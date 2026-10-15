// Noahport MDM - USB enrollment for company laptops (docs/ARCHITECTURE.md section 3, "USB enrollment").
//
// For each freshly reset phone: checks it is safe to enroll, installs the embedded agent, makes it device owner,
// hands it the server address, the enrollment code and its label (the agent then registers itself with the
// server), and appends a row to Documents\Noahport\enrollments.csv.
// The enrollment code comes from the admin site (Enrollment codes) and is asked for once per session.
// adb (Android Platform-Tools) is downloaded from Google on first use unless it sits next to the exe or on PATH.
//
// Double-click to run. Test flags: --once --asset-tag X [--assigned-to Y] [--code C] [--server URL];
// ANDROID_SERIAL picks a phone.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Net;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

namespace Noahport.Enroll;

/// <summary>A phone-level failure: shown, recorded in the CSV, then the tool moves on to the next phone.</summary>
sealed class EnrollException(string message, bool alreadyEnrolled = false) : Exception(message)
{
    public bool AlreadyEnrolled { get; } = alreadyEnrolled;
}

sealed class Phone
{
    public string Serial = "", Manufacturer = "", Model = "", Android = "", AssetTag = "", AssignedTo = "";
}

static class Program
{
    const string PlatformToolsUrl = "https://dl.google.com/android/repository/platform-tools-latest-windows.zip";
    static readonly Regex LabelPattern = new(@"^[A-Za-z0-9 ._/-]{1,40}$");

    static readonly Dictionary<string, string> Meta = typeof(Program).Assembly
        .GetCustomAttributes<AssemblyMetadataAttribute>().ToDictionary(a => a.Key, a => a.Value ?? "");
    static readonly string Package = Meta["AgentPackage"];
    static readonly string Component = Package + "/" + Meta["AgentAdminReceiver"];
    static readonly string DataDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Noahport");
    static readonly string CsvPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "Noahport", "enrollments.csv");

    static bool once;
    static string assetTagArg = "", assignedToArg = "", codeArg = "", serverArg = "";
    static string adb, apk, serial, server, code;

    static int Main(string[] args)
    {
        try
        {
            ParseArgs(args);
            return Run();
        }
        catch (Exception e)
        {
            Say("");
            Say("ERROR: " + e.Message, ConsoleColor.Red);
            return 2;
        }
        finally
        {
            if (!once)
            {
                Say("");
                Say("Press Enter to close this window.");
                Console.ReadLine();
            }
        }
    }

    static void ParseArgs(string[] args)
    {
        for (var i = 0; i < args.Length; i++)
        {
            string Next() => i + 1 < args.Length ? args[++i] : throw new ArgumentException("Missing value for " + args[i]);
            switch (args[i])
            {
                case "--once": once = true; break;
                case "--asset-tag": assetTagArg = Next(); break;
                case "--assigned-to": assignedToArg = Next(); break;
                case "--code": codeArg = Next(); break;
                case "--server": serverArg = Next(); break;
                default: throw new ArgumentException("Unknown option " + args[i]);
            }
        }
    }

    static int Run()
    {
        Console.Title = "Noahport MDM - USB enrollment";
        Say("");
        Say($"Noahport MDM - USB enrollment (agent {Meta["AgentVersion"]})", ConsoleColor.White);
        Say("");

        apk = ExtractAgent();
        adb = FindAdb();
        Adb("start-server");
        server = (serverArg != "" ? serverArg : Meta["ServerUrl"]).TrimEnd('/');
        if (!server.StartsWith("https://")) throw new InvalidOperationException($"Server address must start with https:// (got '{server}').");
        Say("Server: " + server);
        code = AskEnrollmentCode();
        Say("Results are saved to " + CsvPath);

        var enrolled = 0;
        var exitCode = 0;
        while (true)
        {
            Say("");
            Say("Plug in a freshly reset phone with USB debugging on.", ConsoleColor.White);
            serial = WaitForPhone();
            var phone = new Phone
            {
                Serial = Shell("getprop ro.serialno"),
                Manufacturer = Shell("getprop ro.product.manufacturer"),
                Model = Shell("getprop ro.product.model"),
                Android = Shell("getprop ro.build.version.release"),
            };
            if (phone.Serial == "") phone.Serial = serial;
            Say($"Found {phone.Manufacturer} {phone.Model}, Android {phone.Android}, serial {phone.Serial}", ConsoleColor.White);

            try
            {
                EnrollPhone(phone);
                WriteRecord(phone, "ENROLLED", "");
                enrolled++;
                Say($"DONE: {phone.AssetTag} is enrolled. You can unplug it.", ConsoleColor.Green);
            }
            catch (EnrollException e) when (e.AlreadyEnrolled)
            {
                WriteRecord(phone, "ALREADY_ENROLLED", e.Message);
                Say(e.Message, ConsoleColor.Yellow);
            }
            catch (EnrollException e)
            {
                WriteRecord(phone, "FAILED", e.Message);
                Say("NOT ENROLLED: " + e.Message, ConsoleColor.Red);
                exitCode = 1;
            }
            catch (Exception e)
            {
                // Unexpected (adb crashed, phone unplugged mid-way...): record it and carry on with the next phone.
                WriteRecord(phone, "FAILED", "Unexpected error: " + e.Message);
                Say("NOT ENROLLED (unexpected error): " + e.Message, ConsoleColor.Red);
                Say("Check the cable, then try this phone again.", ConsoleColor.Red);
                exitCode = 1;
            }

            if (once) return exitCode;
            assetTagArg = assignedToArg = "";
            Say("");
            Console.Write("Unplug the phone. Press Enter for the next phone, or type Q and Enter to quit: ");
            if ((Console.ReadLine() ?? "q").Trim().StartsWith("q", StringComparison.OrdinalIgnoreCase)) break;
            var waited = false;
            while (Devices().Any(d => d.serial == serial))
            {
                if (!waited) Say("Waiting for the previous phone to be unplugged...", ConsoleColor.Yellow);
                waited = true;
                Thread.Sleep(2000);
            }
        }
        Say($"Enrolled {enrolled} phone(s) this session. Results: {CsvPath}", ConsoleColor.White);
        return exitCode;
    }

    static void EnrollPhone(Phone phone)
    {
        Step("Checking the phone");
        var minSdk = int.Parse(Meta["AgentMinSdk"]);
        if (!int.TryParse(Shell("getprop ro.build.version.sdk"), out var sdk) || sdk < minSdk)
            throw new EnrollException($"Android SDK {sdk} is too old; the agent needs SDK {minSdk}+ (Android 13).");

        var owners = Shell("dpm list-owners");
        if (owners.Contains(Component + ",DeviceOwner"))
            throw new EnrollException("This phone is already enrolled (the agent is device owner). Nothing changed.", alreadyEnrolled: true);
        if (!owners.Contains("no owners"))
            throw new EnrollException($"Another app manages this phone: {owners}. Factory reset it first.");

        var accounts = Shell("dumpsys account");
        var count = Regex.Match(accounts, @"Accounts: (\d+)");
        if (count.Success && int.Parse(count.Groups[1].Value) > 0)
        {
            var types = string.Join(", ", Regex.Matches(accounts, @"Account \{name=[^,]*, type=([^}]+)\}")
                .Cast<Match>().Select(m => m.Groups[1].Value).Distinct());
            throw new EnrollException($"The phone has {count.Groups[1].Value} account(s) ({types}). Factory reset it and skip all sign-ins during setup.");
        }
        var users = Regex.Matches(Shell("pm list users"), @"UserInfo\{").Count;
        if (users > 1) throw new EnrollException($"The phone has {users} users. Factory reset it.");

        phone.AssetTag = ReadLabel("Asset tag (e.g. NP-0042): ", assetTagArg, required: true);
        phone.AssignedTo = ReadLabel("Issued to (name, Enter to skip): ", assignedToArg, required: false);

        Step("Installing the agent");
        var install = Adb("-s", serial, "install", "-r", apk);
        if (!Regex.IsMatch(install, @"(?m)^Success"))
        {
            var hint = install.Contains("INSTALL_FAILED_UPDATE_INCOMPATIBLE")
                ? " A test copy of the agent signed with another key is installed: factory reset the phone." : "";
            throw new EnrollException("Install failed: " + install + hint);
        }

        Step("Making it device owner");
        // Shortly after boot Android is still working out whether the phone has accounts and refuses with "already some
        // accounts" even when there are none; it settles within a minute, so retry while dumpsys still shows 0 accounts.
        for (var attempt = 1; ; attempt++)
        {
            var set = Shell("dpm set-device-owner " + Component);
            if (set.StartsWith("Success")) break;
            if (set.Contains("already some accounts") && Regex.IsMatch(Shell("dumpsys account"), @"Accounts: 0\b") && attempt < 18)
            {
                if (attempt == 1) Say("    The phone is still starting up. Retrying for up to 90 seconds...", ConsoleColor.Yellow);
                Thread.Sleep(5000);
                continue;
            }
            // Keep the one useful line of Android's stack trace.
            var reason = set.Split('\n').Select(l => l.Trim()).FirstOrDefault(l => l.Contains("Exception:")) ?? set;
            throw new EnrollException($"set-device-owner failed: {reason} Factory reset the phone, skip every sign-in during setup, and try again.");
        }
        if (!Shell("dpm list-owners").Contains(Component + ",DeviceOwner"))
            throw new EnrollException("set-device-owner reported success but the agent is not device owner.");

        Step("Registering it with the server");
        // Labels match LabelPattern, the code CodePattern and the server is a checked https URL: no quotes or spaces,
        // so single-quoting is safe for the phone's shell.
        var broadcast = Shell($"am broadcast --include-stopped-packages -n {Package}/.EnrollmentTokenReceiver " +
            $"--es api_base_url '{server}' --es enrollment_token '{code}' " +
            $"--es asset_tag '{phone.AssetTag}' --es assigned_to '{phone.AssignedTo}'");
        if (!Regex.IsMatch(broadcast, @"result=1\b"))
            throw new EnrollException("The agent did not accept the enrollment code: " + broadcast);

        Shell($"am start -n {Package}/.MainActivity");
        Say("    The phone now registers itself and appears on the admin site within a minute (it needs internet).", ConsoleColor.Gray);
    }

    static readonly Regex CodePattern = new(@"^[A-Za-z0-9-]{8,100}$");

    /// <summary>Asks for the admin site's enrollment code until the server accepts it. Does not use it up.</summary>
    static string AskEnrollmentCode()
    {
        var value = codeArg;
        while (true)
        {
            if (value == "")
            {
                Say("");
                Console.Write("Enrollment code (admin site > Enrollment codes): ");
                value = (Console.ReadLine() ?? "").Trim();
            }
            value = Regex.Replace(value, @"\s+", "").ToUpperInvariant();
            if (!CodePattern.IsMatch(value))
            {
                Say("That doesn't look like an enrollment code (it starts with NPE-).", ConsoleColor.Yellow);
            }
            else
            {
                var (status, body) = PostJson(server + "/api/device/v1/enrollment-code/check", $"{{\"enrollment_token\":\"{value}\"}}");
                if (status == 200)
                {
                    var label = JsonField(body, "label");
                    var group = JsonField(body, "group") ?? "no group";
                    Say($"Code accepted: \"{label}\", phones go into {group}, {JsonField(body, "uses_left")} phone(s) left.", ConsoleColor.Green);
                    return value;
                }
                Say(status == 401
                    ? "The server refused this code: it is wrong, used up or expired. Create a new one on the admin site."
                    : $"Could not check the code with {server} (HTTP {status}). Check the laptop's internet connection.", ConsoleColor.Yellow);
            }
            if (once) throw new InvalidOperationException("No valid enrollment code.");
            value = "";
        }
    }

    static (int status, string body) PostJson(string url, string json)
    {
        ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
        var request = (HttpWebRequest)WebRequest.Create(url);
        request.Method = "POST";
        request.ContentType = "application/json";
        request.Accept = "application/json";
        request.Timeout = 20000;
        var bytes = Encoding.UTF8.GetBytes(json);
        try
        {
            using (var stream = request.GetRequestStream()) stream.Write(bytes, 0, bytes.Length);
            using var response = (HttpWebResponse)request.GetResponse();
            using var reader = new StreamReader(response.GetResponseStream());
            return ((int)response.StatusCode, reader.ReadToEnd());
        }
        catch (WebException e) when (e.Response is HttpWebResponse error)
        {
            using var reader = new StreamReader(error.GetResponseStream());
            return ((int)error.StatusCode, reader.ReadToEnd());
        }
        catch (WebException)
        {
            return (0, "");
        }
    }

    /// <summary>Reads one top-level string or number field from a small, flat JSON object.</summary>
    static string JsonField(string json, string name)
    {
        var m = Regex.Match(json, "\"" + Regex.Escape(name) + "\"\\s*:\\s*(\"((?:[^\"\\\\]|\\\\.)*)\"|(-?\\d+))");
        return !m.Success ? null : m.Groups[2].Success && m.Groups[1].Value.StartsWith("\"") ? Regex.Unescape(m.Groups[2].Value) : m.Groups[3].Value;
    }

    // ---- setup ----

    /// <summary>Writes the embedded agent APK to the user's data folder and checks it against the build's hash.</summary>
    static string ExtractAgent()
    {
        Directory.CreateDirectory(DataDir);
        var path = Path.Combine(DataDir, $"agent-{Meta["AgentVersion"]}.apk");
        using (var res = typeof(Program).Assembly.GetManifestResourceStream("agent.apk")
            ?? throw new InvalidOperationException("This build has no agent APK inside. Rebuild it with make-enroll-kit.sh."))
        using (var file = File.Create(path))
            res.CopyTo(file);
        using (var sha = SHA256.Create())
        using (var file = File.OpenRead(path))
        {
            var hash = BitConverter.ToString(sha.ComputeHash(file)).Replace("-", "");
            if (!hash.Equals(Meta["ApkSha256"], StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("The agent inside this tool is damaged. Download NoahportEnroll.exe again.");
        }
        return path;
    }

    static string FindAdb()
    {
        var exeDir = AppDomain.CurrentDomain.BaseDirectory;
        var candidates = new[]
        {
            Path.Combine(exeDir, "platform-tools", "adb.exe"),
            Path.Combine(DataDir, "platform-tools", "adb.exe"),
        }.Concat((Environment.GetEnvironmentVariable("PATH") ?? "").Split(';')
            .Where(p => p.Trim() != "").Select(p => Path.Combine(p.Trim(), "adb.exe")));
        foreach (var c in candidates)
        {
            try { if (File.Exists(c)) return c; } catch (ArgumentException) { } // malformed PATH entry
        }

        Say("adb not found. Downloading Android Platform-Tools from Google (about 7 MB)...");
        var zip = Path.Combine(DataDir, "platform-tools.zip");
        try
        {
            ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
            using (var web = new WebClient()) web.DownloadFile(PlatformToolsUrl, zip);
        }
        catch (Exception e)
        {
            throw new InvalidOperationException($"Could not download Platform-Tools ({e.Message}). Download \"SDK Platform-Tools for Windows\" " +
                "from https://developer.android.com/tools/releases/platform-tools and unzip it next to NoahportEnroll.exe, " +
                "so that platform-tools\\adb.exe sits beside it.");
        }
        var target = Path.Combine(DataDir, "platform-tools");
        if (Directory.Exists(target)) Directory.Delete(target, recursive: true);
        ZipFile.ExtractToDirectory(zip, DataDir);
        File.Delete(zip);
        return Path.Combine(target, "adb.exe");
    }

    // ---- adb ----

    static string Adb(params string[] args)
    {
        var psi = new ProcessStartInfo(adb, string.Join(" ", args.Select(Quote)))
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
            StandardOutputEncoding = Encoding.UTF8,
        };
        using var p = Process.Start(psi);
        var stdout = p.StandardOutput.ReadToEndAsync();
        var stderr = p.StandardError.ReadToEnd();
        p.WaitForExit();
        return (stdout.Result + stderr).Replace("\r", "").Trim();
    }

    static string Quote(string a) => a.Length > 0 && !Regex.IsMatch(a, @"[\s""]") ? a : "\"" + a.Replace("\"", "\\\"") + "\"";

    /// <summary>Runs a command in the phone's shell (one string: adb passes it to the phone's sh as is).</summary>
    static string Shell(string command) => Adb("-s", serial, "shell", command);

    static IEnumerable<(string serial, string state)> Devices()
    {
        var pick = Environment.GetEnvironmentVariable("ANDROID_SERIAL"); // adb's convention for choosing one phone
        foreach (var line in Adb("devices").Split('\n').Skip(1))
        {
            var m = Regex.Match(line, @"^(\S+)\s+(\S+)");
            if (m.Success && (string.IsNullOrEmpty(pick) || m.Groups[1].Value == pick))
                yield return (m.Groups[1].Value, m.Groups[2].Value);
        }
    }

    /// <summary>Waits until exactly one authorized phone is connected and returns its adb serial.</summary>
    static string WaitForPhone()
    {
        var deadline = DateTime.UtcNow.AddSeconds(60);
        var last = "";
        while (true)
        {
            var devices = Devices().ToList();
            string message;
            if (devices.Count == 0) message = "Waiting for a phone... (USB cable plugged in? USB debugging on?)";
            else if (devices.Count > 1) message = $"{devices.Count} phones are connected. Unplug all but one.";
            else if (devices[0].state == "device") return devices[0].serial;
            else if (devices[0].state == "unauthorized") message = "Unlock the phone and tap Allow on \"Allow USB debugging?\" (tick \"Always allow\").";
            else message = $"Phone state is '{devices[0].state}'. Unplug it and plug it back in.";

            if (message != last) Say(message, ConsoleColor.Yellow);
            last = message;
            if (once && DateTime.UtcNow > deadline) throw new InvalidOperationException("No usable phone connected within 60 seconds.");
            Thread.Sleep(2000);
        }
    }

    // ---- helpers ----

    static string ReadLabel(string prompt, string value, bool required)
    {
        while (true)
        {
            if (value == "" && once && !required) return "";
            if (value == "")
            {
                Console.Write(prompt);
                value = (Console.ReadLine() ?? "").Trim();
            }
            if (value == "" && !required) return "";
            if (LabelPattern.IsMatch(value)) return value;
            Say("Use letters, digits, spaces and . _ / - only (max 40 characters).", ConsoleColor.Yellow);
            if (once) throw new EnrollException($"Invalid label '{value}'.");
            value = "";
        }
    }


    static void WriteRecord(Phone phone, string result, string detail)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(CsvPath));
        var header = !File.Exists(CsvPath);
        string Csv(string v) => "\"" + (v ?? "").Replace("\"", "\"\"").Replace("\n", " ") + "\"";
        var row = new[]
        {
            DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"), result, phone.Serial, phone.Manufacturer, phone.Model, phone.Android,
            phone.AssetTag, phone.AssignedTo, Meta["AgentVersion"], Environment.MachineName, Environment.UserName, detail,
        };
        using var w = new StreamWriter(CsvPath, append: true, new UTF8Encoding(encoderShouldEmitUTF8Identifier: true));
        if (header) w.WriteLine("TimeUtc,Result,Serial,Manufacturer,Model,Android,AssetTag,AssignedTo,AgentVersion,Laptop,Operator,Detail");
        w.WriteLine(string.Join(",", row.Select(Csv)));
    }

    static void Say(string text, ConsoleColor color = ConsoleColor.Gray)
    {
        Console.ForegroundColor = color;
        Console.WriteLine(text);
        Console.ResetColor();
    }

    static void Step(string text) => Say("  - " + text, ConsoleColor.Cyan);
}

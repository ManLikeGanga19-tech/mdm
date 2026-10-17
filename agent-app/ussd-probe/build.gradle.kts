plugins {
    id("com.android.application")
}

// B0 test tool (docs/STATUS.md, bundle tracking): a small app, separate from the agent, that sends the carriers'
// data-balance USSD codes with TelephonyManager.sendUssdRequest and shows the reply. Installs next to the agent on
// any test phone; no device owner needed (the user allows "Phone" when it opens). Debug-signed, never for staff.
android {
    namespace = "com.noahport.mdm.ussdprobe"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.noahport.mdm.ussdprobe"
        minSdk = 30 // WindowInsets.Type; the fleet and test phones run Android 13-15
        targetSdk = 36
        versionCode = 1
        versionName = "0.1.0"
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

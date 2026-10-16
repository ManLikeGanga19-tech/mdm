{{--
    "Not allowed" page (HTTP 403), mostly reached when a role's permissions (Roles & permissions) don't include a page
    or action. Standalone: error pages render outside the admin panel, so styles are inline and follow the system's
    light/dark setting.
--}}
@php
    $user = auth()->user();
    $home = rescue(fn () => \Filament\Facades\Filament::getPanel('admin')->getUrl(), url('/admin'), false);
    $reason = $exception?->getMessage();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Not allowed · Noahport MDM</title>
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}">
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #1d4ed8; --accent-text: #ffffff; --warn-bg: #fef3c7; --warn: #b45309; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --card: #111827; --text: #e5e7eb; --muted: #94a3b8; --line: #1f2937; --accent: #3b82f6; --accent-text: #ffffff; --warn-bg: #422006; --warn: #fbbf24; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text);
               font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 460px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 32px 28px; text-align: center;
                box-shadow: 0 10px 30px rgba(15, 23, 42, .06); }
        .badge { width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; background: var(--warn-bg); color: var(--warn); }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { margin: 0 0 12px; color: var(--muted); line-height: 1.5; font-size: 15px; }
        .role { display: inline-block; margin: 4px 0 16px; padding: 4px 10px; border-radius: 999px; border: 1px solid var(--line); font-size: 13px; color: var(--text); }
        .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
        a.btn, button.btn { appearance: none; border: 1px solid var(--line); background: transparent; color: var(--text); padding: 10px 16px; border-radius: 10px;
                            font: inherit; font-size: 14px; text-decoration: none; cursor: pointer; }
        a.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
        .brand { margin-top: 24px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<main class="card">
    <div class="badge" aria-hidden="true">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/>
        </svg>
    </div>
    <h1>{{ $user?->isDisabled() ? 'This account is disabled' : 'You don\'t have access to this' }}</h1>
    @if ($user?->isDisabled())
        <p>A super admin has switched this account off. Ask them if you think this is a mistake.</p>
    @elseif ($user)
        <p>Your role doesn't include permission for this page or action.</p>
        <span class="role">Signed in as {{ $user->name }} · {{ $user->role?->name ?? 'no role' }}</span>
        @if ($reason && $reason !== 'Forbidden' && $reason !== 'This action is unauthorized.')
            <p>{{ $reason }}</p>
        @endif
        <p>If you need it for your work, ask a super admin to add it to your role on <strong>Roles &amp; permissions</strong>.</p>
    @else
        <p>Sign in with an account that has access to this page.</p>
    @endif
    <div class="actions">
        <a class="btn primary" href="{{ $home }}">{{ $user ? 'Go to my start page' : 'Sign in' }}</a>
        <button class="btn" type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ $home }}')">Go back</button>
    </div>
    <div class="brand">Noahport MDM</div>
</main>
</body>
</html>

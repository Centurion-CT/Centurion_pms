<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>You're on the Keverna API waitlist</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color: #14171A; background: #F7F3EA; margin: 0; padding: 32px 16px; }
        .wrap { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 32px; border: 1px solid rgba(15,18,21,0.06); }
        h1 { font-family: Georgia, 'Times New Roman', serif; font-weight: 600; font-size: 26px; margin: 0 0 16px; letter-spacing: -0.02em; }
        p { font-size: 15px; line-height: 1.65; margin: 0 0 14px; color: #3D434A; }
        .accent { color: #8AAE3A; }
        code { font-family: ui-monospace, 'JetBrains Mono', monospace; font-size: 13px; background: #F0EAE0; padding: 2px 6px; border-radius: 4px; }
        .foot { margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(15,18,21,0.08); font-size: 12.5px; color: #6E7278; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Hi {{ $request->name }},</h1>

        <p>
            You're on the Keverna public API waitlist. We're a few weeks out from an
            open beta - once it's ready we'll email you a client ID and a link to
            the reference docs at <code>api.keverna.com</code>.
        </p>

        <p>
            <strong>What we grabbed from your submission:</strong>
        </p>
        <ul style="font-size: 14px; color: #3D434A; line-height: 1.65; padding-left: 20px; margin: 0 0 14px;">
            @if ($request->company)<li>Company: {{ $request->company }}</li>@endif
            @if ($request->github_url)<li>GitHub: <a href="{{ $request->github_url }}" style="color: #8AAE3A;">{{ $request->github_url }}</a></li>@endif
            @if ($request->use_case)<li>Use case: {{ $request->use_case }}</li>@endif
            @if ($request->integrations)<li>Wants to integrate with: {{ $request->integrations }}</li>@endif
        </ul>

        <p>
            If any of that changed or you want to shape what ships first, just
            reply to this email - it comes straight to the team.
        </p>

        <p class="foot">
            Keverna · Booking infrastructure for African operators<br>
            <a href="https://keverna.com" style="color: #6E7278;">keverna.com</a>
        </p>
    </div>
</body>
</html>

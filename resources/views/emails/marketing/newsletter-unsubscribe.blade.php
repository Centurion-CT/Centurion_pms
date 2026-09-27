<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribed - Keverna</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color: #14171A; background: #F7F3EA; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { max-width: 520px; padding: 40px 32px; background: #fff; border-radius: 16px; border: 1px solid rgba(15,18,21,0.06); text-align: center; }
        h1 { font-family: Georgia, 'Times New Roman', serif; font-weight: 600; font-size: 28px; margin: 0 0 16px; letter-spacing: -0.02em; }
        p { font-size: 15px; line-height: 1.65; margin: 0 0 14px; color: #3D434A; }
        a.btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #14171A; color: #F5EFE1; border-radius: 999px; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        @if ($ok)
            <h1>You're unsubscribed.</h1>
            <p>
                We won't email <strong>{{ $email }}</strong> again. If it was a
                mistake, you can resubscribe from the landing page any time.
            </p>
        @else
            <h1>Link expired.</h1>
            <p>
                This unsubscribe link is no longer valid. If you keep getting
                emails, reply to the last one and we'll take care of it.
            </p>
        @endif
        <a href="/" class="btn">← Back to keverna.com</a>
    </div>
</body>
</html>

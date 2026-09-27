<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Welcome to Keverna</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color: #14171A; background: #F7F3EA; margin: 0; padding: 32px 16px; }
        .wrap { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 32px; border: 1px solid rgba(15,18,21,0.06); }
        h1 { font-family: Georgia, 'Times New Roman', serif; font-weight: 600; font-size: 26px; margin: 0 0 16px; letter-spacing: -0.02em; }
        p  { font-size: 15px; line-height: 1.65; margin: 0 0 14px; color: #3D434A; }
        .accent { color: #8AAE3A; }
        .foot { margin-top: 28px; padding-top: 20px; border-top: 1px solid rgba(15,18,21,0.08); font-size: 12px; color: #6E7278; }
        .foot a { color: #6E7278; text-decoration: underline; text-underline-offset: 3px; }
        .brand { font-family: Georgia, 'Times New Roman', serif; font-weight: 700; letter-spacing: -0.02em; color: #14171A; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>You are on the list.</h1>

        <p>Thanks for subscribing.</p>

        <p>
            Once a month you'll get an email from us with what shipped, what we
            learned from operators on the ground, and the odd Nigerian
            hospitality data point. <span class="accent">No pitch, no product tour.</span>
            You can unsubscribe with one click from the footer of every message.
        </p>

        <p>
            If there is anything specific you want us to write about, just reply
            to this email and it lands with the team.
        </p>

        <p class="foot">
            <span class="brand">Keverna</span> - Booking infrastructure for African operators<br>
            <a href="https://keverna.com">keverna.com</a>
            &nbsp;·&nbsp;
            <a href="{{ $subscription->unsubscribeUrl() }}">Unsubscribe</a>
        </p>
    </div>
</body>
</html>

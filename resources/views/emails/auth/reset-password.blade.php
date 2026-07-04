<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#1f2937; margin:0; padding:0; }
        .header { background:#0f172a; color:#fff; padding:20px 24px; }
        .header-title { font-size:18px; font-weight:bold; }
        .header-sub { font-size:12px; color:#94a3b8; margin-top:4px; }
        .container { padding:24px; max-width:620px; margin:0 auto; }
        .card { border:1px solid #e5e7eb; padding:16px; border-radius:6px; margin-bottom:16px; }
        .card-title { font-size:13px; font-weight:bold; margin-bottom:10px; color:#374151; text-transform:uppercase; letter-spacing:.05em; }
        .btn { display:inline-block; background:#0f172a; color:#ffffff; text-decoration:none; font-weight:600; padding:12px 24px; border-radius:6px; font-size:15px; }
        .hr { border-top:1px solid #e5e7eb; margin:20px 0; }
        .muted { color:#6b7280; font-size:12px; }
    </style>
    <title>Reset Password</title>
</head>
<body>
    <div class="header">
        <div class="header-title">HRIS Password Reset</div>
        <div class="header-sub">Human Resource Information System</div>
    </div>
    <div class="container">

        <p style="font-size:14px;">Dear <strong>{{ isset($notifiable->first_name) && $notifiable->first_name ? $notifiable->first_name : (isset($notifiable->name) && $notifiable->name ? $notifiable->name : 'Employee') }}</strong>,</p>

        <div class="card">
            <div class="card-title">Password Reset Request</div>
            <p style="font-size:14px;line-height:1.6;margin:0 0 12px;">You are receiving this email because we received a password reset request for your account.</p>
            <p style="font-size:14px;line-height:1.6;margin:0 0 16px;">Click the button below to reset your password:</p>
            <p style="text-align:center;margin:0 0 16px;">
                <a href="{{ $url }}" class="btn" style="color:#ffffff;">Reset Password</a>
            </p>
            <p style="font-size:14px;line-height:1.6;margin:0;">This password reset link will expire in <strong>{{ $expire }} minutes</strong>.</p>
        </div>

        <div class="card" style="background:#fefce8;border-color:#fde047;">
            <p style="font-size:13px;margin:0;color:#854d0e;"><strong>Didn't request this?</strong> If you did not request a password reset, no further action is required. Your account is safe.</p>
        </div>

        <div class="hr"></div>
        <p style="font-size:14px;">If you have questions, please contact the HR office.</p>
        <p style="font-size:14px;">Regards,<br/><strong>HRIS Team</strong></p>
        <p class="muted">This is an automated notification. Please do not reply to this email.</p>

    </div>
</body>
</html>

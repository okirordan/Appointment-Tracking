<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;background:#f4f7f5;color:#17221c;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f5;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #dce4df;border-radius:14px;overflow:hidden;">
            <tr><td style="padding:20px 28px;background:#075c37;color:#ffffff;">
                <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;opacity:.82;">{{ config('ats.ministry_short_name') }}</div>
                <div style="margin-top:5px;font-size:18px;font-weight:700;">Assignment Tracking System</div>
            </td></tr>
            <tr><td style="padding:30px 28px;">
                <h1 style="margin:0;font-size:21px;line-height:1.35;color:#17221c;">{{ $heading }}</h1>
                @if($detail)<p style="margin:14px 0 0;color:#536159;font-size:14px;line-height:1.65;white-space:pre-line;">{{ $detail }}</p>@endif
                <p style="margin:24px 0 0;"><a href="{{ $actionUrl }}" style="display:inline-block;padding:11px 18px;border-radius:9px;background:#155dfc;color:#ffffff;text-decoration:none;font-size:13px;font-weight:700;">{{ $actionLabel }}</a></p>
                <p style="margin:24px 0 0;color:#7a867f;font-size:11px;line-height:1.5;">This is an automated institutional notification. Sign in to ATS to view the current official record and its Correspondence history.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>

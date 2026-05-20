<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FleetQ Sentry Fix — PR opened</title>
</head>
<body style="margin:0; padding:24px; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color:#1f2937; background:#f9fafb;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; border-radius:8px; padding:24px;">
        <h1 style="margin:0 0 16px; font-size:18px; color:#111827;">
            Sentry Fix — PR opened
        </h1>

        <p style="margin:0 0 12px; font-size:14px; line-height:1.5;">
            <strong>Issue:</strong> {{ $title }}
        </p>

        @if (! empty($summary))
            <p style="margin:0 0 16px; font-size:14px; line-height:1.5;">
                <strong>Summary:</strong> {{ $summary }}
            </p>
        @endif

        <p style="margin:0 0 16px; font-size:14px; line-height:1.5;">
            <strong>Target repository:</strong>
            <code style="background:#f3f4f6; padding:2px 6px; border-radius:4px;">{{ $targetRepo }}</code>
        </p>

        <p style="margin:24px 0;">
            <a href="{{ $prUrl }}"
               style="display:inline-block; padding:10px 18px; background:#2563eb; color:#ffffff; text-decoration:none; border-radius:6px; font-size:14px; font-weight:600;">
                Review PR
            </a>
        </p>

        @if (! empty($sentryPermalink))
            <p style="margin:0 0 8px; font-size:13px; color:#6b7280;">
                <a href="{{ $sentryPermalink }}" style="color:#6b7280;">View original Sentry issue</a>
            </p>
        @endif

        <hr style="border:none; border-top:1px solid #e5e7eb; margin:24px 0;">

        <p style="margin:0; font-size:12px; color:#9ca3af;">
            Sent by the FleetQ Sentry Watchdog. A human still needs to review and merge the PR.
        </p>
    </div>
</body>
</html>

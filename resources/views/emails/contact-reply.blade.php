<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #f8fafc; margin: 0; padding: 0; }
        .wrapper { max-width: 520px; margin: 0 auto; padding: 32px 20px; }
        .card { background: #ffffff; border-radius: 16px; padding: 32px; border: 1px solid #eef1f4; }
        .badge {
            display: inline-block; padding: 6px 14px; border-radius: 999px;
            font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
            color: #fff; background: #2D4A22; margin-bottom: 20px;
        }
        h1 { font-size: 20px; color: #1e292b; margin: 0 0 8px; }
        p  { color: #4b5563; font-size: 14px; line-height: 1.6; }
        .reply-box {
            background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px;
            padding: 16px; margin: 20px 0; font-size: 14px; color: #166534;
        }
        .original-box {
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 16px; margin-top: 12px; font-size: 13px; color: #6b7280;
        }
        .original-label {
            color: #9ca3af; font-weight: 700; text-transform: uppercase;
            font-size: 11px; letter-spacing: .05em; margin-bottom: 8px;
        }
        .footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">

            <span class="badge">✉️ Message Received</span>

            <h1>Hi {{ $name }},</h1>

            <p>Thank you for reaching out to us. Here is our reply to your message:</p>

            <div class="reply-box">
                {!! nl2br(e($replyMessage)) !!}
            </div>

            <p class="original-label">Your Original Message</p>
            <div class="original-box">
                {!! nl2br(e($originalMsg)) !!}
            </div>

        </div>

        <p class="footer">
            Khmer-Fresh Organic Store &middot; Phnom Penh, Cambodia<br>
            This is an automated message — please do not reply directly to this email.
        </p>
    </div>
</body>
</html>. 
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; line-height: 1.6; color: #333; }
        .container { padding: 20px; border: 1px solid #eee; border-radius: 10px; max-width: 500px; margin: auto; }
        .code-box { 
            background-color: #f4f4f4; 
            padding: 15px; 
            text-align: center; 
            font-size: 24px; 
            font-weight: bold; 
            letter-spacing: 5px; 
            color: #2c3e50;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer { font-size: 12px; color: #888; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container" style="direction: ltr; text-align: left;">
        <h2>Hello,</h2>
        <p>You have requested to reset the password for your **AI Content Detector** account.</p>
        <p>Please use the verification code below to complete the process:</p>
        
        <div class="code-box" style="font-size: 24px; font-weight: bold; letter-spacing: 5px; padding: 20px; background: #f4f4f4; text-align: center; border-radius: 8px;">
            {{ $code }}
        </div>

        <p>This code is valid for <strong>15 minutes</strong> only.</p>
        <p>If you did not request this code, you can safely ignore this email.</p>
        
        <div class="footer" style="margin-top: 30px; font-size: 12px; color: #888;">
            This is an automated email, please do not reply.
        </div>
    </div>
</body>
</html>
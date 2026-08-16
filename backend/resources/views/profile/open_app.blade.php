<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فتح الملف الشخصي - ركن</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background-color: #f5f7fa;
            text-align: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            max-width: 400px;
            width: 100%;
        }
        .logo {
            width: 100px;
            margin-bottom: 20px;
        }
        h1 {
            color: #2563eb;
            font-size: 24px;
            margin-bottom: 10px;
        }
        p {
            color: #64748b;
            margin-bottom: 30px;
        }
        .btn {
            display: inline-block;
            background-color: #2563eb;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.3s;
        }
        .btn:hover {
            background-color: #1d4ed8;
        }
        .footer {
            margin-top: 30px;
            font-size: 14px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <img src="{{ asset('images/logo.png') }}" alt="شعار ركن" class="logo">
        <h1>جاري فتح الملف الشخصي...</h1>
        <p>سيتم توجيهك إلى تطبيق ركن لعرض ملف <strong>{{ $user->name }}</strong></p>
        
        <a href="rokn://profile/{{ $user->id }}" class="btn" id="openAppBtn">فتح في التطبيق</a>
        
        <div class="footer">
            إذا لم يتم فتح التطبيق تلقائياً، تأكد من تثبيته على جهازك.
        </div>
    </div>

    <script>
        // Attempt to open the app automatically
        window.onload = function() {
            var appUri = "rokn://profile/{{ $user->id }}";
            window.location.href = appUri;
            
            // Fallback logic could be added here to redirect to App Store after a delay
            /*
            setTimeout(function() {
                if (confirm("يبدو أن التطبيق غير مثبت. هل تريد الانتقال لمتجر التطبيقات؟")) {
                    window.location.href = "https://play.google.com/store/apps/details?id=com.rokn";
                }
            }, 2500);
            */
        };
    </script>
</body>
</html>

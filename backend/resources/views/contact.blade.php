<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تواصل معنا - {{ config('app.name', 'Rokn') }}</title>
    <style>
        :root { color-scheme: dark; font-family: Arial, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #080b12; color: #f8fafc; padding: 24px; }
        main { width: min(100%, 560px); background: #111623; border: 1px solid #252c3b; border-radius: 20px; padding: 28px; }
        h1 { margin: 0 0 12px; font-size: 28px; }
        p { color: #b8c0cf; line-height: 1.8; margin: 0 0 18px; }
        a { color: #70a7ff; }
        .action { display: inline-flex; min-height: 48px; align-items: center; justify-content: center; padding: 0 22px; border-radius: 12px; background: #2f7df6; color: white; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <h1>تواصل معنا</h1>
    <p>لو واجهتك مشكلة في الحساب أو الدفع أو مشاهدة المحتوى، تواصل معنا وسنراجعها معك.</p>

    @if (filled(config('mail.from.address')))
        <a class="action" href="mailto:{{ config('mail.from.address') }}">راسل فريق ركن</a>
    @else
        <p>ستجد وسيلة الدعم الحالية داخل إعدادات التطبيق.</p>
    @endif

    <p style="margin-top: 22px">
        يمكنك مراجعة <a href="{{ route('privacy') }}">سياسة الخصوصية</a>
        و<a href="{{ route('terms') }}">شروط الاستخدام</a> في أي وقت.
    </p>
</main>
</body>
</html>

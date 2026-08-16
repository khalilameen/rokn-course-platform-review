<html>
<body>
<h2>تم وصول رساله جديده</h2>
<h3>{{ 'من :'. $contact->name  }}</h3>
<h4>{{ 'رقم الهاتف :'. $contact->phone }}</h4>
<p>{{ $contact->message }}</p>
<a href="{{ route('admin.contacts.show', $contact->id) }}">عرض الرسالة</a>
</body>
</html>

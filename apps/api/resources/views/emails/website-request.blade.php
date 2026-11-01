<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'ar' ? 'rtl' : 'ltr' }}">
<body style="font-family:Arial,sans-serif;color:#12345a;padding:32px">
<h1>Ben Sbih Rental Car</h1><h2>{{ $copy[0] }}</h2>
<p>{{ $copy[1] }}: <strong dir="ltr">{{ $reference }}</strong></p>
<p>{{ $copy[2] }}</p><p><a href="{{ $trackingUrl }}">{{ $copy[3] }}</a></p><p>{{ $copy[4] }}</p>
</body></html>

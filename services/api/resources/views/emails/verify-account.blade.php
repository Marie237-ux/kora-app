<!doctype html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="background:#fff;color:#171914;font-family:Arial,sans-serif;line-height:1.6;padding:24px">
<main style="max-width:560px;margin:auto">
<h1>KORA</h1><p>{{ $greeting }}</p><p>{{ $intro }}</p>
<p><a href="{{ $url }}" style="color:#425a16">{{ $action }}</a></p>
<p>{{ $expiry }}</p><p>{{ $signature }}</p>
</main></body></html>

<!doctype html>
<html lang="{{ $fr ? 'fr' : 'en' }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
    h1 { font-size: 14px; margin: 0 0 4px; }
    .meta { margin: 0 0 8px; color: #444; }
    .demo { color: #a00; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #bbb; padding: 3px 4px; text-align: left; vertical-align: top; }
    th { background: #eee; }
    td.n { text-align: right; white-space: nowrap; }
    .notes { margin-top: 8px; color: #333; }
</style>
</head>
<body>
<h1>{{ $title }}</h1>
<p class="meta">
    {{ $book }} — {{ $fr ? 'Période' : 'Period' }} {{ $period }} — XAF
    @if ($demo) <span class="demo">{{ $fr ? 'LIVRE DE DÉMONSTRATION' : 'DEMONSTRATION BOOK' }}</span> @endif
</p>
<table>
    <thead><tr>@foreach ($header as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($rows as $row)
        <tr>@foreach ($row as $cell)<td class="{{ preg_match('/^-?[\d ]+$/', $cell) && $cell !== '' ? 'n' : '' }}">{{ $cell }}</td>@endforeach</tr>
    @endforeach
    </tbody>
</table>
<div class="notes">
    @foreach ($notes as $note)<p>{{ $note }}</p>@endforeach
    <p>{{ $fr ? 'Édité le' : 'Generated on' }} {{ $generated }} (Africa/Douala). {{ $fr ? 'Aucune certification comptable ou fiscale.' : 'No accounting or tax certification.' }}</p>
</div>
</body>
</html>

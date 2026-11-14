@php
    $money = fn (string $amount) => number_format((int) $amount, 0, ',', "\u{202F}").' FCFA';
    $text = fn (?array $value) => $value[$locale] ?? ($value['fr'] ?? '');
    $seller = $doc['seller'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #141414; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .banner { border: 1px solid #E2543A; color: #B03A22; padding: 6px; margin-bottom: 10px; font-weight: bold; }
    .muted { color: #555; }
    .cols { width: 100%; margin: 12px 0; }
    .cols td { vertical-align: top; width: 50%; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th, table.lines td { border-bottom: 1px solid #ddd; padding: 4px; text-align: left; }
    .right { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-top: 10px; }
    .totals td { padding: 3px; }
    .strong { font-weight: bold; }
    footer { margin-top: 18px; font-size: 8px; color: #555; }
</style>
</head>
<body>
@if ($draft)
    <div class="banner">{{ __('documents.draft') }}</div>
@endif
@if ($doc['demo'])
    <div class="banner">{{ __('documents.demo') }}</div>
@endif

<table class="cols">
    <tr>
        <td>
            @if ($logo)
                <img src="{{ $logo }}" alt="KORA" style="height: 60px">
            @endif
        </td>
        <td class="right">
            <h1>{{ __('documents.'.$doc['kind']) }}</h1>
            <div>{{ __('documents.number') }} {{ $doc['number'] ?? '—' }}</div>
            <div class="muted">{{ __('documents.issued_at') }} : {{ \Carbon\CarbonImmutable::parse($doc['issued_at'])->setTimezone('Africa/Douala')->format('d/m/Y') }}</div>
            <div class="muted">{{ __('documents.order') }} {{ $doc['order']['number'] }}</div>
        </td>
    </tr>
</table>

<table class="cols">
    <tr>
        <td>
            <div class="strong">{{ __('documents.seller') }}</div>
            <div>{{ $seller['legal_name'] ?? '—' }} ({{ $seller['trade_name'] }})</div>
            <div>{{ $seller['address'] ?? '—' }}, {{ $seller['city'] ?? '—' }}</div>
            <div>{{ __('documents.niu') }} : {{ $seller['niu'] ?? '—' }} · {{ __('documents.rccm') }} : {{ $seller['rccm'] ?? '—' }}</div>
            @if ($seller['phone'] || $seller['email'])
                <div class="muted">{{ $seller['phone'] }} {{ $seller['email'] }}</div>
            @endif
        </td>
        <td>
            <div class="strong">{{ __('documents.buyer') }}</div>
            <div>{{ $doc['buyer']['name'] }}</div>
            @if (! empty($doc['buyer']['billing_address']))
                <div>{{ $doc['buyer']['billing_address'] }}</div>
                <div>{{ __('documents.niu') }} : {{ $doc['buyer']['niu'] ?? '—' }} · {{ __('documents.rccm') }} : {{ $doc['buyer']['rccm'] ?? '—' }}</div>
            @endif
            <div class="muted">{{ $doc['buyer']['district'] }} — {{ $doc['buyer']['landmark'] }}</div>
        </td>
    </tr>
</table>

@if ($doc['kind'] === 'credit_note')
    <p class="strong">{{ __('documents.credit_for', ['number' => $doc['invoice_number']]) }}</p>
    <p>{{ __('documents.reason') }} : {{ $doc['reason'] }}</p>
    <p class="muted">{{ __('documents.refund') }} : {{ $doc['refund']['reference'] }}</p>
@else
    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('documents.item') }}</th>
                <th>{{ __('documents.shop') }}</th>
                <th class="right">{{ __('documents.quantity') }}</th>
                <th class="right">{{ __('documents.unit_price') }}</th>
                <th class="right">{{ __('documents.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['lines'] as $line)
                <tr>
                    <td>
                        {{ $text($line['name']) }} <span class="muted">({{ $line['sku'] }})</span>
                        <div class="muted">{{ __('documents.warranty') }} : {{ $text($line['warranty']) }}</div>
                        @if (! empty($line['loyalty_discount']))
                            <div class="muted">{{ __('documents.loyalty_discount') }} : − {{ $money($line['loyalty_discount']) }}</div>
                        @endif
                    </td>
                    <td>{{ $line['shop'] }}</td>
                    <td class="right">{{ $line['quantity'] }}</td>
                    <td class="right">{{ $money($line['unit_price']) }}</td>
                    <td class="right">{{ $money($line['line_total']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4">{{ __('documents.delivery') }}</td>
                <td class="right">{{ $money($doc['delivery']['gross_total'] ?? $doc['delivery']['total']) }}</td>
            </tr>
            @if (! empty($doc['delivery']['promotion']))
                <tr>
                    <td colspan="4">{{ __('documents.delivery_offer') }} : {{ $text($doc['delivery']['promotion']['name']) }}</td>
                    <td class="right">− {{ $money($doc['delivery']['promotion']['discount']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
@endif

<table class="totals">
    @if ($doc['totals']['vat'] !== null)
        <tr><td>{{ __('documents.excluding_vat') }}</td><td class="right">{{ $money($doc['totals']['excluding_vat']) }}</td></tr>
        <tr>
            <td>{{ __('documents.vat', ['rate' => number_format($doc['totals']['vat_rate_basis_points'] / 100, 2, ',', ' ')]) }}</td>
            <td class="right">{{ $money($doc['totals']['vat']) }}</td>
        </tr>
    @endif
    <tr class="strong"><td>{{ __('documents.total') }}</td><td class="right">{{ $money($doc['totals']['total']) }}</td></tr>
</table>

@if ($doc['totals']['vat'] === null)
    <p class="muted">{{ __('documents.no_vat') }}</p>
@endif

@if (! empty($doc['payments']))
    <p class="strong">{{ __('documents.payments') }}</p>
    @foreach ($doc['payments'] as $payment)
        <div class="muted">{{ ucfirst($payment['provider']) }} · {{ $payment['reference'] }} · {{ $money($payment['amount']) }}</div>
    @endforeach
@endif

<footer>
    @if ($text($seller['mentions'] ?? null))
        <p>{{ $text($seller['mentions']) }}</p>
    @endif
    <p>{{ __('documents.not_fiscal') }}</p>
</footer>
</body>
</html>

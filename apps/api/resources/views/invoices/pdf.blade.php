<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
        .totals { margin-top: 16px; width: 280px; margin-left: auto; }
        .totals td { border: none; padding: 4px 8px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Rechnung {{ $invoice->number }}</h1>
    <p class="muted">Ausgestellt am {{ $invoice->issued_at->format('d.m.Y') }} · Fällig {{ optional($invoice->due_at)->format('d.m.Y') }}</p>

    <table>
        <tr>
            <td>
                <strong>{{ $profile['company'] ?? $invoice->organization->name }}</strong><br>
                {!! nl2br(e($profile['address'] ?? '')) !!}<br>
                @if(!empty($profile['vat_id'])) USt-IdNr.: {{ $profile['vat_id'] }} @endif
            </td>
            <td>
                <strong>Kunde</strong><br>
                {{ $invoice->client->company ?: $invoice->client->name }}<br>
                {!! nl2br(e($invoice->client->address ?? '')) !!}<br>
                @if($invoice->client->vat_id) USt-IdNr.: {{ $invoice->client->vat_id }} @endif
            </td>
        </tr>
    </table>

    <p>Leistungszeitraum: {{ $invoice->period_start->format('d.m.Y') }} – {{ $invoice->period_end->format('d.m.Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>Position</th>
                <th>Menge</th>
                <th class="right">Einzelpreis</th>
                <th class="right">Summe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td class="right">{{ number_format($item->unit_price_cents / 100, 2, ',', '.') }} €</td>
                    <td class="right">{{ number_format($item->total_cents / 100, 2, ',', '.') }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Netto</td><td class="right">{{ number_format($invoice->subtotal_cents / 100, 2, ',', '.') }} €</td></tr>
        @if($invoice->small_business)
            <tr><td colspan="2">Gemäß §19 UStG wird keine Umsatzsteuer berechnet.</td></tr>
        @else
            <tr><td>USt {{ number_format($invoice->tax_rate, 0) }}%</td><td class="right">{{ number_format($invoice->tax_cents / 100, 2, ',', '.') }} €</td></tr>
        @endif
        <tr><td><strong>Gesamt</strong></td><td class="right"><strong>{{ number_format($invoice->total_cents / 100, 2, ',', '.') }} €</strong></td></tr>
    </table>
</body>
</html>

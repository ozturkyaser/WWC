<!DOCTYPE html>
<html lang="de">
<head><meta charset="utf-8"><title>Rechnung {{ $invoice->number }}</title></head>
<body style="font-family: Arial, sans-serif; color: #1a1a1a; line-height: 1.5;">
  <p>Guten Tag{{ $invoice->client?->name ? ' '.$invoice->client->name : '' }},</p>
  <p>
    anbei erhalten Sie die Rechnung <strong>{{ $invoice->number }}</strong>
    für das Projekt <strong>{{ $invoice->project?->name ?? 'Wartung' }}</strong>
    (Zeitraum {{ $invoice->period_start?->format('d.m.Y') }} – {{ $invoice->period_end?->format('d.m.Y') }}).
  </p>
  <p>
    Betrag: <strong>{{ number_format($invoice->total_cents / 100, 2, ',', '.') }} {{ $invoice->currency }}</strong><br>
    Fällig bis: {{ $invoice->due_at?->format('d.m.Y') ?? '–' }}
  </p>
  <p>Das PDF ist dieser E-Mail angehängt.</p>
  <p>Mit freundlichen Grüßen<br>{{ $invoice->organization?->name ?? 'WWC' }}</p>
</body>
</html>

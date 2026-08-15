<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 14px; margin-top: 22px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h1>Wartungsbericht {{ $month->format('m/Y') }}</h1>
    <p class="muted">{{ $project->name }} · {{ $project->client->name ?? '' }}</p>

    <h2>Kennzahlen</h2>
    <table>
        <tr><th>Sites</th><td>{{ $project->sites->count() }}</td></tr>
        <tr><th>Updates / Änderungen</th><td>{{ $updates }}</td></tr>
        <tr><th>Backups</th><td>{{ $backups->count() }} (davon {{ $backups->whereNotNull('verified_at')->count() }} geprüft)</td></tr>
        <tr><th>Wartungsläufe</th><td>{{ $runs->count() }}</td></tr>
        <tr><th>Stunden</th><td>{{ $hours_used }} von {{ $hours_included }} enthalten</td></tr>
    </table>

    <h2>Sites</h2>
    <table>
        <thead><tr><th>Name</th><th>URL</th><th>Status</th><th>WP</th><th>PHP</th></tr></thead>
        <tbody>
        @foreach($project->sites as $site)
            <tr>
                <td>{{ $site->name }}</td>
                <td>{{ $site->url }}</td>
                <td>{{ $site->status }}</td>
                <td>{{ $site->wp_version }}</td>
                <td>{{ $site->php_version }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Tätigkeit</h2>
    <table>
        <thead><tr><th>Datum</th><th>Site</th><th>Ereignis</th></tr></thead>
        <tbody>
        @forelse($events->take(40) as $ev)
            <tr>
                <td>{{ optional($ev->occurred_at)->format('d.m.Y H:i') }}</td>
                <td>{{ $ev->site_id }}</td>
                <td>{{ $ev->title }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Keine Ereignisse in diesem Monat.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>

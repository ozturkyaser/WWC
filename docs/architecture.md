# WWC Architektur

## Komponenten

- **Portal (Next.js)** spricht nur mit der API (Sanctum Bearer Token).
- **API (Laravel)** speichert Tenants, Sites, Events, Jobs, Vulns, Rechnungen.
- **WP-Agent** empfängt HMAC-signierte Commands und pusht Heartbeats/Events.

## Sicherheit

Jede Agent↔API-Nachricht:

```
X-WWC-Timestamp
X-WWC-Nonce
X-WWC-Key-Id
X-WWC-Signature = HMAC-SHA256(method\npath\ntimestamp\nnonce\nbody, secret)
```

Replay-Schutz: ±120s + Nonce in Redis. Secret-Rotation mit Previous-Key.

## Commands (Allowlist)

`ping`, `inventory`, `update_plugin`, `update_theme`, `update_core`, `run_scan`, `self_update`

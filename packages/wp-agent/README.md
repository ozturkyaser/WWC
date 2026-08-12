# WWC Agent

WordPress-Plugin für die WWC-Wartungsplattform.

## Installation

1. Ordner `wwc-agent` nach `wp-content/plugins/` kopieren (oder ZIP erstellen)
2. Plugin aktivieren
3. Unter Einstellungen → WWC Agent Pairing-Code und API-URL eintragen

## Sicherheit

- HMAC-SHA256 auf allen REST-Routen
- Replay-Schutz (Timestamp + Nonce)
- Command-Allowlist
- Kill-Switch: `define('WWC_AGENT_DISABLED', true);` in `wp-config.php`

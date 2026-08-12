# WWC WordPress-Wartungsplattform

Zentrales Management-Portal + sicheres Agent-Plugin für die Fernwartung von WordPress-Sites.

## Struktur

```
apps/api          Laravel API (Auth, Sites, Jobs, Vuln, Billing) — PHP 8.4+
apps/portal       Next.js Dashboard
packages/wp-agent WordPress Agent-Plugin
docs/             Architektur
```

## Schnellstart

```bash
# Infra (optional)
docker compose up -d postgres redis mailpit

# API (PHP 8.4 via Docker, SQLite out of the box)
docker run --rm -it -v "$PWD/apps/api:/app" -w /app -p 8080:8080 php:8.4-cli \
  bash -c "php artisan migrate --seed --force && php artisan serve --host=0.0.0.0 --port=8080"

# Portal
cd apps/portal
cp .env.local.example .env.local
npm install
npm run dev
```

Portal: http://localhost:3000  
API: http://localhost:8080  

**Login nach Seed:** `admin@wwc.local` / `password`

## Online-WordPress + lokale API

Eine **Online-Website kann localhost nicht erreichen**. Dafür brauchst du eine öffentliche API-URL:

**Option A – schnell zum Testen (Tunnel):**
```bash
./scripts/start-tunnel.sh
```
Die ausgegebene `https://….trycloudflare.com` URL im WP-Plugin als API-URL eintragen.

**Option B – Produktion (empfohlen):**  
API + Portal auf einen VPS/Cloud-Host deployen und `WWC_PUBLIC_API_URL=https://api.deine-domain.de` setzen.

## Agent installieren

**Im Portal (empfohlen):**
1. Unter **Projekte** Projekt + Site-URL anlegen
2. Der Browser lädt automatisch `wwc-agent.zip` herunter
3. Pairing-Code + **öffentliche** API-URL in WP unter Einstellungen → WWC Agent eintragen

**Manuell:** Quelle liegt unter `packages/wp-agent` (Kopie auch in `apps/api/resources/wp-agent`).  
Download-API: `GET /api/plugin/download` (auth)

## Kernfunktionen

- HMAC-signierte Remote-Commands (Updates, Scans, Inventory)
- Heartbeat + Events (fehlgeschlagene Logins, Plugin/Theme-Änderungen)
- Vulnerability-Abgleich + Safe Auto-Fix (projektbezogen)
- Agent-Self-Update über signierte Release-Metadaten
- Projekte mit Wartungsumfang/Monatsbudget
- Automatische DE-PDF-Rechnungen (`php artisan wwc:bill-monthly`)
- Staging/Dev-Umgebung mit Portal-Subdomain (`{slug}.dev.localhost:3000`), Live-Vorschau und WP-Admin-Magic-Login
- Projekt-Wizard: Impressum → Kunde → Wartungsstufe (1/2/3/Custom) → Plugin → Full-Backup → Dev-Umgebung
- Monatsrechnung (`wwc:bill-monthly`) inkl. PDF-E-Mail an Kundenadresse aus dem Impressum

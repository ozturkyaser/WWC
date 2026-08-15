#!/usr/bin/env bash
set -euo pipefail

# Cloudflare Quick Tunnel -> lokale WWC API
# Damit Online-WordPress-Sites die lokale API erreichen können.

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API_PORT="${WWC_API_PORT:-8082}"

if ! curl -fsS "http://127.0.0.1:${API_PORT}/up" >/dev/null 2>&1; then
  echo "API auf Port ${API_PORT} ist nicht erreichbar. Bitte zuerst die API starten."
  exit 1
fi

docker rm -f wwc-tunnel >/dev/null 2>&1 || true
docker run -d --name wwc-tunnel \
  --add-host=host.docker.internal:host-gateway \
  cloudflare/cloudflared:latest \
  tunnel --no-autoupdate --url "http://host.docker.internal:${API_PORT}" >/dev/null

echo "Warte auf Tunnel-URL…"
URL=""
for _ in $(seq 1 40); do
  URL="$(docker logs wwc-tunnel 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | tail -1 || true)"
  if [[ -n "${URL}" ]]; then
    break
  fi
  sleep 1
done

if [[ -z "${URL}" ]]; then
  echo "Tunnel-URL nicht gefunden. Logs:"
  docker logs wwc-tunnel 2>&1 | tail -40
  exit 1
fi

ENV_FILE="${ROOT}/apps/api/.env"
if [[ -f "${ENV_FILE}" ]]; then
  if grep -q '^WWC_PUBLIC_API_URL=' "${ENV_FILE}"; then
    sed -i.bak "s|^WWC_PUBLIC_API_URL=.*|WWC_PUBLIC_API_URL=${URL}|" "${ENV_FILE}"
  else
    echo "WWC_PUBLIC_API_URL=${URL}" >> "${ENV_FILE}"
  fi
  if grep -q '^APP_URL=' "${ENV_FILE}"; then
    sed -i.bak "s|^APP_URL=.*|APP_URL=${URL}|" "${ENV_FILE}"
  fi
  rm -f "${ENV_FILE}.bak"
fi

if docker ps --format '{{.Names}}' | grep -qE '^wwc-api'; then
  docker compose -f "${ROOT}/docker-compose.yml" exec -T api php artisan config:clear >/dev/null 2>&1 || true
fi

cat <<EOF

Öffentliche API-URL (für Online-WordPress):
  ${URL}

1) Portal neu laden
2) Site/Projekt "Neu verbinden"
3) Im WP-Plugin genau diese HTTPS-URL + Pairing-Code eintragen

Hinweis: Quick-Tunnel-URL ändert sich bei jedem Neustart.
Für Dauerbetrieb die API auf einem VPS hosten (empfohlen).

Stoppen: docker rm -f wwc-tunnel

EOF

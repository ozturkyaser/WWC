<?php

namespace App\Services;

use App\Models\PairingCode;
use App\Models\Site;
use Illuminate\Support\Str;

class PairingService
{
    public function createCode(Site $site): PairingCode
    {
        PairingCode::where('site_id', $site->id)->whereNull('used_at')->delete();

        return PairingCode::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'code' => strtoupper(Str::random(6)).'-'.strtoupper(Str::random(6)),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function complete(string $code, string $siteUrl, array $meta = [], ?string $reachableApiBase = null): array
    {
        $normalized = strtoupper(trim(str_replace(' ', '', $code)));
        $pairing = PairingCode::where('code', $normalized)->first();

        if (! $pairing) {
            throw new \InvalidArgumentException('Pairing-Code unbekannt.');
        }
        if ($pairing->used_at !== null) {
            throw new \InvalidArgumentException('Pairing-Code wurde bereits verwendet. Bitte im Portal neu verbinden.');
        }
        if ($pairing->expires_at->isPast()) {
            throw new \InvalidArgumentException('Pairing-Code abgelaufen. Bitte im Portal neu verbinden.');
        }

        $site = $pairing->site;
        if (! $site) {
            throw new \InvalidArgumentException('Site zum Pairing-Code nicht gefunden.');
        }

        $secret = bin2hex(random_bytes(32));
        $keyId = bin2hex(random_bytes(8));

        $site->url = rtrim($siteUrl, '/');
        $site->setHmacSecret($secret);
        $site->hmac_secret_previous_encrypted = null;
        $site->key_id = $keyId;
        $site->status = 'online';
        $site->paired_at = now();
        $site->last_seen_at = now();
        $site->wp_version = $meta['wp_version'] ?? null;
        $site->php_version = $meta['php_version'] ?? null;
        $site->agent_version = $meta['agent_version'] ?? null;
        $site->save();

        $pairing->used_at = now();
        $pairing->save();

        AuditLogger::log('site.paired', $site->organization_id, null, $site->id, [
            'url' => $site->url,
        ]);

        // After pairing: Full-Backup → Development/Staging (wizard onboarding)
        if ($site->onboarding_status === 'awaiting_pair' || $site->project_id) {
            if (! in_array($site->onboarding_status, ['done', 'awaiting_backup', 'awaiting_staging'], true)) {
                try {
                    app(SiteOnboardingService::class)->startAfterPairing($site->fresh());
                } catch (\Throwable) {
                    // Pairing itself must succeed even if onboarding dispatch fails.
                }
            }
        }

        // Critical: return the URL the agent actually used to reach us,
        // not APP_URL (often localhost and unreachable from WP containers/VMs).
        $apiBase = rtrim($reachableApiBase ?: (config('wwc.public_api_url') ?: config('app.url')), '/');

        return [
            'site_id' => $site->id,
            'api_url' => $apiBase.'/api/agent',
            'hmac_secret' => $secret,
            'key_id' => $keyId,
        ];
    }

    public function disconnect(Site $site): void
    {
        $site->hmac_secret_encrypted = null;
        $site->hmac_secret_previous_encrypted = null;
        $site->key_id = null;
        $site->paired_at = null;
        $site->status = 'pending';
        $site->save();

        PairingCode::where('site_id', $site->id)->whereNull('used_at')->delete();

        AuditLogger::log('site.disconnected', $site->organization_id, null, $site->id);
    }

    public function reconnect(Site $site): array
    {
        $this->disconnect($site);
        $code = $this->createCode($site);

        return [
            'site_id' => $site->id,
            'pairing_code' => $code->code,
            'expires_at' => $code->expires_at,
            'plugin_download_url' => url('/api/plugin/download'),
            'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
        ];
    }

    public function rotateSecret(Site $site): array
    {
        $newSecret = bin2hex(random_bytes(32));
        $site->rotateHmacSecret($newSecret);
        $site->save();

        AuditLogger::log('site.secret_rotated', $site->organization_id, null, $site->id);

        return [
            'hmac_secret' => $newSecret,
            'key_id' => $site->key_id,
            'previous_key_id' => 'previous',
        ];
    }
}

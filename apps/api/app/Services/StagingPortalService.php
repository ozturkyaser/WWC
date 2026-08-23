<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class StagingPortalService
{
    public function syncFromStagingPayload(Site $site, ?array $staging, bool $destroyed = false): void
    {
        if ($destroyed || ! is_array($staging) || empty($staging['exists'])) {
            $health = is_array($site->health) ? $site->health : [];
            $health['staging'] = ['exists' => false];
            $site->forceFill([
                'staging_url' => null,
                'staging_admin_url' => null,
                'staging_access_encrypted' => null,
                'staging_ready_at' => null,
                'health' => $health,
            ])->save();

            return;
        }

        if (! $site->staging_slug) {
            $site->staging_slug = $this->uniqueSlug($site);
        }

        $access = $staging['access'] ?? null;
        if (is_array($access) && (! empty($access['login_token']) || ! empty($access['password']))) {
            $site->staging_access_encrypted = Crypt::encryptString(json_encode($access));
        }

        $adminLogin = $staging['admin_login_url'] ?? null;
        if (! $adminLogin && is_array($access) && ! empty($access['admin_login_url'])) {
            $adminLogin = $access['admin_login_url'];
        }

        $health = is_array($site->health) ? $site->health : [];
        $safe = $this->stripSecretsFromHealth(['staging' => $staging]);
        $health['staging'] = array_merge(
            is_array($health['staging'] ?? null) ? $health['staging'] : [],
            is_array($safe['staging'] ?? null) ? $safe['staging'] : [],
            ['exists' => true, 'url' => $staging['url'] ?? $site->staging_url]
        );

        $site->forceFill([
            'staging_url' => $staging['url'] ?? $site->staging_url,
            'staging_admin_url' => $adminLogin ?: ($staging['admin_url'] ?? $site->staging_admin_url),
            'staging_ready_at' => now(),
            'health' => $health,
        ])->save();
    }

    public function syncFromJobResult(Site $site, string $command, ?array $result): void
    {
        if ($command === 'staging_destroy' && ($result['ok'] ?? false)) {
            $this->syncFromStagingPayload($site, null, true);
            $site->forceFill(['staging_slug' => null])->save();

            return;
        }

        if (in_array($command, ['staging_create', 'staging_status', 'staging_grant_admin'], true)) {
            $staging = $result['staging'] ?? null;
            if (! is_array($staging) && ($result['ok'] ?? false) && $command === 'staging_grant_admin') {
                $staging = [
                    'exists' => true,
                    'url' => $result['admin_url'] ?? $site->staging_url,
                    'admin_url' => $result['admin_url'] ?? null,
                    'admin_login_url' => $result['admin_login_url'] ?? null,
                    'access' => $result,
                ];
            }
            if (is_array($staging)) {
                if (! empty($result['access']) && is_array($result['access'])) {
                    $staging['access'] = $result['access'];
                }
                $this->syncFromStagingPayload($site, $staging);
            }
        }
    }

    public function stripSecretsFromHealth(?array $health): ?array
    {
        if (! is_array($health)) {
            return $health;
        }
        if (isset($health['staging']['access'])) {
            $access = $health['staging']['access'];
            $health['staging']['access'] = [
                'username' => $access['username'] ?? 'wwc-dev',
                'expires_at' => $access['expires_at'] ?? null,
                'has_credentials' => true,
            ];
        }
        if (isset($health['staging']['admin_login_url'])) {
            // Token stays in encrypted staging_access; avoid leaking via health dumps.
            unset($health['staging']['admin_login_url']);
        }

        return $health;
    }

    public function getAccess(Site $site): ?array
    {
        if (! $site->staging_access_encrypted) {
            return null;
        }

        try {
            $raw = Crypt::decryptString($site->staging_access_encrypted);
            $data = json_decode($raw, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function portalUrl(Site $site): ?string
    {
        if (! $site->staging_slug) {
            return null;
        }

        return $this->portalUrlForSlug($site->staging_slug);
    }

    public function portalUrlForSlug(string $slug): string
    {
        return rtrim($this->portalBase(), '/').'/dev/'.rawurlencode($slug);
    }

    /**
     * Public WWC portal origin. Never emit localhost on production – that is
     * only valid for local APP_ENV + WWC_PORTAL_URL.
     */
    private function portalBase(): string
    {
        $configured = rtrim((string) config('wwc.portal_url', ''), '/');
        $app = rtrim((string) config('app.url', ''), '/');
        $public = 'https://wwc.kiservicehub.de';

        if (app()->environment('local') && $this->isLoopbackUrl($configured)) {
            return $configured !== '' ? $configured : 'http://localhost:3000';
        }

        foreach ([$configured, $app, $public] as $candidate) {
            if ($candidate !== '' && ! $this->isLoopbackUrl($candidate)) {
                return $candidate;
            }
        }

        return $public;
    }

    private function isLoopbackUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.localhost');
    }

    public function uniqueSlug(Site $site): string
    {
        $base = Str::slug($site->name) ?: 'site';
        $base = Str::limit($base, 40, '');
        $candidate = $base;
        $i = 0;
        while (
            Site::where('staging_slug', $candidate)
                ->where('id', '!=', $site->id)
                ->exists()
        ) {
            $i++;
            $candidate = $base.'-'.substr(str_replace('-', '', $site->id), 0, 6);
            if ($i > 1) {
                $candidate .= '-'.$i;
            }
        }

        return $candidate;
    }

    public function toPortalPayload(Site $site): array
    {
        $access = $this->getAccess($site);
        $exists = (bool) ($site->health['staging']['exists'] ?? false) || (bool) $site->staging_ready_at;

        return [
            'exists' => $exists && (bool) $site->staging_url,
            'slug' => $site->staging_slug,
            'portal_url' => $this->portalUrl($site),
            'preview_url' => $site->staging_url,
            'admin_url' => $access['admin_url'] ?? $site->staging_admin_url,
            'admin_login_url' => $access['admin_login_url'] ?? $site->staging_admin_url,
            'access' => $access ? [
                'username' => $access['username'] ?? 'wwc-dev',
                'password' => $access['password'] ?? null,
                'expires_at' => $access['expires_at'] ?? null,
            ] : null,
            'ready_at' => $site->staging_ready_at,
        ];
    }
}

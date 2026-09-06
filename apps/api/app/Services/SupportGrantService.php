<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Str;

class SupportGrantService
{
    /**
     * @param  array{site_url:string,wp_version?:string,php_version?:string,agent_version?:string,contact?:string,note?:string}  $meta
     * @return array{site_id:string,api_url:string,hmac_secret:string,key_id:string,created:bool}
     */
    public function grant(array $meta, ?string $reachableApiBase = null): array
    {
        $url = $this->normalizeUrl((string) $meta['site_url']);
        if ($url === '') {
            throw new \InvalidArgumentException('Site-URL ungültig.');
        }

        $site = $this->findByUrl($url);
        $created = false;
        if (! $site) {
            $org = $this->inboxOrganization();
            $site = Site::create([
                'organization_id' => $org->id,
                'name' => $this->nameFromUrl($url),
                'url' => $url,
                'status' => 'online',
                'access_mode' => 'support',
            ]);
            $created = true;
        }

        $secret = $site->getHmacSecret();
        if ($secret === null || $secret === '') {
            $secret = bin2hex(random_bytes(32));
            $site->setHmacSecret($secret);
            $site->hmac_secret_previous_encrypted = null;
            $site->key_id = bin2hex(random_bytes(8));
        }

        $site->url = $url;
        $site->status = 'online';
        $site->paired_at = $site->paired_at ?? now();
        $site->last_seen_at = now();
        $site->wp_version = $meta['wp_version'] ?? $site->wp_version;
        $site->php_version = $meta['php_version'] ?? $site->php_version;
        $site->agent_version = $meta['agent_version'] ?? $site->agent_version;
        $site->support_granted_at = now();
        $site->support_revoked_at = null;
        $site->support_contact = isset($meta['contact']) ? mb_substr(trim((string) $meta['contact']), 0, 190) : $site->support_contact;
        $site->support_note = isset($meta['note']) ? mb_substr(trim((string) $meta['note']), 0, 500) : $site->support_note;
        if ($site->access_mode !== 'pair') {
            $site->access_mode = 'support';
        }
        $site->save();

        AuditLogger::log('site.support_granted', $site->organization_id, null, $site->id, [
            'url' => $site->url,
            'created' => $created,
            'contact' => $site->support_contact,
        ]);

        $apiBase = rtrim($reachableApiBase ?: (config('wwc.public_api_url') ?: config('app.url')), '/');

        return [
            'site_id' => $site->id,
            'api_url' => $apiBase.'/api/agent',
            'hmac_secret' => $secret,
            'key_id' => (string) $site->key_id,
            'created' => $created,
        ];
    }

    public function revoke(Site $site): void
    {
        $site->support_revoked_at = now();
        $site->save();

        if (($site->access_mode ?? '') === 'support') {
            app(PairingService::class)->disconnect($site);
            $site->refresh();
            $site->access_mode = 'support';
            $site->support_revoked_at = now();
            $site->save();
        }

        AuditLogger::log('site.support_revoked', $site->organization_id, null, $site->id, [
            'url' => $site->url,
        ]);
    }

    public function inboxOrganization(): Organization
    {
        $configured = trim((string) config('wwc.support_organization_id', ''));
        if ($configured !== '') {
            $org = Organization::query()->find($configured);
            if ($org) {
                return $org;
            }
        }

        $existing = Organization::query()->orderBy('created_at')->first();
        if ($existing) {
            return $existing;
        }

        return Organization::create([
            'name' => 'WWC Support',
            'slug' => 'wwc-support-'.Str::lower(Str::random(6)),
        ]);
    }

    public function findByUrl(string $url): ?Site
    {
        $host = strtolower((string) (parse_url($this->normalizeUrl($url), PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return null;
        }
        $bare = preg_replace('/^www\./', '', $host) ?: $host;

        return Site::query()
            ->get()
            ->first(function (Site $site) use ($bare): bool {
                $siteHost = strtolower((string) (parse_url($this->normalizeUrl((string) $site->url), PHP_URL_HOST) ?: ''));
                $siteBare = preg_replace('/^www\./', '', $siteHost) ?: $siteHost;

                return $siteBare === $bare;
            });
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme.'://'.$host.$path;
    }

    private function nameFromUrl(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        return $host !== '' ? $host : 'Support-Site';
    }
}

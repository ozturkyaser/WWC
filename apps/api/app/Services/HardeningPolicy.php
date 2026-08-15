<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;

class HardeningPolicy
{
    public const KEYS = [
        'hide_login',
        'limit_login_attempts',
        'disable_xmlrpc',
        'disable_file_edit',
        'disable_user_enumeration',
        'hide_wp_version',
        'security_headers',
        'disable_pingbacks',
        'disable_app_passwords',
        'block_php_uploads',
        'disable_directory_listing',
    ];

    public function defaultsForTier(string $tier): array
    {
        $base = [
            'hide_login' => false,
            'limit_login_attempts' => true,
            'disable_xmlrpc' => true,
            'disable_file_edit' => false,
            'disable_user_enumeration' => false,
            'hide_wp_version' => true,
            'security_headers' => true,
            'disable_pingbacks' => true,
            'disable_app_passwords' => false,
            'block_php_uploads' => false,
            'disable_directory_listing' => false,
        ];

        if (in_array($tier, ['2', '3', 'custom'], true)) {
            $base['disable_file_edit'] = true;
            $base['disable_user_enumeration'] = true;
            $base['block_php_uploads'] = true;
            $base['disable_directory_listing'] = true;
        }
        if ($tier === '3') {
            $base['hide_login'] = true;
            $base['disable_app_passwords'] = true;
        }

        return $base;
    }

    public function expectedForSite(Site $site): array
    {
        $tier = (string) ($site->project?->maintenance_tier ?: '1');
        $org = $site->organization;
        $templates = is_array($org?->hardening_templates) ? $org->hardening_templates : [];
        $override = is_array($templates[$tier] ?? null) ? $templates[$tier] : [];

        return array_merge($this->defaultsForTier($tier), $override);
    }

    /**
     * @return list<string>
     */
    public function drift(Site $site): array
    {
        $expected = $this->expectedForSite($site);
        $actual = $site->hardening['settings'] ?? [];
        $missing = [];
        foreach (self::KEYS as $key) {
            if (! empty($expected[$key]) && empty($actual[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function applyTemplate(Organization $org, string $tier, array $settings): array
    {
        $templates = $org->hardening_templates ?? [];
        $clean = [];
        foreach (self::KEYS as $key) {
            $clean[$key] = (bool) ($settings[$key] ?? false);
        }
        $templates[$tier] = $clean;
        $org->update(['hardening_templates' => $templates]);

        return $clean;
    }
}

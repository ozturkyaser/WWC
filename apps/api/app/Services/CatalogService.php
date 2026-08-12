<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Str;

class CatalogService
{
    public function defaultHourPackages(): array
    {
        return [
            [
                'id' => 'hp-5',
                'name' => '5 Stunden',
                'hours' => 5,
                'price_cents' => 37500,
                'billing' => 'once',
                'active' => true,
                'description' => 'Support-Kontingent 5 Stunden',
            ],
            [
                'id' => 'hp-10',
                'name' => '10 Stunden',
                'hours' => 10,
                'price_cents' => 70000,
                'billing' => 'once',
                'active' => true,
                'description' => 'Support-Kontingent 10 Stunden',
            ],
            [
                'id' => 'hp-20',
                'name' => '20 Stunden',
                'hours' => 20,
                'price_cents' => 130000,
                'billing' => 'once',
                'active' => true,
                'description' => 'Support-Kontingent 20 Stunden',
            ],
        ];
    }

    public function hourPackages(Organization $org, bool $onlyActive = false): array
    {
        $packages = $org->hour_packages;
        if (! is_array($packages) || $packages === []) {
            $packages = $this->defaultHourPackages();
        }

        $normalized = collect($packages)->map(function ($p) {
            return [
                'id' => (string) ($p['id'] ?? Str::uuid()),
                'name' => (string) ($p['name'] ?? 'Paket'),
                'hours' => (float) ($p['hours'] ?? 0),
                'price_cents' => (int) ($p['price_cents'] ?? 0),
                'billing' => in_array($p['billing'] ?? 'once', ['once', 'monthly'], true) ? $p['billing'] : 'once',
                'active' => (bool) ($p['active'] ?? true),
                'description' => (string) ($p['description'] ?? ''),
            ];
        })->values()->all();

        if ($onlyActive) {
            return array_values(array_filter($normalized, fn ($p) => $p['active'] && $p['hours'] > 0));
        }

        return $normalized;
    }

    public function sanitizeHourPackages(array $packages): array
    {
        return collect($packages)->map(function ($p) {
            $hours = max(0, (float) ($p['hours'] ?? 0));
            $price = max(0, (int) ($p['price_cents'] ?? 0));

            return [
                'id' => (string) ($p['id'] ?: Str::uuid()),
                'name' => trim((string) ($p['name'] ?? 'Paket')) ?: 'Paket',
                'hours' => $hours,
                'price_cents' => $price,
                'billing' => in_array($p['billing'] ?? 'once', ['once', 'monthly'], true) ? $p['billing'] : 'once',
                'active' => (bool) ($p['active'] ?? true),
                'description' => trim((string) ($p['description'] ?? '')),
            ];
        })->values()->all();
    }

    /**
     * Merge global tier defaults with optional org overrides (price + hours).
     */
    public function maintenanceTiers(Organization $org): array
    {
        $defaults = config('wwc.maintenance_tiers', []);
        $overrides = is_array($org->maintenance_tiers) ? $org->maintenance_tiers : [];

        return collect($defaults)->map(function ($tier, $key) use ($overrides) {
            $over = $overrides[$key] ?? [];
            $scope = array_merge($tier['scope'] ?? [], $over['scope'] ?? []);
            if (isset($over['hours_included'])) {
                $scope['hours_included'] = (float) $over['hours_included'];
            }

            return [
                'key' => $tier['key'],
                'label' => $over['label'] ?? $tier['label'],
                'description' => $over['description'] ?? ($tier['description'] ?? ''),
                'monthly_cents' => array_key_exists('monthly_cents', $over)
                    ? ($over['monthly_cents'] !== null ? (int) $over['monthly_cents'] : null)
                    : $tier['monthly_cents'],
                'scope' => $scope,
                'hours_included' => (float) ($scope['hours_included'] ?? 0),
            ];
        })->values()->all();
    }

    public function sanitizeMaintenanceTierOverrides(array $tiers): array
    {
        $out = [];
        foreach ($tiers as $key => $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $k = (string) ($tier['key'] ?? $key);
            if (! in_array($k, ['1', '2', '3', 'custom'], true)) {
                continue;
            }
            $row = [];
            if (isset($tier['label'])) {
                $row['label'] = (string) $tier['label'];
            }
            if (isset($tier['description'])) {
                $row['description'] = (string) $tier['description'];
            }
            if (array_key_exists('monthly_cents', $tier)) {
                $row['monthly_cents'] = $tier['monthly_cents'] === null || $tier['monthly_cents'] === ''
                    ? null
                    : (int) $tier['monthly_cents'];
            }
            if (isset($tier['hours_included'])) {
                $row['hours_included'] = (float) $tier['hours_included'];
            }
            if ($row !== []) {
                $out[$k] = $row;
            }
        }

        return $out;
    }
}

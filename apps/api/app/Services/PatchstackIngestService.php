<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingests Patchstack public vulnerability listings.
 *
 * Primary: SSR __NUXT_DATA__ from https://patchstack.com/database (and filter/search variants).
 * Secondary: hub public/home?page=N when reachable.
 * Optional: official Threat Intelligence API via PSKey.
 *
 * @see https://patchstack.com/database
 * @see https://docs.patchstack.com/api-solutions/threat-intelligence-api/extended/
 */
class PatchstackIngestService
{
    private const PUBLIC_DB = 'https://patchstack.com/database';

    private const HUB_HOME = 'https://hub-vdp-api-production-csruhi.patchstack.cloud/public/home';

    /** @var array<int, bool> */
    private array $nuxtStack = [];

    public function syncPublicPages(int $maxPages = 100, bool $incremental = true): array
    {
        $maxPages = max(1, min(100, $maxPages));
        $cutoff = $incremental ? now()->subDay()->startOfDay() : null;
        $seen = [];
        $items = [];
        $pagesOk = 0;
        $hubOk = false;

        // Always pull SSR home + high-signal filter/search shards (Cloudflare often blocks hub pagination).
        foreach ($this->publicListUrls($maxPages) as $url) {
            try {
                $parsed = $this->fetchNuxtVulnerabilities($url);
                foreach ($parsed['items'] as $row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug === '' || isset($seen[$slug])) {
                        continue;
                    }
                    if ($cutoff && ! empty($row['disclosure_date'])) {
                        try {
                            if (\Carbon\Carbon::parse($row['disclosure_date'])->lt($cutoff) && $incremental) {
                                // keep very recent pages; skip clearly older rows on incremental runs
                                if (($parsed['page'] ?? 1) > 3) {
                                    continue;
                                }
                            }
                        } catch (Throwable) {
                            // ignore parse errors
                        }
                    }
                    $seen[$slug] = true;
                    $items[] = $row;
                }
                if ($parsed['items'] !== []) {
                    $pagesOk++;
                }
                usleep(250_000);
            } catch (Throwable $e) {
                Log::warning('Patchstack SSR fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        // Attempt hub pagination pages 1..maxPages (works when CF allows the server IP).
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $hub = $this->fetchHubPage($page);
                if ($hub === []) {
                    if ($page === 1) {
                        break;
                    }
                    continue;
                }
                $hubOk = true;
                foreach ($hub as $row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug === '' || isset($seen[$slug])) {
                        continue;
                    }
                    $seen[$slug] = true;
                    $items[] = $row;
                }
                usleep(200_000);
            } catch (Throwable $e) {
                Log::info('Patchstack hub page skipped', ['page' => $page, 'error' => $e->getMessage()]);
                if ($page === 1) {
                    break;
                }
            }
        }

        return [
            'items' => $items,
            'unique' => count($items),
            'sources' => [
                'ssr_fetches_ok' => $pagesOk,
                'hub_ok' => $hubOk,
                'max_pages' => $maxPages,
            ],
        ];
    }

    public function syncOfficialLatest(?string $apiKey): array
    {
        if (! $apiKey) {
            return ['items' => [], 'unique' => 0];
        }

        $response = Http::timeout(45)
            ->withHeaders(['PSKey' => $apiKey, 'Accept' => 'application/json'])
            ->get('https://patchstack.com/database/api/v2/latest');

        if (! $response->successful()) {
            Log::warning('Patchstack API /latest failed', ['status' => $response->status(), 'body' => $response->body()]);

            return ['items' => [], 'unique' => 0, 'error' => 'HTTP '.$response->status()];
        }

        $json = $response->json();
        $rawItems = is_array($json['vulnerabilities'] ?? null)
            ? $json['vulnerabilities']
            : (is_array($json) ? $json : []);

        $items = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapApiItem($row);
            if ($mapped) {
                $items[] = $mapped;
            }
        }

        return ['items' => $items, 'unique' => count($items)];
    }

    /**
     * Product-aware lookup for installed inventory (proper per-site security check).
     *
     * @param  array<int, array{name:string,version:string,type:string}>  $products
     */
    public function lookupProducts(?string $apiKey, array $products): array
    {
        if (! $apiKey || $products === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($products, 50) as $chunk) {
            $payload = array_map(static function (array $p) {
                return [
                    'name' => $p['name'],
                    'version' => $p['version'],
                    'type' => $p['type'],
                    'exists' => false,
                ];
            }, $chunk);

            $response = Http::timeout(60)
                ->withHeaders([
                    'PSKey' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://patchstack.com/database/api/v2/batch', $payload);

            if (! $response->successful()) {
                Log::warning('Patchstack batch failed', ['status' => $response->status()]);
                continue;
            }

            $vulns = $response->json('vulnerabilities') ?? [];
            if (! is_array($vulns)) {
                continue;
            }
            foreach ($vulns as $slug => $result) {
                if (! is_array($result)) {
                    continue;
                }
                foreach ($result as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $mapped = $this->mapApiItem($row, is_string($slug) ? $slug : null);
                    if ($mapped) {
                        $out[] = $mapped;
                    }
                }
            }
            usleep(150_000);
        }

        return $out;
    }

    /** @return list<string> */
    private function publicListUrls(int $maxPages): array
    {
        $urls = [self::PUBLIC_DB];
        for ($p = 1; $p <= $maxPages; $p++) {
            $urls[] = self::PUBLIC_DB.'?page='.$p;
        }
        $urls[] = self::PUBLIC_DB.'?exploited=1';

        // Broader coverage when hub pagination is blocked (Cloudflare).
        if ($maxPages >= 10) {
            foreach (['sql', 'xss', 'rce', 'csrf', 'upload', 'privilege', 'injection', 'wordpress', 'woocommerce', 'elementor', 'contact-form'] as $term) {
                $urls[] = self::PUBLIC_DB.'?search='.rawurlencode($term);
            }
        }
        if ($maxPages >= 25) {
            foreach (range('a', 'z') as $letter) {
                $urls[] = self::PUBLIC_DB.'?search='.$letter;
            }
        }

        return array_values(array_unique($urls));
    }

    private function fetchHubPage(int $page): array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => 'https://patchstack.com',
                'Referer' => 'https://patchstack.com/database?page='.$page,
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ])
            ->get(self::HUB_HOME, ['page' => $page]);

        if (! $response->successful()) {
            throw new \RuntimeException('Hub HTTP '.$response->status());
        }

        $json = $response->json();
        $rows = $json['vulnerabilities']['data'] ?? $json['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $mapped = $this->normalizePublicRow($row);
                if ($mapped) {
                    $out[] = $mapped;
                }
            }
        }

        return $out;
    }

    private function fetchNuxtVulnerabilities(string $url): array
    {
        $response = Http::timeout(45)
            ->withHeaders([
                'Accept' => 'text/html',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('SSR HTTP '.$response->status());
        }

        $html = $response->body();
        if (! preg_match('/id="__NUXT_DATA__">(.*?)<\/script>/s', $html, $m)) {
            return ['items' => [], 'page' => 1];
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            return ['items' => [], 'page' => 1];
        }

        $this->nuxtStack = [];
        $root = $this->nuxtRef(1, $data);
        $vulns = $this->findVulnerabilitiesNode($root);
        $items = [];
        foreach (($vulns['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->normalizePublicRow($row);
            if ($mapped) {
                $items[] = $mapped;
            }
        }

        return [
            'items' => $items,
            'page' => (int) ($vulns['pagination']['current_page'] ?? 1),
            'total_pages' => (int) ($vulns['pagination']['total_pages'] ?? 1),
        ];
    }

    private function findVulnerabilitiesNode(mixed $obj): ?array
    {
        if (! is_array($obj)) {
            return null;
        }
        if (isset($obj['vulnerabilities']) && is_array($obj['vulnerabilities']) && isset($obj['vulnerabilities']['data'])) {
            return $obj['vulnerabilities'];
        }
        foreach ($obj as $value) {
            $found = $this->findVulnerabilitiesNode($value);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function nuxtHydrate(mixed $val, array $data): mixed
    {
        if (is_string($val) || is_float($val) || is_bool($val) || $val === null) {
            return $val;
        }
        if (is_int($val)) {
            return $val;
        }
        if (is_array($val)) {
            if ($val !== [] && is_string($val[0] ?? null) && in_array($val[0], ['ShallowReactive', 'Reactive', 'Set'], true)) {
                return isset($val[1]) ? $this->nuxtRef($val[1], $data) : null;
            }
            if ($this->isList($val)) {
                return array_map(fn ($x) => $this->nuxtRef($x, $data), $val);
            }
            $out = [];
            foreach ($val as $k => $v) {
                $out[$k] = $this->nuxtRef($v, $data);
            }

            return $out;
        }

        return $val;
    }

    private function nuxtRef(mixed $x, array $data): mixed
    {
        if (is_int($x)) {
            if ($x < 0 || $x >= count($data) || isset($this->nuxtStack[$x])) {
                return null;
            }
            $this->nuxtStack[$x] = true;
            try {
                return $this->nuxtHydrate($data[$x], $data);
            } finally {
                unset($this->nuxtStack[$x]);
            }
        }

        return $this->nuxtHydrate($x, $data);
    }

    private function isList(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function normalizePublicRow(array $row): ?array
    {
        $platform = strtolower((string) ($row['platform'] ?? ''));
        if ($platform !== '' && $platform !== 'wordpress') {
            return null; // focus WP ecosystem for this portal
        }

        $slug = (string) ($row['product_slug'] ?? $row['slug'] ?? '');
        $external = (string) ($row['slug'] ?? $row['url'] ?? '');
        if ($external === '' && $slug === '') {
            return null;
        }

        $kind = strtolower((string) ($row['kind'] ?? 'plugin'));
        $component = match (true) {
            str_contains($kind, 'theme') => 'theme',
            str_contains($kind, 'core') || $slug === 'wordpress' => 'core',
            default => 'plugin',
        };

        $cvss = isset($row['cvss']) ? (float) $row['cvss'] : null;
        $title = trim((string) ($row['product_name'] ?? $slug).' – '.(string) ($row['clean_title'] ?? $row['type'] ?? 'Vulnerability'));
        $affected = (string) ($row['affected_in'] ?? '');
        $fixed = (string) ($row['fixed_in'] ?? '');
        if ($fixed === '' || ! preg_match('/^\d/', $fixed)) {
            $fixed = null;
        }

        $productSlug = (string) ($row['product_slug'] ?? $slug);
        if ($component === 'core') {
            $productSlug = 'wordpress';
        }

        $urlSlug = (string) ($row['url'] ?? $row['slug'] ?? $external);
        $publicUrl = $urlSlug !== ''
            ? 'https://patchstack.com/database/wordpress/'.($component === 'core' ? 'core' : $component).'/'.rawurlencode($productSlug).'/vulnerability/'.rawurlencode($urlSlug)
            : null;

        return [
            'external_id' => $external ?: $productSlug.'-'.md5(json_encode($row)),
            'slug' => $productSlug,
            'component_type' => $component,
            'title' => $title,
            'description' => (string) ($row['clean_title'] ?? $row['type'] ?? $title),
            'severity' => $this->severityFromCvss($cvss, ! empty($row['is_exploited'])),
            'cvss' => $cvss,
            'patch_priority' => isset($row['patch_priority']) ? (int) $row['patch_priority'] : null,
            'is_exploited' => (bool) ($row['is_exploited'] ?? false),
            'affected_versions' => $affected !== '' ? $affected : null,
            'fixed_in' => $fixed,
            'cve' => $row['cve'] ?? null,
            'url' => $publicUrl,
            'disclosed_at' => $row['disclosure_date'] ?? null,
            'raw' => $row,
        ];
    }

    private function mapApiItem(array $row, ?string $fallbackSlug = null): ?array
    {
        $name = (string) ($row['name'] ?? $row['product_slug'] ?? $fallbackSlug ?? '');
        $type = strtolower((string) ($row['type'] ?? $row['product_type'] ?? 'plugin'));
        $component = match (true) {
            str_contains($type, 'theme') => 'theme',
            str_contains($type, 'wordpress') || str_contains($type, 'core') => 'core',
            default => 'plugin',
        };
        $cvss = isset($row['cvss_score']) ? (float) $row['cvss_score'] : (isset($row['cvss']) ? (float) $row['cvss'] : null);
        $external = (string) ($row['id'] ?? $row['vulnerability_id'] ?? $row['slug'] ?? '');
        if ($external === '') {
            $external = ($name ?: 'unknown').'-'.substr(md5(json_encode($row)), 0, 12);
        }

        $affected = $row['affected_in'] ?? $row['affected_versions'] ?? null;
        if (is_array($affected)) {
            $affected = implode(', ', $affected);
        }
        $fixed = $row['patched_in'] ?? $row['fixed_in'] ?? null;
        if (is_array($fixed)) {
            $fixed = $fixed[0] ?? null;
        }

        return [
            'external_id' => (string) $external,
            'slug' => $component === 'core' ? 'wordpress' : ($name ?: 'unknown'),
            'component_type' => $component,
            'title' => (string) ($row['title'] ?? $row['description'] ?? 'Patchstack advisory'),
            'description' => (string) ($row['description'] ?? $row['title'] ?? ''),
            'severity' => $this->severityFromCvss($cvss, ! empty($row['is_exploited'])),
            'cvss' => $cvss,
            'patch_priority' => isset($row['patch_priority']) ? (int) $row['patch_priority'] : null,
            'is_exploited' => (bool) ($row['is_exploited'] ?? false),
            'affected_versions' => $affected ? (string) $affected : null,
            'fixed_in' => $fixed ? (string) $fixed : null,
            'cve' => $row['cve'] ?? null,
            'url' => $row['direct_url'] ?? null,
            'disclosed_at' => $row['disclosure_date'] ?? $row['published_at'] ?? null,
            'raw' => $row,
        ];
    }

    public function severityFromCvss(?float $cvss, bool $exploited = false): string
    {
        if ($exploited && ($cvss === null || $cvss >= 7.0)) {
            return 'critical';
        }
        if ($cvss === null) {
            return 'medium';
        }
        if ($cvss >= 9.0) {
            return 'critical';
        }
        if ($cvss >= 7.0) {
            return 'high';
        }
        if ($cvss >= 4.0) {
            return 'medium';
        }

        return 'low';
    }

    public function priorityScore(array $item): int
    {
        $score = 0;
        $severity = $item['severity'] ?? 'medium';
        $score += match ($severity) {
            'critical' => 1000,
            'high' => 700,
            'medium' => 400,
            default => 100,
        };
        $cvss = isset($item['cvss']) ? (float) $item['cvss'] : 0.0;
        $score += (int) round($cvss * 10);
        if (! empty($item['is_exploited'])) {
            $score += 500;
        }
        $pp = isset($item['patch_priority']) ? (int) $item['patch_priority'] : 0;
        $score += $pp * 25;
        if (! empty($item['fixed_in'])) {
            $score += 50; // actionable
        }

        return $score;
    }
}

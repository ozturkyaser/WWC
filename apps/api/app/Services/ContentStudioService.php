<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * KI-Wartung: eine Site, zwei Ziele.
 * Live = gepaarter Agent (HMAC bleibt nur auf der Kundenseite).
 * Isolierte Kopie = wp-cli auf dem WWC-Server, ohne Pairing-Key.
 */
class ContentStudioService
{
    public function __construct(
        private DevCloneService $clones,
        private AgentDispatcher $dispatcher,
        private AlertService $alerts,
    ) {}

    public function payload(Site $site): array
    {
        $studio = $site->content_studio ?? [];
        $intel = $studio['intel'] ?? null;
        $target = $this->storedTarget($site);

        return [
            'intel' => $intel,
            'intel_source' => $studio['intel_source'] ?? null,
            'scanned_at' => $studio['scanned_at'] ?? null,
            'draft' => $studio['draft'] ?? null,
            'history' => array_slice($studio['history'] ?? [], 0, 10),
            'target' => $target,
            'live_paired' => (bool) $site->getHmacSecret(),
            'clone_ready' => $this->clones->isReady($site),
            'clone_url' => $site->dev_clone['url'] ?? null,
            'scan_status' => $studio['scan_status'] ?? (is_array($intel) ? 'ready' : 'idle'),
            'scan_job_id' => $studio['scan_job_id'] ?? null,
            'pairing_note' => 'Die isolierte Kopie teilt denselben Site-Datensatz, nicht den Live-Pairing-Key. Live läuft über den gepaarten Agenten.',
        ];
    }

    public function setTarget(Site $site, string $target): array
    {
        $target = $this->normalizeTarget($target);
        $this->assertTargetAvailable($site, $target);
        $this->patchStudio($site, ['target' => $target]);

        return $this->payload($site->fresh() ?? $site);
    }

    /**
     * Auftrag planen und sofort auf dem gewählten Ziel umsetzen.
     */
    public function run(Site $site, string $prompt, ?string $target = null, bool $confirmLive = false): array
    {
        $target = $this->resolveTarget($site, $target);
        $this->assertTargetAvailable($site, $target);
        $this->patchStudio($site, ['target' => $target]);
        $site = $site->fresh() ?? $site;

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Bitte beschreiben, was geändert werden soll.');
        }

        $this->ensureIntel($site, $target);
        $site = $site->fresh() ?? $site;

        $this->plan($site, $prompt, $target);
        $site = $site->fresh() ?? $site;

        if ($target === 'clone') {
            return $this->applyDev($site);
        }

        if (! $confirmLive) {
            throw new RuntimeException('Live-Änderungen brauchen eine ausdrückliche Bestätigung.');
        }

        return $this->applyLive($site);
    }

    /** @deprecated Use run() */
    public function runOnDev(Site $site, string $prompt): array
    {
        return $this->run($site, $prompt, 'clone');
    }

    public function scan(Site $site, ?string $target = null): array
    {
        $target = $this->resolveTarget($site, $target);
        $this->assertTargetAvailable($site, $target);
        $this->patchStudio($site, ['target' => $target]);
        $site = $site->fresh() ?? $site;

        if ($target === 'clone') {
            $intel = $this->clones->scanClone($site);
            $this->storeIntel($site, $intel, 'clone');
            $this->patchStudio($site->fresh() ?? $site, [
                'scan_status' => 'ready',
                'scan_job_id' => null,
            ]);

            return $this->payload($site->fresh() ?? $site);
        }

        $job = $this->dispatcher->dispatch($site, 'site_scan');
        $this->patchStudio($site, [
            'scan_status' => 'pending',
            'scan_job_id' => $job->id,
            'target' => 'live',
        ]);

        return $this->payload($site->fresh() ?? $site);
    }

    public function rememberScanResult(Site $site, array $result): void
    {
        if (empty($result['ok']) || empty($result['site'])) {
            $this->patchStudio($site, [
                'scan_status' => 'failed',
            ]);

            return;
        }
        $this->storeIntel($site, $result, 'live');
        $this->patchStudio($site->fresh() ?? $site, [
            'scan_status' => 'ready',
            'scan_job_id' => null,
        ]);
    }

    /**
     * @return array{summary:string,ops:list<array<string,mixed>>}
     */
    public function plan(Site $site, string $prompt, ?string $target = null): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Bitte beschreiben, was geändert werden soll.');
        }
        $target = $this->resolveTarget($site, $target);
        $intel = ($site->content_studio['intel'] ?? null);
        if (! is_array($intel) || ($site->content_studio['intel_source'] ?? '') !== $target) {
            throw new RuntimeException('Zuerst die Website vollständig scannen (aktuelles Ziel: '.($target === 'live' ? 'Live' : 'isolierte Kopie').').');
        }

        $planned = $this->askAi($site, $intel, $prompt, $target);
        $ops = $this->sanitizeOps($planned['ops'] ?? [], $target);
        if ($ops === []) {
            throw new RuntimeException('Die KI hat keine umsetzbaren Änderungen erzeugt.');
        }

        $draft = [
            'prompt' => $prompt,
            'summary' => $planned['summary'] ?? 'Geplante Änderungen.',
            'ops' => $ops,
            'status' => 'planned',
            'target' => $target,
            'dev_results' => null,
            'live_results' => null,
            'error' => null,
            'planned_at' => now()->toIso8601String(),
        ];
        $this->patchStudio($site, ['draft' => $draft, 'target' => $target]);

        return $draft;
    }

    public function applyDev(Site $site): array
    {
        if (! $this->clones->isReady($site)) {
            throw new RuntimeException('Zuerst die isolierte Umgebung auf dem WWC-Server erstellen.');
        }
        $draft = $site->content_studio['draft'] ?? null;
        if (! is_array($draft) || ($draft['ops'] ?? []) === []) {
            throw new RuntimeException('Kein Änderungsplan vorhanden.');
        }

        $result = $this->clones->applyOnClone($site, $draft['ops']);
        $ok = (bool) ($result['ok'] ?? false);
        $draft['dev_results'] = $this->publicizeResults($site, $result['results'] ?? []);
        $draft['status'] = $ok ? 'applied_dev' : 'failed';
        $draft['target'] = 'clone';
        $draft['error'] = $ok
            ? null
            : ($this->firstResultError($draft['dev_results']) ?? 'Mindestens eine Änderung in der isolierten Kopie ist fehlgeschlagen.');
        $draft['applied_dev_at'] = now()->toIso8601String();
        $this->patchStudio($site, ['draft' => $draft, 'target' => 'clone']);
        $this->rememberHistory($site->fresh() ?? $site, $draft);

        if ($ok) {
            try {
                $intel = $this->clones->scanClone($site);
                $this->storeIntel($site->fresh() ?? $site, $intel, 'clone');
            } catch (\Throwable $e) {
                Log::info('content studio rescan skipped', ['error' => $e->getMessage()]);
            }
        }

        return $this->payload($site->fresh() ?? $site);
    }

    public function applyLive(Site $site): array
    {
        if (! $site->getHmacSecret()) {
            throw new RuntimeException('Live-Site ist nicht verbunden.');
        }
        $draft = $site->content_studio['draft'] ?? null;
        if (! is_array($draft) || ($draft['ops'] ?? []) === []) {
            throw new RuntimeException('Kein Änderungsplan vorhanden.');
        }

        $ops = array_values(array_filter(
            $draft['ops'],
            static fn ($op) => is_array($op) && ($op['op'] ?? '') !== 'update_theme_file'
        ));
        if ($ops === []) {
            throw new RuntimeException('Theme-Dateien nur in der isolierten Kopie ändern, nicht live.');
        }

        $job = $this->dispatcher->dispatch($site, 'content_apply', ['ops' => $ops]);
        $draft['status'] = 'promoting';
        $draft['target'] = 'live';
        $draft['live_job_id'] = $job->id;
        $this->patchStudio($site, ['draft' => $draft, 'target' => 'live']);

        return $this->payload($site->fresh() ?? $site);
    }

    public function promoteLive(Site $site): array
    {
        $draft = $site->content_studio['draft'] ?? null;
        if (! is_array($draft) || ($draft['status'] ?? '') !== 'applied_dev') {
            throw new RuntimeException('Änderungen zuerst in der isolierten Kopie anwenden und prüfen.');
        }

        return $this->applyLive($site);
    }

    public function rememberApplyResult(Site $site, array $result, bool $ok): void
    {
        $studio = $site->content_studio ?? [];
        $draft = $studio['draft'] ?? [];
        $draft['live_results'] = $result['results'] ?? $result;
        $draft['status'] = $ok ? 'promoted' : 'failed';
        $draft['error'] = $ok ? null : (string) ($result['error'] ?? 'Live-Übernahme fehlgeschlagen');
        $draft['promoted_at'] = now()->toIso8601String();
        $this->patchStudio($site, ['draft' => $draft]);

        $org = $site->organization;
        if ($org && $ok) {
            $this->alerts->notify(
                $org,
                'content_promoted',
                'Inhalte live übernommen: '.$site->name,
                [
                    $draft['summary'] ?? 'Geplante Änderungen sind live.',
                    'Ziel: Live-Site über den gepaarten Agenten.',
                ],
                '/sites/'.$site->id.'?tab=editor',
                'info',
                'content_promoted:'.$site->id.':'.($draft['promoted_at'] ?? now()->toIso8601String()),
                $site,
                5
            );
        }
    }

    public function storeUpload(Site $site, UploadedFile $file): array
    {
        $dir = storage_path('app/wwc-content/'.$site->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $file->getClientOriginalName()) ?: 'upload.bin';
        $path = $file->storeAs('wwc-content/'.$site->id, $name);
        $absolute = storage_path('app/'.$path);
        $clonePath = null;
        if ($this->clones->isReady($site)) {
            $clonePath = $this->clones->placeCloneUpload($site, $absolute, $name);
        }

        $stored = [
            'filename' => $name,
            'storage' => $absolute,
            'clone_path' => $clonePath,
        ];
        if ($clonePath) {
            $this->patchStudio($site, [
                'draft' => [
                    'prompt' => 'Logo ersetzen: '.$name,
                    'summary' => 'Logo in der isolierten Kopie durch die hochgeladene Datei ersetzen.',
                    'ops' => [['op' => 'set_logo', 'path' => $clonePath, 'title' => $name]],
                    'status' => 'planned',
                    'target' => 'clone',
                    'dev_results' => null,
                    'live_results' => null,
                    'error' => null,
                    'planned_at' => now()->toIso8601String(),
                ],
            ]);
        }

        return $stored;
    }

    private function ensureIntel(Site $site, string $target): void
    {
        $studio = $site->content_studio ?? [];
        $intel = $studio['intel'] ?? null;
        $source = (string) ($studio['intel_source'] ?? '');
        if (is_array($intel) && $source === $target && ($studio['scan_status'] ?? 'ready') !== 'pending') {
            return;
        }

        if ($target === 'live') {
            if (($studio['scan_status'] ?? '') === 'pending') {
                throw new RuntimeException('Live-Scan läuft noch. Sobald der Agent fertig ist, den Auftrag erneut senden.');
            }
            $this->scan($site, 'live');
            throw new RuntimeException('Live-Scan gestartet. Der Agent erfasst Theme, Plugins und Inhalte – danach den Auftrag erneut senden.');
        }

        $this->scan($site, 'clone');
    }

    private function resolveTarget(Site $site, ?string $requested): string
    {
        if (is_string($requested) && trim($requested) !== '') {
            return $this->normalizeTarget($requested);
        }

        return $this->storedTarget($site);
    }

    private function storedTarget(Site $site): string
    {
        $stored = (string) ($site->content_studio['target'] ?? '');
        if (in_array($stored, ['live', 'clone'], true)) {
            return $stored;
        }

        return $this->clones->isReady($site) ? 'clone' : 'live';
    }

    private function normalizeTarget(string $target): string
    {
        $target = strtolower(trim($target));
        if ($target === 'dev') {
            $target = 'clone';
        }
        if (! in_array($target, ['live', 'clone'], true)) {
            throw new RuntimeException('Ziel muss live oder clone sein.');
        }

        return $target;
    }

    private function assertTargetAvailable(Site $site, string $target): void
    {
        if ($target === 'clone' && ! $this->clones->isReady($site)) {
            throw new RuntimeException('Zuerst die isolierte Umgebung auf dem WWC-Server erstellen. Die KI arbeitet dort, nicht mit dem Live-Pairing-Key.');
        }
        if ($target === 'live' && ! $site->getHmacSecret()) {
            throw new RuntimeException('Live-Site ist nicht verbunden. Pairing im Tab Verbindung herstellen.');
        }
    }

    /** @param array<string, mixed> $intel */
    private function storeIntel(Site $site, array $intel, string $source): void
    {
        $this->patchStudio($site, [
            'intel' => $intel,
            'intel_source' => $source,
            'scanned_at' => $intel['scanned_at'] ?? now()->toIso8601String(),
            'scan_status' => 'ready',
        ]);
    }

    /** @param array<string, mixed> $patch */
    private function patchStudio(Site $site, array $patch): void
    {
        $site->update(['content_studio' => array_merge($site->fresh()->content_studio ?? [], $patch)]);
    }

    /**
     * @return array{summary:string,ops:list<array<string,mixed>>}
     */
    private function askAi(Site $site, array $intel, string $prompt, string $target): array
    {
        $key = config('wwc.ai_api_key');
        $compact = [
            'target' => $target,
            'site' => $intel['site'] ?? [],
            'theme' => $intel['theme'] ?? [],
            'editors' => $intel['editors'] ?? [],
            'branding' => $intel['branding'] ?? [],
            'homepage' => $intel['homepage'] ?? [],
            'permalinks' => $intel['permalinks'] ?? '',
            'pages' => array_slice($intel['pages'] ?? [], 0, 50),
            'posts' => array_slice($intel['posts'] ?? [], 0, 20),
            'menus' => $intel['menus'] ?? [],
            'widgets' => $intel['widgets'] ?? [],
            'custom_css' => mb_substr((string) ($intel['custom_css'] ?? ''), 0, 1500),
            'theme_files' => array_slice($intel['theme_files'] ?? [], 0, 40),
            'plugins' => collect($intel['plugins'] ?? [])
                ->map(fn ($p) => [
                    'name' => $p['name'] ?? '',
                    'slug' => $p['slug'] ?? '',
                    'active' => (bool) ($p['active'] ?? false),
                    'version' => $p['version'] ?? '',
                ])
                ->take(60)
                ->values()
                ->all(),
        ];

        if (! $key) {
            return $this->heuristicPlan($compact, $prompt, $target);
        }

        $themeFilesNote = $target === 'clone'
            ? 'update_theme_file (path relativ zum aktiven Theme, content) nur in der isolierten Kopie.'
            : 'update_theme_file nicht verwenden (nur isolierte Kopie).';

        try {
            $res = Http::timeout(45)
                ->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Du planst WordPress-Wartung für eine Agentur. '
                                .'Antworte NUR mit JSON: {"summary":"Deutsch, 2 Sätze","ops":[...]}. '
                                .'Erlaubte ops: create_post (type page|post, title, content, status, excerpt), '
                                .'update_post (id, title?, content?, status?, excerpt?), '
                                .'set_option (key in blogname|blogdescription|show_on_front|page_on_front|page_for_posts|posts_per_page, value), '
                                .'set_logo (path oder media_id), '
                                .'plugin_activate/plugin_deactivate/plugin_update (slug), '
                                .'theme_update (slug), set_custom_css (css), '
                                .$themeFilesNote.' '
                                .'Kein Markdown. Page-Builder-Seiten (editor elementor/wpbakery/builder) nicht per content überschreiben – neue Gutenberg-Seite anlegen. '
                                .'Ziel ist '.$target.'. content als HTML oder Gutenberg-Blöcke.',
                        ],
                        [
                            'role' => 'user',
                            'content' => Str::limit(json_encode([
                                'auftrag' => $prompt,
                                'sitemap' => $compact,
                            ], JSON_UNESCAPED_UNICODE), 16000),
                        ],
                    ],
                ]);
            $content = (string) data_get($res->json(), 'choices.0.message.content');
            if (preg_match('/\{.*\}/s', $content, $m) === 1) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed) && isset($parsed['ops'])) {
                    return [
                        'summary' => (string) ($parsed['summary'] ?? 'Geplante Änderungen'),
                        'ops' => is_array($parsed['ops']) ? $parsed['ops'] : [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::info('content studio AI failed', ['error' => $e->getMessage()]);
        }

        return $this->heuristicPlan($compact, $prompt, $target);
    }

    /**
     * @param  array<string, mixed>  $intel
     * @return array{summary:string,ops:list<array<string,mixed>>}
     */
    private function heuristicPlan(array $intel, string $prompt, string $target): array
    {
        $lower = mb_strtolower($prompt);
        $where = $target === 'live' ? 'auf der Live-Site' : 'in der isolierten Kopie';

        if (str_contains($lower, 'deaktiv') && str_contains($lower, 'plugin')) {
            $slug = $this->guessPluginSlug($intel, $prompt);
            if ($slug) {
                return [
                    'summary' => 'Plugin deaktivieren '.$where.'.',
                    'ops' => [['op' => 'plugin_deactivate', 'slug' => $slug]],
                ];
            }
        }
        if ((str_contains($lower, 'aktiv') || str_contains($lower, 'einschalt')) && str_contains($lower, 'plugin')) {
            $slug = $this->guessPluginSlug($intel, $prompt);
            if ($slug) {
                return [
                    'summary' => 'Plugin aktivieren '.$where.'.',
                    'ops' => [['op' => 'plugin_activate', 'slug' => $slug]],
                ];
            }
        }
        if (str_contains($lower, 'css') || str_contains($lower, 'stylesheet')) {
            return [
                'summary' => 'Zusätzliches Custom-CSS '.$where.' setzen.',
                'ops' => [['op' => 'set_custom_css', 'css' => "/* WWC */\n".$prompt]],
            ];
        }
        if (str_contains($lower, 'blog') || str_contains($lower, 'beitrag')) {
            return [
                'summary' => 'Neuer Blogbeitrag '.$where.'.',
                'ops' => [[
                    'op' => 'create_post',
                    'type' => 'post',
                    'status' => 'publish',
                    'title' => Str::limit($prompt, 80, ''),
                    'content' => '<p>'.e($prompt).'</p>',
                ]],
            ];
        }
        if (str_contains($lower, 'landing') || str_contains($lower, 'seite')) {
            return [
                'summary' => 'Neue Gutenberg-Seite '.$where.' anlegen.',
                'ops' => [[
                    'op' => 'create_post',
                    'type' => 'page',
                    'status' => 'publish',
                    'title' => Str::limit($prompt, 80, ''),
                    'content' => '<h1>'.e(Str::limit($prompt, 60, '')).'</h1><p>Bitte prüfen.</p>',
                ]],
            ];
        }

        return [
            'summary' => 'Untertitel der Site '.$where.' anpassen.',
            'ops' => [[
                'op' => 'set_option',
                'key' => 'blogdescription',
                'value' => Str::limit($prompt, 120, ''),
            ]],
        ];
    }

    /** @param array<string, mixed> $intel */
    private function guessPluginSlug(array $intel, string $prompt): ?string
    {
        $lower = mb_strtolower($prompt);
        foreach ($intel['plugins'] ?? [] as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            $name = mb_strtolower((string) ($plugin['name'] ?? ''));
            if ($slug !== '' && (($name !== '' && str_contains($lower, $name)) || str_contains($lower, mb_strtolower($slug)))) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $ops
     * @return list<array<string, mixed>>
     */
    public function sanitizeOps(array $ops, string $target = 'clone'): array
    {
        $clean = [];
        foreach ($ops as $op) {
            if (! is_array($op)) {
                continue;
            }
            $name = (string) ($op['op'] ?? '');
            $row = match ($name) {
                'create_post' => [
                    'op' => 'create_post',
                    'type' => in_array($op['type'] ?? 'page', ['page', 'post'], true) ? ($op['type'] ?? 'page') : 'page',
                    'title' => mb_substr(trim((string) ($op['title'] ?? '')), 0, 200),
                    'content' => (string) ($op['content'] ?? ''),
                    'excerpt' => mb_substr((string) ($op['excerpt'] ?? ''), 0, 500),
                    'status' => in_array($op['status'] ?? 'publish', ['publish', 'draft', 'private'], true) ? ($op['status'] ?? 'publish') : 'publish',
                    'parent' => (int) ($op['parent'] ?? 0),
                ],
                'update_post' => [
                    'op' => 'update_post',
                    'id' => (int) ($op['id'] ?? 0),
                    'title' => isset($op['title']) ? mb_substr((string) $op['title'], 0, 200) : null,
                    'content' => $op['content'] ?? null,
                    'excerpt' => isset($op['excerpt']) ? mb_substr((string) $op['excerpt'], 0, 500) : null,
                    'status' => isset($op['status']) && in_array($op['status'], ['publish', 'draft', 'private'], true) ? $op['status'] : null,
                ],
                'set_option' => [
                    'op' => 'set_option',
                    'key' => (string) ($op['key'] ?? ''),
                    'value' => $op['value'] ?? '',
                ],
                'set_logo' => [
                    'op' => 'set_logo',
                    'media_id' => (int) ($op['media_id'] ?? 0),
                    'path' => isset($op['path']) ? (string) $op['path'] : null,
                    'title' => (string) ($op['title'] ?? 'Logo'),
                ],
                'upload_media' => [
                    'op' => 'upload_media',
                    'filename' => basename((string) ($op['filename'] ?? 'upload.bin')),
                    'base64' => (string) ($op['base64'] ?? ''),
                    'title' => (string) ($op['title'] ?? ''),
                ],
                'plugin_activate', 'plugin_deactivate', 'plugin_update' => [
                    'op' => $name,
                    'slug' => trim((string) ($op['slug'] ?? '')),
                ],
                'theme_update' => [
                    'op' => 'theme_update',
                    'slug' => trim((string) ($op['slug'] ?? '')),
                ],
                'set_custom_css' => [
                    'op' => 'set_custom_css',
                    'css' => mb_substr((string) ($op['css'] ?? $op['value'] ?? ''), 0, 80000),
                ],
                'update_theme_file' => $target === 'clone' ? [
                    'op' => 'update_theme_file',
                    'path' => ltrim(str_replace('\\', '/', (string) ($op['path'] ?? '')), '/'),
                    'content' => (string) ($op['content'] ?? ''),
                ] : null,
                default => null,
            };
            if ($row === null) {
                continue;
            }
            if ($name === 'create_post' && $row['title'] === '') {
                continue;
            }
            if ($name === 'update_post' && $row['id'] <= 0) {
                continue;
            }
            if ($name === 'set_option' && ! in_array($row['key'], ['blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page'], true)) {
                continue;
            }
            if (in_array($name, ['plugin_activate', 'plugin_deactivate', 'plugin_update', 'theme_update'], true) && ($row['slug'] ?? '') === '') {
                continue;
            }
            if ($name === 'update_theme_file') {
                $path = (string) ($row['path'] ?? '');
                if ($path === '' || str_contains($path, '..')) {
                    continue;
                }
            }
            $clean[] = array_filter($row, static fn ($v) => $v !== null);
        }

        return array_slice($clean, 0, 20);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    public function publicizeResults(Site $site, array $results): array
    {
        $out = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! empty($row['url'])) {
                $row['url'] = $this->publicizeUrl($site, (string) $row['url']);
            }
            $out[] = $row;
        }

        return $out;
    }

    public function publicizeUrl(Site $site, string $url): string
    {
        $clone = rtrim((string) ($site->dev_clone['url'] ?? ''), '/');
        if ($clone === '' || $url === '') {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = '/';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        $clonePath = parse_url($clone, PHP_URL_PATH);
        $clonePath = is_string($clonePath) ? rtrim($clonePath, '/') : '';
        if ($clonePath !== '' && str_starts_with($path, $clonePath)) {
            $path = substr($path, strlen($clonePath)) ?: '/';
        }

        return $clone.$path.($query ? '?'.$query : '');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function rememberHistory(Site $site, array $draft): void
    {
        $history = $site->content_studio['history'] ?? [];
        if (! is_array($history)) {
            $history = [];
        }
        array_unshift($history, [
            'prompt' => $draft['prompt'] ?? '',
            'summary' => $draft['summary'] ?? '',
            'status' => $draft['status'] ?? '',
            'target' => $draft['target'] ?? null,
            'dev_results' => $draft['dev_results'] ?? [],
            'error' => $draft['error'] ?? null,
            'at' => $draft['applied_dev_at'] ?? $draft['promoted_at'] ?? now()->toIso8601String(),
        ]);
        $this->patchStudio($site, ['history' => array_slice($history, 0, 10)]);
    }

    /** @param  list<array<string, mixed>>  $results */
    private function firstResultError(array $results): ?string
    {
        foreach ($results as $row) {
            if (empty($row['ok']) && ! empty($row['error'])) {
                return (string) $row['error'];
            }
        }

        return null;
    }
}

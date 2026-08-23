<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * KI-Content-Editor: Site verstehen, Aenderungen zuerst in der Dev-Kopie,
 * nach Freigabe dieselben Operationen live anwenden.
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

        return [
            'intel' => $studio['intel'] ?? null,
            'intel_source' => $studio['intel_source'] ?? null,
            'scanned_at' => $studio['scanned_at'] ?? null,
            'draft' => $studio['draft'] ?? null,
            'clone_ready' => $this->clones->isReady($site),
            'clone_url' => $site->dev_clone['url'] ?? null,
        ];
    }

    public function scan(Site $site): array
    {
        if ($this->clones->isReady($site)) {
            $intel = $this->clones->scanClone($site);
            $this->storeIntel($site, $intel, 'clone');

            return $this->payload($site->fresh() ?? $site);
        }

        if (! $site->getHmacSecret()) {
            throw new RuntimeException('Weder Dev-Kopie noch gekoppelter Agent verfügbar.');
        }

        $job = $this->dispatcher->dispatch($site, 'site_scan');
        $studio = $site->content_studio ?? [];
        $studio['draft'] = array_merge($studio['draft'] ?? [], [
            'status' => 'scanning',
            'job_id' => $job->id,
        ]);
        $site->update(['content_studio' => $studio]);

        return $this->payload($site->fresh() ?? $site);
    }

    public function rememberScanResult(Site $site, array $result): void
    {
        if (empty($result['ok']) || empty($result['site'])) {
            return;
        }
        $this->storeIntel($site, $result, 'live');
    }

    /**
     * @return array{summary:string,ops:list<array<string,mixed>>}
     */
    public function plan(Site $site, string $prompt): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Bitte beschreiben, was geändert werden soll.');
        }
        $intel = ($site->content_studio['intel'] ?? null);
        if (! is_array($intel)) {
            throw new RuntimeException('Zuerst die Website scannen.');
        }

        $planned = $this->askAi($site, $intel, $prompt);
        $ops = $this->sanitizeOps($planned['ops'] ?? []);
        if ($ops === []) {
            throw new RuntimeException('Die KI hat keine umsetzbaren Änderungen erzeugt.');
        }

        $draft = [
            'prompt' => $prompt,
            'summary' => $planned['summary'] ?? 'Geplante Änderungen an der Dev-Umgebung.',
            'ops' => $ops,
            'status' => 'planned',
            'dev_results' => null,
            'live_results' => null,
            'error' => null,
            'planned_at' => now()->toIso8601String(),
        ];
        $this->patchStudio($site, ['draft' => $draft]);

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
        $draft['dev_results'] = $result['results'] ?? [];
        $draft['status'] = $ok ? 'applied_dev' : 'failed';
        $draft['error'] = $ok ? null : 'Mindestens eine Änderung in der Dev-Kopie ist fehlgeschlagen.';
        $draft['applied_dev_at'] = now()->toIso8601String();
        $this->patchStudio($site, ['draft' => $draft]);

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

    public function promoteLive(Site $site): array
    {
        $draft = $site->content_studio['draft'] ?? null;
        if (! is_array($draft) || ($draft['status'] ?? '') !== 'applied_dev') {
            throw new RuntimeException('Änderungen zuerst in der Dev-Umgebung anwenden und prüfen.');
        }
        if (! $site->getHmacSecret()) {
            throw new RuntimeException('Live-Site ist nicht verbunden.');
        }

        $job = $this->dispatcher->dispatch($site, 'content_apply', ['ops' => $draft['ops']]);
        $draft['status'] = 'promoting';
        $draft['live_job_id'] = $job->id;
        $this->patchStudio($site, ['draft' => $draft]);

        return $this->payload($site->fresh() ?? $site);
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
                    'Zuvor in der isolierten Umgebung geprüft.',
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
                    'summary' => 'Logo in der Dev-Umgebung durch die hochgeladene Datei ersetzen.',
                    'ops' => [['op' => 'set_logo', 'path' => $clonePath, 'title' => $name]],
                    'status' => 'planned',
                    'dev_results' => null,
                    'live_results' => null,
                    'error' => null,
                    'planned_at' => now()->toIso8601String(),
                ],
            ]);
        }

        return $stored;
    }

    /** @param array<string, mixed> $intel */
    private function storeIntel(Site $site, array $intel, string $source): void
    {
        $this->patchStudio($site, [
            'intel' => $intel,
            'intel_source' => $source,
            'scanned_at' => $intel['scanned_at'] ?? now()->toIso8601String(),
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
    private function askAi(Site $site, array $intel, string $prompt): array
    {
        $key = config('wwc.ai_api_key');
        $compact = [
            'site' => $intel['site'] ?? [],
            'theme' => $intel['theme'] ?? [],
            'editors' => $intel['editors'] ?? [],
            'branding' => $intel['branding'] ?? [],
            'homepage' => $intel['homepage'] ?? [],
            'pages' => array_slice($intel['pages'] ?? [], 0, 40),
            'posts' => array_slice($intel['posts'] ?? [], 0, 20),
            'menus' => $intel['menus'] ?? [],
            'plugins_active' => collect($intel['plugins'] ?? [])
                ->where('active', true)
                ->map(fn ($p) => ($p['name'] ?? '').' ('.$p['slug'].')')
                ->take(40)
                ->values()
                ->all(),
        ];

        if (! $key) {
            return $this->heuristicPlan($compact, $prompt);
        }

        try {
            $res = Http::timeout(40)
                ->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Du planst WordPress-Inhaltsänderungen für eine Agentur. '
                                .'Antworte NUR mit JSON: {"summary":"Deutsch, 2 Sätze","ops":[...]}. '
                                .'Erlaubte ops: create_post (type page|post, title, content, status, excerpt), '
                                .'update_post (id, title?, content?, status?, excerpt?), '
                                .'set_option (key in blogname|blogdescription|show_on_front|page_on_front|page_for_posts|posts_per_page, value), '
                                .'set_logo (path oder media_id). '
                                .'Kein Markdown. Page-Builder-Seiten (editor elementor/wpbakery/builder) nicht per content überschreiben – neue Gutenberg-Seite anlegen. '
                                .'content als HTML oder Gutenberg-Blöcke. Zuerst in Dev, nie direkt live.',
                        ],
                        [
                            'role' => 'user',
                            'content' => Str::limit(json_encode([
                                'auftrag' => $prompt,
                                'sitemap' => $compact,
                            ], JSON_UNESCAPED_UNICODE), 14000),
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

        return $this->heuristicPlan($compact, $prompt);
    }

    /**
     * @param  array<string, mixed>  $intel
     * @return array{summary:string,ops:list<array<string,mixed>>}
     */
    private function heuristicPlan(array $intel, string $prompt): array
    {
        $lower = mb_strtolower($prompt);
        if (str_contains($lower, 'blog') || str_contains($lower, 'beitrag')) {
            return [
                'summary' => 'Neuer Blogbeitrag als Entwurf in der Dev-Umgebung.',
                'ops' => [[
                    'op' => 'create_post',
                    'type' => 'post',
                    'status' => 'draft',
                    'title' => Str::limit($prompt, 80, ''),
                    'content' => '<p>'.e($prompt).'</p>',
                ]],
            ];
        }
        if (str_contains($lower, 'landing') || str_contains($lower, 'seite')) {
            return [
                'summary' => 'Neue Gutenberg-Seite in der Dev-Umgebung anlegen.',
                'ops' => [[
                    'op' => 'create_post',
                    'type' => 'page',
                    'status' => 'draft',
                    'title' => Str::limit($prompt, 80, ''),
                    'content' => '<h1>'.e(Str::limit($prompt, 60, '')).'</h1><p>Entwurf – bitte in der Dev-Umgebung prüfen.</p>',
                ]],
            ];
        }

        return [
            'summary' => 'Untertitel der Site in der Dev-Umgebung anpassen.',
            'ops' => [[
                'op' => 'set_option',
                'key' => 'blogdescription',
                'value' => Str::limit($prompt, 120, ''),
            ]],
        ];
    }

    /**
     * @param  list<mixed>  $ops
     * @return list<array<string, mixed>>
     */
    public function sanitizeOps(array $ops): array
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
                    'status' => in_array($op['status'] ?? 'draft', ['publish', 'draft', 'private'], true) ? ($op['status'] ?? 'draft') : 'draft',
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
            $clean[] = array_filter($row, static fn ($v) => $v !== null);
        }

        return array_slice($clean, 0, 20);
    }
}

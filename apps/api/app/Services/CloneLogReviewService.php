<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Prueft nach einem Dry-Run die Logs der isolierten Dev-Kopie.
 * Bei sauberem Lauf kommt eine Info-Benachrichtigung.
 */
class CloneLogReviewService
{
    public function __construct(private AlertService $alerts) {}

    /**
     * @param  array{ok?:bool,items?:list<array>,health_error?:string|null}  $report
     * @return array{ok:bool,summary:string,findings:list<string>,reviewed_at:string,source:string}
     */
    public function reviewAndNotify(Site $site, array $report, string $logs): array
    {
        $logsClean = $this->logsLookClean($logs);
        $dryRunOk = (bool) ($report['ok'] ?? false);
        $ai = $this->askAi($site, $report, $logs);

        $ok = $dryRunOk && $logsClean && (($ai['ok'] ?? true) === true);
        $findings = $ai['findings'] ?? $this->heuristicFindings($logs);
        if (! $logsClean && $findings === []) {
            $findings = ['In den Logs stehen PHP- oder Datenbankfehler.'];
        }
        if (! $dryRunOk && ($report['health_error'] ?? null)) {
            $findings[] = (string) $report['health_error'];
        }

        $summary = is_string($ai['summary'] ?? null) && $ai['summary'] !== ''
            ? $ai['summary']
            : $this->heuristicSummary($site, $ok, $report, $findings);

        $review = [
            'ok' => $ok,
            'summary' => $summary,
            'findings' => array_values(array_unique(array_filter($findings))),
            'reviewed_at' => now()->toIso8601String(),
            'source' => $ai === null ? 'heuristic' : 'ai',
        ];

        $this->notify($site, $review);

        return $review;
    }

    public function logsLookClean(string $logs): bool
    {
        if (trim($logs) === '') {
            return true;
        }

        return preg_match(
            '/PHP (Fatal|Parse) error|Uncaught (Error|Exception|TypeError|ValueError)|WordPress database error|Allowed memory size exhausted|Maximum execution time of \d+ seconds exceeded|exit signal Segmentation/i',
            $logs
        ) !== 1;
    }

    /**
     * @param  array{ok?:bool,items?:list<array>,health_error?:string|null}  $report
     * @return array{ok:bool,summary:string,findings:list<string>}|null
     */
    private function askAi(Site $site, array $report, string $logs): ?array
    {
        $key = config('wwc.ai_api_key');
        if (! $key) {
            return null;
        }

        $items = collect($report['items'] ?? [])->map(fn ($r) => [
            'type' => $r['type'] ?? '',
            'slug' => $r['slug'] ?? '',
            'ok' => (bool) ($r['ok'] ?? false),
            'error' => $r['error'] ?? null,
        ])->all();

        try {
            $res = Http::timeout(25)
                ->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Du prüfst Logs einer isolierten WordPress-Dev-Kopie nach einem Update-Dry-Run. '
                                .'Antworte NUR mit JSON: {"ok":true,"summary":"zwei Sätze Deutsch","findings":["…"]}. '
                                .'ok=true nur ohne PHP-Fatal, Parse-Error, Uncaught, DB-Fehler oder HTTP-500. '
                                .'Notices und Deprecations allein sind ok=true. Kein Markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => Str::limit(json_encode([
                                'site' => $site->name,
                                'dry_run_ok' => (bool) ($report['ok'] ?? false),
                                'health_error' => $report['health_error'] ?? null,
                                'items' => $items,
                                'logs' => mb_substr($logs, -12000),
                            ], JSON_UNESCAPED_UNICODE), 14000),
                        ],
                    ],
                ]);

            if (! $res->successful()) {
                return null;
            }
            $content = data_get($res->json(), 'choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }
            if (preg_match('/\{.*\}/s', $content, $m) !== 1) {
                return null;
            }
            $parsed = json_decode($m[0], true);
            if (! is_array($parsed)) {
                return null;
            }

            $findings = [];
            foreach ((array) ($parsed['findings'] ?? []) as $row) {
                $text = trim((string) $row);
                if ($text !== '') {
                    $findings[] = mb_substr($text, 0, 240);
                }
            }

            return [
                'ok' => (bool) ($parsed['ok'] ?? false),
                'summary' => mb_substr(trim((string) ($parsed['summary'] ?? '')), 0, 500),
                'findings' => $findings,
            ];
        } catch (\Throwable $e) {
            Log::info('clone log AI skipped', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  list<string>  $findings
     * @param  array{items?:list<array>}  $report
     */
    private function heuristicSummary(Site $site, bool $ok, array $report, array $findings): string
    {
        $names = collect($report['items'] ?? [])
            ->map(fn ($r) => (string) ($r['slug'] ?? $r['type'] ?? ''))
            ->filter()
            ->implode(', ');

        if ($ok) {
            return $names !== ''
                ? "Dry-Run für {$site->name} ohne Fehler in der isolierten Umgebung ({$names}). Updates können live ausgeführt werden."
                : "Dry-Run für {$site->name} ohne Fehler in der isolierten Umgebung. Updates können live ausgeführt werden.";
        }

        $hint = $findings[0] ?? 'Die Logs oder der Dry-Run zeigen Probleme.';

        return "Dry-Run für {$site->name}: {$hint}";
    }

    /** @return list<string> */
    private function heuristicFindings(string $logs): array
    {
        $findings = [];
        foreach (preg_split('/\R/', $logs) ?: [] as $line) {
            if (preg_match('/PHP (Fatal|Parse) error|Uncaught |WordPress database error|Allowed memory size|Maximum execution time/i', $line) === 1) {
                $findings[] = mb_substr(trim($line), 0, 240);
            }
            if (count($findings) >= 8) {
                break;
            }
        }

        return $findings;
    }

    /** @param  array{ok:bool,summary:string,findings:list<string>}  $review */
    private function notify(Site $site, array $review): void
    {
        $org = $site->organization;
        if (! $org) {
            return;
        }

        $ok = $review['ok'];
        $lines = array_values(array_filter([
            $review['summary'],
            $ok ? 'Die KI hat die Logs der isolierten Umgebung geprüft – keine Fehler.' : null,
            ...array_map(fn ($f) => '• '.$f, array_slice($review['findings'], 0, 5)),
        ]));

        $this->alerts->notify(
            $org,
            $ok ? 'clone_dry_run_ok' : 'clone_dry_run_logs',
            $ok
                ? 'Dry-Run ohne Fehler: '.$site->name
                : 'Dry-Run: Logs der Umgebung prüfen – '.$site->name,
            $lines,
            '/sites/'.$site->id,
            $ok ? 'info' : 'warning',
            'clone_dry_run:'.$site->id.':'.($review['reviewed_at'] ?? now()->toIso8601String()),
            $site,
            5
        );
    }
}

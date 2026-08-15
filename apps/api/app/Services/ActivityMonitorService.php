<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ActivityMonitorService
{
    public const SUSPICIOUS_TYPES = [
        'user_created', 'user_role', 'user_deleted', 'app_password',
        'plugin_deleted', 'wp_upgrade', 'theme_switched', 'action_blocked',
        'option_updated', 'login_failed',
    ];

    public function ingest(Site $site, SiteEvent $event): void
    {
        $flags = $this->classify($event);
        if ($flags === []) {
            return;
        }

        $payload = $event->payload ?? [];
        $payload['monitor'] = [
            'flags' => $flags,
            'score' => $this->score($flags),
            'checked_at' => now()->toIso8601String(),
        ];
        $severity = $this->score($flags) >= 80 ? 'critical' : 'warning';
        $event->update([
            'payload' => $payload,
            'severity' => $event->severity === 'critical' ? 'critical' : $severity,
        ]);

        $guard = $site->activity_guard ?? [];
        $autoBlock = (bool) ($guard['auto_block'] ?? false);

        if ($autoBlock) {
            $block = array_values(array_unique(array_merge($guard['block'] ?? [], $this->rulesForFlags($flags))));
            $site->update([
                'activity_guard' => array_merge($guard, [
                    'enabled' => true,
                    'auto_block' => true,
                    'block' => $block,
                ]),
            ]);
        }

        $summary = $this->summarize($site, $event, $flags);
        app(AlertService::class)->notify(
            $site->organization,
            'activity_'.$flags[0],
            ($autoBlock ? 'Verdächtige Aktion (Wache aktiv): ' : 'Verdächtige Aktion: ').$site->name,
            array_filter([
                $event->title,
                'Auslöser: '.($payload['user_login'] ?? 'unbekannt').' · IP '.($payload['ip'] ?? '–'),
                $summary,
                $autoBlock ? 'Passende Sperren wurden an den Agent geschickt.' : 'Im Leitstand / Aktivität prüfen. Auto-Block kann in den Site-Einstellungen aktiviert werden.',
            ]),
            '/activity?site='.$site->id,
            $severity === 'critical' ? 'error' : 'warning',
            'activity:'.$site->id.':'.$event->type.':'.($payload['user_login'] ?? 'x'),
            $site,
            90
        );
    }

    public function guardPayload(Site $site): array
    {
        $g = $site->activity_guard ?? [];

        return [
            'enabled' => (bool) ($g['enabled'] ?? false),
            'auto_block' => (bool) ($g['auto_block'] ?? false),
            'block' => array_values($g['block'] ?? []),
        ];
    }

    /** @return list<string> */
    public function classify(SiteEvent $event): array
    {
        $type = $event->type;
        $p = $event->payload ?? [];
        $flags = [];

        if ($type === 'action_blocked') {
            $flags[] = 'blocked';
        }
        if ($type === 'user_created' && in_array('administrator', $p['target_roles'] ?? [], true)) {
            $flags[] = 'new_admin';
        }
        if ($type === 'user_role' && (($p['new_role'] ?? '') === 'administrator')) {
            $flags[] = 'role_escalate';
        }
        if ($type === 'user_deleted') {
            $flags[] = 'user_delete_admin';
        }
        if ($type === 'wp_upgrade' && ($p['action'] ?? '') === 'install' && ($p['type'] ?? '') === 'plugin') {
            $flags[] = 'plugin_install';
        }
        if ($type === 'theme_switched') {
            $flags[] = 'theme_switch';
        }
        if ($type === 'app_password') {
            $flags[] = 'app_password';
        }
        if ($type === 'option_updated' && in_array($p['option'] ?? '', ['siteurl', 'home', 'users_can_register', 'default_role'], true)) {
            $flags[] = 'core_option';
        }
        if ($type === 'login_failed') {
            $recent = SiteEvent::where('site_id', $event->site_id)
                ->where('type', 'login_failed')
                ->where('occurred_at', '>=', now()->subMinutes(15))
                ->count();
            if ($recent >= 8) {
                $flags[] = 'brute_force';
            }
        }

        return $flags;
    }

    /** @param list<string> $flags */
    private function score(array $flags): int
    {
        $map = [
            'new_admin' => 90,
            'role_escalate' => 95,
            'plugin_install' => 70,
            'theme_switch' => 65,
            'user_delete_admin' => 80,
            'app_password' => 60,
            'core_option' => 85,
            'brute_force' => 75,
            'blocked' => 50,
        ];
        $score = 0;
        foreach ($flags as $f) {
            $score = max($score, $map[$f] ?? 40);
        }

        return $score;
    }

    /** @param list<string> $flags */
    private function rulesForFlags(array $flags): array
    {
        $out = [];
        foreach ($flags as $f) {
            if (in_array($f, ['new_admin', 'role_escalate', 'plugin_install', 'theme_switch', 'file_edit', 'user_delete_admin'], true)) {
                $out[] = $f;
            }
            if ($f === 'core_option') {
                $out[] = 'file_edit';
            }
        }

        return $out;
    }

    /** @param list<string> $flags */
    private function summarize(Site $site, SiteEvent $event, array $flags): ?string
    {
        $heuristic = 'Kennzeichen: '.implode(', ', $flags).'.';
        $key = config('wwc.ai_api_key');
        if (! $key) {
            return $heuristic;
        }
        try {
            $res = Http::timeout(12)->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Du überwachst WordPress-Logs für ein Systemhaus. Antworte in einem deutschen Satz: Risiko und empfohlene Reaktion.'],
                        ['role' => 'user', 'content' => json_encode([
                            'site' => $site->name,
                            'event' => $event->title,
                            'type' => $event->type,
                            'flags' => $flags,
                            'actor' => $event->payload['user_login'] ?? null,
                        ], JSON_UNESCAPED_UNICODE)],
                    ],
                    'max_tokens' => 80,
                ]);
            $text = $res->json('choices.0.message.content');

            return is_string($text) && $text !== '' ? $text : $heuristic;
        } catch (\Throwable $e) {
            Log::info('activity ai skipped', ['error' => $e->getMessage()]);

            return $heuristic;
        }
    }
}

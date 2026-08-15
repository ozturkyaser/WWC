<?php

namespace App\Services;

use App\Mail\AlertMail;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Zentrale Stelle fuer Benachrichtigungen an die Organisation
 * (Site offline, Backup-/Update-Fehler, Security-Findings, KI-Wartung).
 * Mails gehen an alle Mitglieder der Organisation; ein Dedupe-Schluessel
 * verhindert wiederholte Mails fuer denselben Zustand.
 */
class AlertService
{
    /**
     * @param  list<string>  $lines
     */
    public function notify(
        Organization $org,
        string $type,
        string $subject,
        array $lines,
        ?string $actionPath = null,
        string $severity = 'warning',
        ?string $dedupeKey = null,
        ?Site $site = null,
        int $dedupeMinutes = 360,
    ): void {
        if ($dedupeKey !== null && ! Cache::add('wwc_alert:'.$dedupeKey, 1, now()->addMinutes($dedupeMinutes))) {
            return;
        }

        if ($site) {
            SiteEvent::create([
                'organization_id' => $org->id,
                'site_id' => $site->id,
                'type' => 'alert_'.$type,
                'severity' => in_array($severity, ['critical', 'error'], true) ? 'error' : $severity,
                'title' => $subject,
                'payload' => ['lines' => $lines],
                'occurred_at' => now(),
            ]);
        }

        $actionUrl = $actionPath
            ? rtrim((string) config('wwc.portal_url'), '/').$actionPath
            : null;

        $emails = User::query()
            ->whereIn('id', $org->memberships()->pluck('user_id'))
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new AlertMail($subject, $lines, $actionUrl, $severity));
            } catch (\Throwable $e) {
                Log::warning('Alert mail failed', ['email' => $email, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }
    }

    public function siteOffline(Site $site): void
    {
        $lastSeen = $site->last_seen_at?->diffForHumans() ?? 'unbekannt';
        $this->notify(
            $site->organization,
            'site_offline',
            "Site offline: {$site->name}",
            [
                "Die Site \"{$site->name}\" ({$site->url}) hat sich nicht mehr gemeldet.",
                "Letztes Lebenszeichen: {$lastSeen}.",
                'Moegliche Ursachen: Server nicht erreichbar, Agent deaktiviert oder WP-Cron blockiert.',
            ],
            "/sites/{$site->id}",
            'error',
            "site_offline:{$site->id}",
            $site,
            720
        );
    }

    public function siteBackOnline(Site $site): void
    {
        Cache::forget('wwc_alert:site_offline:'.$site->id);
        $this->notify(
            $site->organization,
            'site_online',
            "Site wieder online: {$site->name}",
            ["Die Site \"{$site->name}\" ({$site->url}) meldet sich wieder."],
            "/sites/{$site->id}",
            'info',
            "site_online:{$site->id}",
            $site,
            60
        );
    }
}

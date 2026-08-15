<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Offline-Erkennung: Sites ohne aktuellen Heartbeat markieren + benachrichtigen
Schedule::command('wwc:check-heartbeats')->everyFiveMinutes();
// Geplante Backups (nachts, pro Site versetzt; woechentlich voll, sonst inkrementell)
Schedule::command('wwc:run-backups')->everyFifteenMinutes();
// Woechentlicher Restore-Test im Dev-Clone (backup_verify)
Schedule::command('wwc:verify-backups')->sundays()->at('04:30');
Schedule::command('wwc:sync-patchstack --pages=100 --scan')->dailyAt('02:30');
Schedule::command('wwc:scan-sites --skip-patchstack')->dailyAt('03:15');
// Per-site Wartungs-KI: Audit (+ Dry-Run→Live wenn auto_apply)
Schedule::command('wwc:run-maintenance')->hourly();
// Monatsende/Monatsanfang: Rechnung für Vormonat + E-Mail an Kunden
Schedule::command('wwc:bill-monthly')->monthlyOn(1, '06:00');

<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AgentClient
{
    public function __construct(private HmacSigner $signer = new HmacSigner) {}

    public function pushCommand(Site $site, AgentJob $job): array
    {
        $secret = $site->getHmacSecret();
        if (! $secret) {
            throw new RuntimeException('Site is not paired.');
        }

        $path = '/wp-json/wwc/v1/execute';
        $body = json_encode([
            'job_id' => $job->id,
            'command' => $job->command,
            'payload' => $job->payload ?? new \stdClass,
        ], JSON_THROW_ON_ERROR);

        $response = $this->signedRequest($site, 'POST', $path, $body);

        return $response->json() ?? [];
    }

    public function cancelJob(Site $site, string $jobId): array
    {
        $path = '/wp-json/wwc/v1/cancel';
        $body = json_encode(['job_id' => $jobId], JSON_THROW_ON_ERROR);
        $response = $this->signedRequest($site, 'POST', $path, $body, 15);

        return $response->json() ?? [];
    }

    public function ping(Site $site): array
    {
        $path = '/wp-json/wwc/v1/ping';
        $response = $this->signedRequest($site, 'GET', $path, '');

        return $response->json() ?? [];
    }

    public function requestInventory(Site $site): array
    {
        $path = '/wp-json/wwc/v1/inventory';
        $response = $this->signedRequest($site, 'GET', $path, '');

        return $response->json() ?? [];
    }

    public function executeSync(Site $site, string $command, array $payload = []): array
    {
        $path = '/wp-json/wwc/v1/execute';
        $body = json_encode([
            'job_id' => '',
            'command' => $command,
            'payload' => $payload ?: new \stdClass,
        ], JSON_THROW_ON_ERROR);

        $response = $this->signedRequest($site, 'POST', $path, $body, 120);

        return $response->json() ?? [];
    }

    /**
     * Stream a backup ZIP from the WordPress agent (HMAC-authenticated).
     *
     * @return array{body: string, filename: string, backup_id: string|null}
     */
    public function downloadBackup(Site $site, string $backupId = 'latest-full'): array
    {
        $backupId = $backupId !== '' ? $backupId : 'latest-full';
        $path = '/wp-json/wwc/v1/backups/'.rawurlencode($backupId).'/download';
        $response = $this->signedRequest($site, 'GET', $path, '', 600, 'application/zip');

        if (! $response->successful()) {
            throw new RuntimeException(
                'Backup download failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $disposition = (string) $response->header('Content-Disposition');
        $filename = $backupId.'.zip';
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
            $filename = $m[1];
        }

        return [
            'body' => $response->body(),
            'filename' => $filename,
            'backup_id' => $response->header('X-WWC-Backup-Id'),
        ];
    }

    /**
     * @return array{ok:bool,backup_id:string,type?:string,created_at?:string|null,wp_version?:string|null,file_count?:int|null,files:list<array{name:string,size:int}>}
     */
    public function listBackupParts(Site $site, string $backupId = 'latest-full'): array
    {
        $backupId = $backupId !== '' ? $backupId : 'latest-full';
        $path = '/wp-json/wwc/v1/backups/'.rawurlencode($backupId).'/parts';

        try {
            $response = $this->signedRequest($site, 'GET', $path, '', 60);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                throw new RuntimeException(
                    'Agent unterstützt das Holen mehrteiliger Backups nicht (mind. 0.6.20). Bitte Agent aktualisieren.'
                );
            }
            throw $e;
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['ok']) || empty($data['backup_id']) || ! is_array($data['files'] ?? null)) {
            throw new RuntimeException('Agent lieferte keine Backup-Teile.');
        }

        return $data;
    }

    public function downloadBackupPartTo(Site $site, string $backupId, string $name, string $target): void
    {
        $name = basename($name);
        $path = '/wp-json/wwc/v1/backups/'.rawurlencode($backupId).'/parts/'.rawurlencode($name);
        $this->signedDownload($site, $path, $target, 600);
    }

    private function signedDownload(Site $site, string $path, string $target, int $timeout = 600): void
    {
        $secret = $site->getHmacSecret();
        if (! $secret) {
            throw new RuntimeException('Site is not paired.');
        }

        $timestamp = (string) time();
        $nonce = Str::random(32);
        $signature = HmacSigner::sign('GET', $path, $timestamp, $nonce, '', $secret);
        $url = rtrim($site->url, '/').$path;
        $tmp = $target.'.dl';
        @mkdir(dirname($target), 0755, true);

        $response = Http::timeout($timeout)
            ->connectTimeout(15)
            ->sink($tmp)
            ->withHeaders([
                'X-WWC-Timestamp' => $timestamp,
                'X-WWC-Nonce' => $nonce,
                'X-WWC-Key-Id' => $site->key_id ?? 'primary',
                'X-WWC-Signature' => $signature,
                'Accept' => 'application/octet-stream',
                'Content-Type' => 'application/json',
            ])
            ->get($url);

        if (! $response->successful()) {
            @unlink($tmp);
            $hint = $response->status() === 404
                ? 'Agent unterstützt das Holen mehrteiliger Backups nicht (mind. 0.6.20). Bitte Agent aktualisieren.'
                : 'Backup-Teil fehlgeschlagen: HTTP '.$response->status();
            throw new RuntimeException($hint);
        }

        if (! is_file($tmp) || ! rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Backup-Teil konnte nicht gespeichert werden.');
        }
    }

    private function signedRequest(
        Site $site,
        string $method,
        string $path,
        string $body,
        int $timeout = 30,
        string $accept = 'application/json'
    ) {
        $secret = $site->getHmacSecret();
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $signature = HmacSigner::sign($method, $path, $timestamp, $nonce, $body, $secret);

        $url = rtrim($site->url, '/').$path;

        $pending = Http::timeout($timeout)
            ->connectTimeout(10)
            ->withHeaders([
                'X-WWC-Timestamp' => $timestamp,
                'X-WWC-Nonce' => $nonce,
                'X-WWC-Key-Id' => $site->key_id ?? 'primary',
                'X-WWC-Signature' => $signature,
                'Accept' => $accept,
                'Content-Type' => 'application/json',
            ]);

        $response = strtoupper($method) === 'GET'
            ? $pending->get($url)
            : $pending->withBody($body, 'application/json')->send($method, $url);

        // 202 Accepted = queued on agent for background execution
        if (! $response->successful() && $response->status() !== 202) {
            throw new RuntimeException(
                'Agent request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        return $response;
    }

    public static function consumeNonce(string $siteId, string $nonce): bool
    {
        $key = "wwc:nonce:{$siteId}:{$nonce}";
        if (Cache::has($key)) {
            return false;
        }
        Cache::put($key, 1, 300);

        return true;
    }
}

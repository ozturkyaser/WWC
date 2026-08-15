<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Throwable;

class ReleaseService
{
    public function status(): array
    {
        $repo = $this->repoPath();
        $agent = app(PluginPackager::class)->version();
        $git = $this->gitInfo($repo);
        $deploy = $this->deployState();

        return [
            'name' => 'WWC',
            'version' => $git['version'] ?? ('agent-'.$agent),
            'agent_version' => $agent,
            'repo_path' => $repo,
            'repo_available' => is_dir($repo.'/.git'),
            'git' => $git,
            'deploy' => $deploy,
        ];
    }

    /**
     * @return array{ok:bool,message:string,log:list<string>,status?:array<string,mixed>}
     */
    public function deploy(bool $force = false): array
    {
        $repo = $this->repoPath();
        if (! is_dir($repo.'/.git')) {
            return ['ok' => false, 'message' => 'Git-Repository nicht gefunden (WWC_REPO_PATH).', 'log' => []];
        }

        $lock = storage_path('app/wwc-deploy.lock');
        $fp = fopen($lock, 'c+');
        if (! $fp || ! flock($fp, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'message' => 'Es läuft bereits ein Deploy.', 'log' => []];
        }

        $log = [];
        $this->writeDeployState('running', 'Deploy gestartet…', $log);
        try {
            $remote = (string) config('wwc.deploy_remote', 'origin');
            $branch = (string) config('wwc.deploy_branch', 'main');

            $log[] = $this->step("git fetch {$remote}");
            $fetch = $this->git(['fetch', '--prune', $remote], $repo, 90);
            $log[] = $fetch['output'];
            if (! $fetch['ok']) {
                throw new \RuntimeException('git fetch fehlgeschlagen: '.$fetch['output']);
            }

            $target = $remote.'/'.$branch;
            if ($force) {
                $log[] = $this->step("git reset --hard {$target}");
                $reset = $this->git(['reset', '--hard', $target], $repo, 30);
                $log[] = $reset['output'];
                if (! $reset['ok']) {
                    throw new \RuntimeException('git reset fehlgeschlagen: '.$reset['output']);
                }
            } else {
                $log[] = $this->step("git merge --ff-only {$target}");
                $merge = $this->git(['merge', '--ff-only', $target], $repo, 30);
                $log[] = $merge['output'];
                if (! $merge['ok']) {
                    throw new \RuntimeException(
                        'Fast-forward nicht möglich (lokale Änderungen oder abweichender Branch). Haken „Lokale Änderungen verwerfen“ setzen. '.$merge['output']
                    );
                }
            }

            $log[] = $this->step('php artisan migrate --force');
            $migrate = Process::path(base_path())->timeout(90)->run(['php', 'artisan', 'migrate', '--force']);
            $log[] = trim($migrate->output()."\n".$migrate->errorOutput());
            if (! $migrate->successful()) {
                throw new \RuntimeException('Migration fehlgeschlagen.');
            }

            $log[] = $this->step('php artisan config:clear');
            Process::path(base_path())->timeout(20)->run(['php', 'artisan', 'config:clear']);

            $compose = $repo.'/docker-compose.yml';
            if (is_file($compose) && $this->dockerAvailable()) {
                $log[] = $this->step('docker compose restart scheduler worker');
                $restart = Process::timeout(60)->run([
                    'docker', 'compose', '-f', $compose, 'restart', 'scheduler', 'worker',
                ]);
                $log[] = trim($restart->output()."\n".$restart->errorOutput());
            }

            $status = $this->status();
            $this->writeDeployState('ok', 'Deploy fertig: '.$status['version'], $log);

            return [
                'ok' => true,
                'message' => 'Aktueller Stand von Git ist live: '.$status['version'],
                'log' => $this->trimLog($log),
                'status' => $status,
            ];
        } catch (Throwable $e) {
            $log[] = 'FEHLER: '.$e->getMessage();
            $this->writeDeployState('failed', $e->getMessage(), $log);

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'log' => $this->trimLog($log),
                'status' => $this->status(),
            ];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function repoPath(): string
    {
        $path = (string) config('wwc.repo_path', base_path('../..'));
        $real = realpath($path);

        return $real !== false ? $real : rtrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function gitInfo(string $repo): array
    {
        if (! is_dir($repo.'/.git')) {
            return [
                'available' => false,
                'error' => 'Kein .git unter '.$repo,
            ];
        }

        $head = trim($this->git(['rev-parse', 'HEAD'], $repo)['output']);
        $short = trim($this->git(['rev-parse', '--short=7', 'HEAD'], $repo)['output']);
        $branch = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD'], $repo)['output']);
        $subject = trim($this->git(['log', '-1', '--pretty=%s'], $repo)['output']);
        $committed = trim($this->git(['log', '-1', '--pretty=%cI'], $repo)['output']);
        $dirty = trim($this->git(['status', '--porcelain', '--untracked-files=no'], $repo)['output']) !== '';

        $remote = (string) config('wwc.deploy_remote', 'origin');
        $wantBranch = (string) config('wwc.deploy_branch', 'main');
        $behind = null;
        $ahead = null;
        $remoteSha = null;
        $compareError = null;
        $this->maybeFetch($repo, $remote);
        $remoteRef = $this->git(['rev-parse', $remote.'/'.$wantBranch], $repo);
        if ($remoteRef['ok'] && preg_match('/^[0-9a-f]{40}$/i', trim($remoteRef['output']))) {
            $remoteSha = trim($remoteRef['output']);
            $counts = $this->git(['rev-list', '--left-right', '--count', $head.'...'.$remoteSha], $repo);
            if ($counts['ok'] && preg_match('/^(\d+)\s+(\d+)/', trim($counts['output']), $c)) {
                $ahead = (int) $c[1];
                $behind = (int) $c[2];
            }
        } else {
            $compareError = 'Git-Remote nicht erreichbar oder Branch fehlt.';
        }

        $date = $committed !== '' ? substr($committed, 0, 10) : date('Y-m-d');
        $version = $date.'+'.$short;

        return [
            'available' => true,
            'version' => $version,
            'sha' => $head,
            'short_sha' => $short,
            'branch' => $branch,
            'subject' => $subject,
            'committed_at' => $committed !== '' ? $committed : null,
            'dirty' => $dirty,
            'remote' => $remote,
            'remote_branch' => $wantBranch,
            'remote_sha' => $remoteSha,
            'ahead' => $ahead,
            'behind' => $behind,
            'update_available' => is_int($behind) && $behind > 0,
            'compare_error' => $compareError,
        ];
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,output:string}
     */
    private function git(array $args, ?string $cwd = null, int $timeout = 15): array
    {
        $cmd = array_merge(['git', '-c', 'safe.directory=*'], $args);
        $pending = Process::timeout($timeout);
        if ($cwd) {
            $pending = $pending->path($cwd);
        }
        $result = $pending->run($cmd);
        $out = trim($result->output()."\n".$result->errorOutput());

        return ['ok' => $result->successful(), 'output' => $out];
    }

    private function maybeFetch(string $repo, string $remote): void
    {
        $key = 'wwc_git_fetch_'.md5($repo.'|'.$remote);
        if (cache()->has($key)) {
            return;
        }
            $fetch = $this->git(['fetch', '--prune', $remote], $repo, 25);
        if ($fetch['ok']) {
            cache()->put($key, 1, now()->addSeconds(20));
        }
    }

    private function dockerAvailable(): bool
    {
        return Process::timeout(5)->run(['docker', 'compose', 'version'])->successful();
    }

    private function step(string $label): string
    {
        return '→ '.$label;
    }

    /** @param  list<string>  $log */
    private function writeDeployState(string $status, string $message, array $log): void
    {
        $dir = storage_path('app');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($dir.'/wwc-deploy.json', json_encode([
            'status' => $status,
            'message' => $message,
            'log' => $this->trimLog($log),
            'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function deployState(): array
    {
        $file = storage_path('app/wwc-deploy.json');
        if (! is_file($file)) {
            return ['status' => 'idle', 'message' => null, 'log' => [], 'at' => null];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : ['status' => 'idle'];
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function trimLog(array $log): array
    {
        $clean = [];
        foreach ($log as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $clean[] = mb_substr($line, 0, 400);
            }
        }

        return array_slice($clean, -40);
    }
}

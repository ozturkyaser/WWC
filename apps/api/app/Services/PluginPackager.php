<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class PluginPackager
{
    public function sourcePath(): string
    {
        $repo = rtrim((string) config('wwc.repo_path', ''), '/');
        $candidates = array_values(array_filter([
            $repo !== '' ? $repo.'/packages/wp-agent' : null,
            base_path('../../packages/wp-agent'),
            dirname(base_path(), 2).'/packages/wp-agent',
            resource_path('wp-agent'),
        ]));

        foreach ($candidates as $path) {
            if (is_dir($path) && file_exists($path.'/wwc-agent.php')) {
                return realpath($path) ?: $path;
            }
        }

        throw new RuntimeException('WWC Agent plugin source not found.');
    }

    public function version(): string
    {
        $file = $this->sourcePath().'/wwc-agent.php';
        $raw = (string) file_get_contents($file);
        if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/mi', $raw, $m)) {
            return trim($m[1]);
        }
        if (preg_match("/define\(\s*'WWC_AGENT_VERSION'\s*,\s*'([^']+)'\s*\)/", $raw, $m)) {
            return $m[1];
        }

        return (string) config('wwc.agent_version', '0.0.0');
    }

    public function buildZip(?string $target = null): string
    {
        $source = $this->sourcePath();
        $dir = storage_path('app/agent-releases');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $version = $this->version();
        $target = $target ?: ($dir.'/wwc-agent-'.$version.'.zip');
        $latestLink = $dir.'/wwc-agent.zip';

        foreach ([$target, $latestLink] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create plugin ZIP.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getRealPath();
            $relative = substr($absolute, strlen($source) + 1);
            $zip->addFile($absolute, 'wwc-agent/'.str_replace('\\', '/', $relative));
        }

        $zip->close();
        copy($target, $latestLink);

        $this->writeLatestMeta($version, $latestLink);

        return $latestLink;
    }

    /**
     * @return array{version:string,package:string,signature:?string,url:?string,built_at:string}
     */
    public function releaseMeta(): array
    {
        $version = $this->version();
        $zip = storage_path('app/agent-releases/wwc-agent.zip');
        $metaFile = storage_path('app/agent-releases/latest.json');
        $needBuild = ! is_file($zip);
        if (! $needBuild && is_file($metaFile)) {
            $cached = json_decode((string) file_get_contents($metaFile), true);
            if (! is_array($cached) || ($cached['version'] ?? '') !== $version) {
                $needBuild = true;
            }
        } elseif (! $needBuild) {
            $needBuild = true;
        }
        if ($needBuild) {
            $this->buildZip();
        }

        $base = rtrim((string) config('wwc.public_api_url', config('app.url')), '/');

        return [
            'version' => $version,
            'package' => $base.'/api/agent-releases/download',
            'signature' => null,
            'url' => $base.'/api/agent-releases/latest',
            'built_at' => now()->toIso8601String(),
            'sha256' => is_file($zip) ? hash_file('sha256', $zip) : null,
        ];
    }

    private function writeLatestMeta(string $version, string $zipPath): void
    {
        $base = rtrim((string) config('wwc.public_api_url', config('app.url')), '/');
        $meta = [
            'version' => $version,
            'package' => $base.'/api/agent-releases/download',
            'signature' => null,
            'url' => $base.'/api/agent-releases/latest',
            'built_at' => now()->toIso8601String(),
            'sha256' => hash_file('sha256', $zipPath) ?: null,
        ];
        file_put_contents(
            storage_path('app/agent-releases/latest.json'),
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

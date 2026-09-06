<?php

namespace App\Services;

use App\Models\Site;
use RuntimeException;

/**
 * Cursor/Claude steuern WordPress über WWC.
 * Live = HMAC-Agent, Clone = wp-cli auf dem WWC-Server.
 */
class WpMcpService
{
    public function __construct(
        private AgentClient $client,
        private DevCloneService $clones,
    ) {}

    /**
     * @return list<array{name:string,description:string,input:array<string,string>}>
     */
    public function catalog(): array
    {
        return [
            [
                'name' => 'wwc_sites',
                'description' => 'Listet die Sites der Organisation (id, url, agent, clone, pairing).',
                'input' => [],
            ],
            [
                'name' => 'wwc_wp_tools',
                'description' => 'WordPress-MCP-Tools der Site (info, scan, posts, SEO, Theme, apply).',
                'input' => ['site_id' => 'uuid', 'target' => 'clone|live'],
            ],
            [
                'name' => 'wwc_wp_call',
                'description' => 'Ruft ein WordPress-Tool auf. Schreiben auf Live braucht confirm_live=true.',
                'input' => [
                    'site_id' => 'uuid',
                    'tool' => 'wp_info|wp_scan|wp_list_posts|wp_get_post|wp_search|wp_plugins|wp_seo_get|wp_seo_set|wp_theme_read|wp_theme_write|wp_apply',
                    'arguments' => 'object',
                    'target' => 'clone|live',
                    'confirm_live' => 'bool',
                ],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Site>|iterable<Site>  $sites
     * @return list<array<string, mixed>>
     */
    public function listSites(iterable $sites): array
    {
        $out = [];
        foreach ($sites as $site) {
            $out[] = [
                'id' => $site->id,
                'name' => $site->name,
                'url' => $site->url,
                'status' => $site->status,
                'agent_version' => $site->agent_version,
                'live_paired' => (bool) $site->getHmacSecret(),
                'clone_ready' => $this->clones->isReady($site),
                'clone_url' => $site->dev_clone['url'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wpTools(Site $site, ?string $target = null): array
    {
        $target = $this->resolveTarget($site, $target);
        if ($target === 'live') {
            return $this->client->mcpTools($site);
        }

        return $this->agentToolCatalog();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function wpCall(Site $site, string $tool, array $arguments, ?string $target, bool $confirmLive): array
    {
        $tool = trim($tool);
        if ($tool === '') {
            throw new RuntimeException('tool fehlt.');
        }
        $target = $this->resolveTarget($site, $target);
        $writes = ['wp_apply', 'wp_seo_set', 'wp_theme_write'];
        if ($target === 'live' && in_array($tool, $writes, true) && ! $confirmLive) {
            throw new RuntimeException('Live-Änderungen brauchen confirm_live=true.');
        }
        if ($target === 'clone') {
            if (! $this->clones->isReady($site)) {
                throw new RuntimeException('Isolierte Kopie ist nicht bereit.');
            }

            return $this->clones->mcpOnClone($site, $tool, $arguments);
        }
        if (! $site->getHmacSecret()) {
            throw new RuntimeException('Live-Site ist nicht gekoppelt. Zuerst den WWC-Agenten verbinden.');
        }

        return $this->client->mcpCall($site, $tool, $arguments);
    }

    private function resolveTarget(Site $site, ?string $target): string
    {
        $target = $target === 'live' || $target === 'clone' ? $target : null;
        if ($target === 'live') {
            if (! $site->getHmacSecret()) {
                throw new RuntimeException('Live-Site ist nicht gekoppelt.');
            }

            return 'live';
        }
        if ($target === 'clone') {
            if (! $this->clones->isReady($site)) {
                throw new RuntimeException('Isolierte Kopie ist nicht bereit.');
            }

            return 'clone';
        }
        if ($this->clones->isReady($site)) {
            return 'clone';
        }
        if ($site->getHmacSecret()) {
            return 'live';
        }

        throw new RuntimeException('Weder Live-Agent noch isolierte Kopie sind bereit.');
    }

    /**
     * @return list<array{name:string,description:string,input:array<string,string>}>
     */
    private function agentToolCatalog(): array
    {
        return [
            ['name' => 'wp_info', 'description' => 'Site, Theme, PHP, Agent.', 'input' => []],
            ['name' => 'wp_scan', 'description' => 'Voller Site-Scan.', 'input' => []],
            ['name' => 'wp_list_posts', 'description' => 'Seiten/Beiträge listen.', 'input' => ['type' => 'page|post']],
            ['name' => 'wp_get_post', 'description' => 'Beitrag inkl. SEO.', 'input' => ['id' => 'int']],
            ['name' => 'wp_search', 'description' => 'Inhalte suchen.', 'input' => ['query' => 'string']],
            ['name' => 'wp_plugins', 'description' => 'Plugins listen.', 'input' => []],
            ['name' => 'wp_seo_get', 'description' => 'SEO-Felder lesen.', 'input' => ['id' => 'int']],
            ['name' => 'wp_seo_set', 'description' => 'SEO-Felder schreiben.', 'input' => ['id' => 'int']],
            ['name' => 'wp_theme_read', 'description' => 'Theme-Datei lesen.', 'input' => ['path' => 'string']],
            ['name' => 'wp_theme_write', 'description' => 'Theme-Datei schreiben.', 'input' => ['path' => 'string', 'content' => 'string']],
            ['name' => 'wp_apply', 'description' => 'Allowlisted Content-Ops.', 'input' => ['ops' => 'list']],
        ];
    }
}

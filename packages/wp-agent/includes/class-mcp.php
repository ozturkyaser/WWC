<?php

declare(strict_types=1);

/**
 * Allowlisted MCP tools on the paired WordPress site.
 * Cursor/Claude talk to WWC; WWC calls this over HMAC – not an open WP MCP.
 */
final class WWC_Agent_Mcp
{
    public const WRITE_TOOLS = [
        'wp_apply',
        'wp_seo_set',
        'wp_theme_write',
    ];

    /**
     * @return list<array{name:string,description:string,input:array<string,string>}>
     */
    public static function tools(): array
    {
        return [
            [
                'name' => 'wp_info',
                'description' => 'Site-Name, URL, Theme, PHP, Agent-Version, aktive SEO-Plugins.',
                'input' => [],
            ],
            [
                'name' => 'wp_scan',
                'description' => 'Voller Site-Scan: Seiten, Plugins, Editoren, Branding.',
                'input' => [],
            ],
            [
                'name' => 'wp_list_posts',
                'description' => 'Seiten oder Beiträge listen.',
                'input' => ['type' => 'page|post', 'search' => 'string', 'status' => 'publish|draft|any', 'limit' => 'int'],
            ],
            [
                'name' => 'wp_get_post',
                'description' => 'Beitrag inkl. Inhalt und SEO-Meta.',
                'input' => ['id' => 'int'],
            ],
            [
                'name' => 'wp_search',
                'description' => 'Inhalte durchsuchen.',
                'input' => ['query' => 'string', 'type' => 'page|post|any', 'limit' => 'int'],
            ],
            [
                'name' => 'wp_plugins',
                'description' => 'Installierte Plugins mit Status.',
                'input' => [],
            ],
            [
                'name' => 'wp_seo_get',
                'description' => 'SEO-Titel, Description, Canonical, Focus-Keyword (Yoast/Rank Math/Core).',
                'input' => ['id' => 'int'],
            ],
            [
                'name' => 'wp_seo_set',
                'description' => 'SEO-Felder setzen. Schreibt Yoast oder Rank Math, falls aktiv.',
                'input' => ['id' => 'int', 'title' => 'string', 'description' => 'string', 'canonical' => 'string', 'focus' => 'string'],
            ],
            [
                'name' => 'wp_theme_read',
                'description' => 'Theme-Datei lesen (css/js/php/json/html/svg).',
                'input' => ['path' => 'string'],
            ],
            [
                'name' => 'wp_theme_write',
                'description' => 'Theme-Datei schreiben. Lieber auf der isolierten Kopie testen.',
                'input' => ['path' => 'string', 'content' => 'string'],
            ],
            [
                'name' => 'wp_apply',
                'description' => 'Allowlisted Content-Ops: create_post, update_post, set_option, set_custom_css, plugin_activate, …',
                'input' => ['ops' => 'list'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function handle(array $payload): array
    {
        $tool = (string) ($payload['tool'] ?? '');
        if ($tool === '' || $tool === 'tools') {
            return ['ok' => true, 'tools' => self::tools()];
        }
        $args = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];

        return self::call($tool, $args);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function call(string $tool, array $args): array
    {
        try {
            return match ($tool) {
                'wp_info' => self::info(),
                'wp_scan' => WWC_Agent_Site_Intel::scan(),
                'wp_list_posts' => self::list_posts($args),
                'wp_get_post' => self::get_post($args),
                'wp_search' => self::search($args),
                'wp_plugins' => self::plugins(),
                'wp_seo_get' => self::seo_get($args),
                'wp_seo_set' => self::seo_set($args),
                'wp_theme_read' => self::theme_read($args),
                'wp_theme_write' => WWC_Agent_Site_Intel::apply([[
                    'op' => 'update_theme_file',
                    'path' => (string) ($args['path'] ?? ''),
                    'content' => (string) ($args['content'] ?? ''),
                ]]),
                'wp_apply' => WWC_Agent_Site_Intel::apply(is_array($args['ops'] ?? null) ? $args['ops'] : []),
                default => ['ok' => false, 'error' => 'Unbekanntes MCP-Tool: '.$tool],
            };
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private static function info(): array
    {
        $theme = wp_get_theme();

        return [
            'ok' => true,
            'site' => [
                'name' => get_bloginfo('name'),
                'tagline' => get_bloginfo('description'),
                'url' => home_url('/'),
                'admin' => admin_url(),
                'language' => get_bloginfo('language'),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'agent_version' => defined('WWC_AGENT_VERSION') ? WWC_AGENT_VERSION : '',
            ],
            'theme' => [
                'name' => (string) $theme->get('Name'),
                'stylesheet' => get_stylesheet(),
                'version' => (string) $theme->get('Version'),
            ],
            'seo_plugin' => self::seo_plugin(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function list_posts(array $args): array
    {
        $type = (string) ($args['type'] ?? 'page');
        if (! in_array($type, ['page', 'post'], true)) {
            return ['ok' => false, 'error' => 'type muss page oder post sein'];
        }
        $status = (string) ($args['status'] ?? 'publish');
        if (! in_array($status, ['publish', 'draft', 'private', 'any'], true)) {
            $status = 'publish';
        }
        $q = [
            'post_type' => $type,
            'post_status' => $status === 'any' ? ['publish', 'draft', 'private'] : $status,
            'posts_per_page' => min(80, max(1, (int) ($args['limit'] ?? 30))),
            'orderby' => 'modified',
            'order' => 'DESC',
            's' => trim((string) ($args['search'] ?? '')),
        ];
        $items = [];
        foreach (get_posts($q) as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }
            $items[] = self::post_card($post);
        }

        return ['ok' => true, 'type' => $type, 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function get_post(array $args): array
    {
        $post = get_post((int) ($args['id'] ?? 0));
        if (! $post instanceof WP_Post) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden'];
        }

        return [
            'ok' => true,
            'post' => array_merge(self::post_card($post), [
                'content' => (string) $post->post_content,
                'excerpt' => (string) $post->post_excerpt,
                'seo' => self::seo_fields($post->ID),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function search(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query fehlt'];
        }
        $type = (string) ($args['type'] ?? 'any');
        $types = $type === 'any' ? ['page', 'post'] : [$type];
        $items = [];
        foreach (get_posts([
            'post_type' => $types,
            'post_status' => ['publish', 'draft'],
            's' => $query,
            'posts_per_page' => min(40, max(1, (int) ($args['limit'] ?? 20))),
        ]) as $post) {
            if ($post instanceof WP_Post) {
                $items[] = self::post_card($post);
            }
        }

        return ['ok' => true, 'query' => $query, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function plugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        $out = [];
        foreach (get_plugins() as $file => $data) {
            $out[] = [
                'file' => $file,
                'slug' => dirname($file) === '.' ? basename($file, '.php') : dirname($file),
                'name' => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => in_array($file, $active, true),
            ];
        }

        return ['ok' => true, 'plugins' => $out];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function seo_get(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (! get_post($id) instanceof WP_Post) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden'];
        }

        return ['ok' => true, 'id' => $id, 'plugin' => self::seo_plugin(), 'seo' => self::seo_fields($id)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function seo_set(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden'];
        }
        $before = self::seo_fields($id);
        $plugin = self::seo_plugin();
        $map = $plugin === 'rankmath'
            ? [
                'title' => 'rank_math_title',
                'description' => 'rank_math_description',
                'canonical' => 'rank_math_canonical_url',
                'focus' => 'rank_math_focus_keyword',
            ]
            : [
                'title' => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
                'canonical' => '_yoast_wpseo_canonical',
                'focus' => '_yoast_wpseo_focuskw',
            ];
        foreach (['title', 'description', 'canonical', 'focus'] as $field) {
            if (! array_key_exists($field, $args)) {
                continue;
            }
            $value = trim((string) $args[$field]);
            if ($value === '') {
                delete_post_meta($id, $map[$field]);
            } else {
                update_post_meta($id, $map[$field], $value);
            }
        }
        if (array_key_exists('excerpt', $args)) {
            wp_update_post(['ID' => $id, 'post_excerpt' => (string) $args['excerpt']]);
        }

        return [
            'ok' => true,
            'id' => $id,
            'plugin' => $plugin,
            'before' => $before,
            'after' => self::seo_fields($id),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function theme_read(array $args): array
    {
        $rel = str_replace('\\', '/', trim((string) ($args['path'] ?? '')));
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return ['ok' => false, 'error' => 'Ungültiger Theme-Pfad'];
        }
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if (! in_array($ext, ['css', 'js', 'php', 'json', 'svg', 'html'], true)) {
            return ['ok' => false, 'error' => 'Dateityp nicht erlaubt: '.$ext];
        }
        $root = realpath(get_stylesheet_directory());
        if ($root === false) {
            return ['ok' => false, 'error' => 'Theme-Verzeichnis fehlt'];
        }
        $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $real = realpath($full);
        if ($real === false || ! str_starts_with($real, $root) || ! is_file($real)) {
            return ['ok' => false, 'error' => 'Datei nicht gefunden'];
        }
        $content = (string) file_get_contents($real);
        if (strlen($content) > 120000) {
            $content = substr($content, 0, 120000)."\n… [gekürzt]";
        }

        return ['ok' => true, 'path' => $rel, 'bytes' => (int) filesize($real), 'content' => $content];
    }

    /** @return array{title:?string,description:?string,canonical:?string,focus:?string,excerpt:string} */
    private static function seo_fields(int $id): array
    {
        $plugin = self::seo_plugin();
        if ($plugin === 'rankmath') {
            return [
                'title' => self::meta_or_null($id, 'rank_math_title'),
                'description' => self::meta_or_null($id, 'rank_math_description'),
                'canonical' => self::meta_or_null($id, 'rank_math_canonical_url'),
                'focus' => self::meta_or_null($id, 'rank_math_focus_keyword'),
                'excerpt' => (string) get_post_field('post_excerpt', $id),
            ];
        }

        return [
            'title' => self::meta_or_null($id, '_yoast_wpseo_title'),
            'description' => self::meta_or_null($id, '_yoast_wpseo_metadesc'),
            'canonical' => self::meta_or_null($id, '_yoast_wpseo_canonical'),
            'focus' => self::meta_or_null($id, '_yoast_wpseo_focuskw'),
            'excerpt' => (string) get_post_field('post_excerpt', $id),
        ];
    }

    private static function meta_or_null(int $id, string $key): ?string
    {
        $value = get_post_meta($id, $key, true);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function seo_plugin(): string
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option('active_plugins', []);
        foreach ($active as $file) {
            $slug = dirname((string) $file);
            if ($slug === 'seo-by-rank-math') {
                return 'rankmath';
            }
            if ($slug === 'wordpress-seo' || $slug === 'wordpress-seo-premium') {
                return 'yoast';
            }
        }

        return 'core';
    }

    /** @return array<string, mixed> */
    private static function post_card(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            'type' => $post->post_type,
            'status' => $post->post_status,
            'url' => (string) get_permalink($post),
            'modified' => get_post_modified_time('c', true, $post),
        ];
    }
}

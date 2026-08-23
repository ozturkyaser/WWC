<?php

declare(strict_types=1);

/**
 * Scans the WordPress install (stack, editors, pages) and applies allowlisted
 * content operations. Used by the live agent and by wp-cli on the Dev-Kopie.
 */
final class WWC_Agent_Site_Intel
{
    private const OPTION_ALLOWLIST = [
        'blogname', 'blogdescription', 'show_on_front', 'page_on_front',
        'page_for_posts', 'posts_per_page',
    ];

    private const BUILDER_SLUGS = [
        'elementor' => 'Elementor',
        'elementor-pro' => 'Elementor Pro',
        'js_composer' => 'WPBakery',
        'divi-builder' => 'Divi Builder',
        'bb-plugin' => 'Beaver Builder',
        'beaver-builder-lite-version' => 'Beaver Builder',
        'oxygen' => 'Oxygen',
        'bricks' => 'Bricks',
        'breakdance' => 'Breakdance',
        'thrive-visual-editor' => 'Thrive Architect',
        'siteorigin-panels' => 'SiteOrigin',
        'fusion-builder' => 'Avada Fusion Builder',
    ];

    public static function scan(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $active = (array) get_option('active_plugins', []);
        $plugins = [];
        $builders = [];
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }
            $isActive = in_array($file, $active, true) || (is_multisite() && is_plugin_active($file));
            $plugins[] = [
                'file' => $file,
                'slug' => $slug,
                'name' => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => $isActive,
            ];
            if ($isActive && isset(self::BUILDER_SLUGS[$slug])) {
                $builders[] = self::BUILDER_SLUGS[$slug];
            }
        }

        $theme = wp_get_theme();
        $parent = $theme->parent();
        $stylesheet = get_stylesheet();
        if (in_array(strtolower($stylesheet), ['divi', 'extra'], true) || stripos((string) $theme->get('Name'), 'Divi') !== false) {
            $builders[] = 'Divi';
        }

        $builders = array_values(array_unique($builders));
        $defaultEditor = function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('page')
            ? 'gutenberg'
            : 'classic';

        $logoId = (int) get_theme_mod('custom_logo');
        $pages = self::map_posts('page', 80);
        $posts = self::map_posts('post', 40);

        return [
            'ok' => true,
            'scanned_at' => gmdate('c'),
            'site' => [
                'name' => get_bloginfo('name'),
                'tagline' => get_bloginfo('description'),
                'url' => home_url('/'),
                'admin' => admin_url(),
                'language' => get_bloginfo('language'),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ],
            'theme' => [
                'name' => (string) $theme->get('Name'),
                'stylesheet' => $stylesheet,
                'version' => (string) $theme->get('Version'),
                'parent' => $parent ? (string) $parent->get('Name') : null,
                'screenshot' => $theme->get_screenshot() ?: null,
            ],
            'editors' => [
                'default' => $defaultEditor,
                'builders' => $builders,
                'notes' => $builders === []
                    ? 'Standard: Gutenberg/Classic – Seiteninhalte können direkt geändert werden.'
                    : 'Page-Builder aktiv. Bestehende Builder-Layouts nicht als Roh-HTML überschreiben; neue Seiten/Beiträge als Gutenberg anlegen.',
            ],
            'branding' => [
                'logo_id' => $logoId ?: null,
                'logo_url' => $logoId ? wp_get_attachment_url($logoId) : null,
                'site_icon' => get_site_icon_url(256) ?: null,
            ],
            'homepage' => [
                'show_on_front' => (string) get_option('show_on_front'),
                'page_on_front' => (int) get_option('page_on_front'),
                'page_for_posts' => (int) get_option('page_for_posts'),
            ],
            'plugins' => $plugins,
            'pages' => $pages,
            'posts' => $posts,
            'menus' => self::menus(),
            'counts' => [
                'pages' => (int) wp_count_posts('page')->publish,
                'posts' => (int) wp_count_posts('post')->publish,
                'plugins_active' => count(array_filter($plugins, static fn ($p) => $p['active'])),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return array{ok:bool,results:list<array>,error?:string}
     */
    public static function apply(array $ops): array
    {
        $results = [];
        foreach ($ops as $i => $op) {
            if (! is_array($op)) {
                $results[] = ['ok' => false, 'index' => $i, 'error' => 'Ungültige Operation'];
                continue;
            }
            try {
                $results[] = array_merge(['index' => $i, 'op' => (string) ($op['op'] ?? '')], self::apply_one($op));
            } catch (Throwable $e) {
                $results[] = ['ok' => false, 'index' => $i, 'op' => (string) ($op['op'] ?? ''), 'error' => $e->getMessage()];
            }
        }

        $ok = true;
        foreach ($results as $row) {
            if (empty($row['ok'])) {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array{ok:bool,id?:int,url?:string,error?:string}
     */
    private static function apply_one(array $op): array
    {
        $name = (string) ($op['op'] ?? '');

        return match ($name) {
            'create_post' => self::create_post($op),
            'update_post' => self::update_post($op),
            'set_option' => self::set_option($op),
            'set_logo' => self::set_logo($op),
            'upload_media' => self::upload_media($op),
            default => ['ok' => false, 'error' => 'Unbekannte Operation: '.$name],
        };
    }

    /** @param array<string, mixed> $op */
    private static function create_post(array $op): array
    {
        $type = (string) ($op['type'] ?? 'page');
        if (! in_array($type, ['page', 'post'], true)) {
            return ['ok' => false, 'error' => 'Nur page oder post'];
        }
        $title = trim((string) ($op['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'Titel fehlt'];
        }
        $id = wp_insert_post([
            'post_type' => $type,
            'post_title' => $title,
            'post_content' => (string) ($op['content'] ?? ''),
            'post_excerpt' => (string) ($op['excerpt'] ?? ''),
            'post_status' => in_array($op['status'] ?? 'publish', ['publish', 'draft', 'private'], true) ? $op['status'] : 'publish',
            'post_parent' => (int) ($op['parent'] ?? 0),
        ], true);
        if (is_wp_error($id)) {
            return ['ok' => false, 'error' => $id->get_error_message()];
        }

        return ['ok' => true, 'id' => (int) $id, 'url' => (string) get_permalink($id)];
    }

    /** @param array<string, mixed> $op */
    private static function update_post(array $op): array
    {
        $id = (int) ($op['id'] ?? 0);
        $post = $id > 0 ? get_post($id) : null;
        if (! $post instanceof WP_Post) {
            return ['ok' => false, 'error' => 'Beitrag nicht gefunden'];
        }
        if (self::is_builder_owned($post) && array_key_exists('content', $op)) {
            return ['ok' => false, 'error' => 'Seite läuft über einen Page-Builder – Inhalt nicht als HTML überschreiben. Titel/Status gehen, oder neue Gutenberg-Seite anlegen.'];
        }
        $data = ['ID' => $id];
        if (isset($op['title'])) {
            $data['post_title'] = (string) $op['title'];
        }
        if (array_key_exists('content', $op)) {
            $data['post_content'] = (string) $op['content'];
        }
        if (isset($op['excerpt'])) {
            $data['post_excerpt'] = (string) $op['excerpt'];
        }
        if (isset($op['status']) && in_array($op['status'], ['publish', 'draft', 'private'], true)) {
            $data['post_status'] = $op['status'];
        }
        $updated = wp_update_post($data, true);
        if (is_wp_error($updated)) {
            return ['ok' => false, 'error' => $updated->get_error_message()];
        }

        return ['ok' => true, 'id' => $id, 'url' => (string) get_permalink($id)];
    }

    /** @param array<string, mixed> $op */
    private static function set_option(array $op): array
    {
        $key = (string) ($op['key'] ?? '');
        if (! in_array($key, self::OPTION_ALLOWLIST, true)) {
            return ['ok' => false, 'error' => 'Option nicht erlaubt: '.$key];
        }
        update_option($key, $op['value'] ?? '');

        return ['ok' => true];
    }

    /** @param array<string, mixed> $op */
    private static function set_logo(array $op): array
    {
        $mediaId = (int) ($op['media_id'] ?? 0);
        if ($mediaId <= 0 && ! empty($op['path']) && is_readable((string) $op['path'])) {
            $uploaded = self::sideload_file((string) $op['path'], (string) ($op['title'] ?? 'Logo'));
            if (! ($uploaded['ok'] ?? false)) {
                return $uploaded;
            }
            $mediaId = (int) $uploaded['id'];
        }
        if ($mediaId <= 0) {
            return ['ok' => false, 'error' => 'Kein Logo (media_id oder path)'];
        }
        set_theme_mod('custom_logo', $mediaId);

        return ['ok' => true, 'id' => $mediaId, 'url' => (string) wp_get_attachment_url($mediaId)];
    }

    /** @param array<string, mixed> $op */
    private static function upload_media(array $op): array
    {
        $name = basename((string) ($op['filename'] ?? 'upload.bin'));
        $bin = base64_decode((string) ($op['base64'] ?? ''), true);
        if ($bin === false || $bin === '') {
            return ['ok' => false, 'error' => 'Ungültige Datei'];
        }
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $bin);
        $uploaded = self::sideload_file($tmp, (string) ($op['title'] ?? $name));
        @unlink($tmp);

        return $uploaded;
    }

    /** @return array{ok:bool,id?:int,url?:string,error?:string} */
    private static function sideload_file(string $path, string $title): array
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $file = [
            'name' => basename($path),
            'tmp_name' => $path,
        ];
        $id = media_handle_sideload($file, 0, $title);
        if (is_wp_error($id)) {
            return ['ok' => false, 'error' => $id->get_error_message()];
        }

        return ['ok' => true, 'id' => (int) $id, 'url' => (string) wp_get_attachment_url($id)];
    }

    private static function is_builder_owned(WP_Post $post): bool
    {
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            return true;
        }
        if (get_post_meta($post->ID, '_wpb_vc_js_status', true) === 'true') {
            return true;
        }
        if (get_post_meta($post->ID, '_fl_builder_enabled', true)) {
            return true;
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private static function map_posts(string $type, int $limit): array
    {
        $rows = get_posts([
            'post_type' => $type,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        $out = [];
        foreach ($rows as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }
            $editor = 'classic';
            if (self::is_builder_owned($post)) {
                if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
                    $editor = 'elementor';
                } elseif (get_post_meta($post->ID, '_wpb_vc_js_status', true) === 'true') {
                    $editor = 'wpbakery';
                } else {
                    $editor = 'builder';
                }
            } elseif (function_exists('has_blocks') && has_blocks($post)) {
                $editor = 'gutenberg';
            }
            $out[] = [
                'id' => $post->ID,
                'title' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                'type' => $post->post_type,
                'status' => $post->post_status,
                'url' => (string) get_permalink($post),
                'modified' => get_post_modified_time('c', true, $post),
                'editor' => $editor,
                'template' => (string) (get_page_template_slug($post->ID) ?: 'default'),
                'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 28),
            ];
        }

        return $out;
    }

    /** @return list<array{name:string,slug:string,items:list<array{title:string,url:string}>}> */
    private static function menus(): array
    {
        $out = [];
        foreach (wp_get_nav_menus() as $menu) {
            $items = [];
            foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $item) {
                $items[] = [
                    'title' => (string) $item->title,
                    'url' => (string) $item->url,
                ];
                if (count($items) >= 30) {
                    break;
                }
            }
            $out[] = [
                'name' => (string) $menu->name,
                'slug' => (string) $menu->slug,
                'items' => $items,
            ];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

final class WWC_Agent_Updater
{
    private static function bootstrap(): void
    {
        if (! function_exists('request_filesystem_credentials')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';

        // Explicit includes – class-wp-upgrader.php does not always pull skins in all WP versions
        // when loaded outside wp-admin bootstrap.
        $files = [
            'class-wp-upgrader-skin.php',
            'class-automatic-upgrader-skin.php',
            'class-plugin-upgrader.php',
            'class-theme-upgrader.php',
            'class-core-upgrader.php',
            'update.php',
        ];
        foreach ($files as $file) {
            $path = ABSPATH.'wp-admin/includes/'.$file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        // Fallback for very old WP skins bundle
        $legacySkins = ABSPATH.'wp-admin/includes/class-wp-upgrader-skins.php';
        if (! class_exists('Automatic_Upgrader_Skin', false) && file_exists($legacySkins)) {
            require_once $legacySkins;
        }

        if (! class_exists('Automatic_Upgrader_Skin', false)) {
            throw new RuntimeException('Automatic_Upgrader_Skin could not be loaded. Is wp-admin complete?');
        }
    }

    public static function update_plugin(string $slugOrFile): array
    {
        self::bootstrap();

        $file = self::resolve_plugin_file($slugOrFile);
        if (! $file) {
            return ['ok' => false, 'error' => 'Plugin not found: '.$slugOrFile];
        }

        // Avoid interactive FS credentials prompts in remote context
        add_filter('filesystem_method', static fn () => 'direct', 100);

        wp_update_plugins();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($file);

        $ok = $result !== false && ! is_wp_error($result);

        return [
            'ok' => $ok,
            'file' => $file,
            'result' => is_wp_error($result) ? $result->get_error_message() : (bool) $result,
            'error' => $ok ? null : (is_wp_error($result) ? $result->get_error_message() : 'Plugin update failed'),
        ];
    }

    public static function update_theme(string $stylesheet): array
    {
        self::bootstrap();
        add_filter('filesystem_method', static fn () => 'direct', 100);

        wp_update_themes();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->upgrade($stylesheet);
        $ok = $result !== false && ! is_wp_error($result);

        return [
            'ok' => $ok,
            'stylesheet' => $stylesheet,
            'result' => is_wp_error($result) ? $result->get_error_message() : (bool) $result,
            'error' => $ok ? null : (is_wp_error($result) ? $result->get_error_message() : 'Theme update failed'),
        ];
    }

    public static function update_core(): array
    {
        self::bootstrap();
        add_filter('filesystem_method', static fn () => 'direct', 100);

        wp_version_check();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $updates = get_site_transient('update_core');
        $offer = null;
        if (is_object($updates) && ! empty($updates->updates) && is_array($updates->updates)) {
            foreach ($updates->updates as $update) {
                if (! empty($update->response) && $update->response === 'upgrade') {
                    $offer = $update;
                    break;
                }
            }
            $offer = $offer ?: ($updates->updates[0] ?? null);
        }
        if (! $offer) {
            return ['ok' => true, 'message' => 'No core update available'];
        }
        $result = $upgrader->upgrade($offer);
        $ok = ! is_wp_error($result);

        return [
            'ok' => $ok,
            'result' => is_wp_error($result) ? $result->get_error_message() : $result,
            'error' => $ok ? null : (is_wp_error($result) ? $result->get_error_message() : 'Core update failed'),
        ];
    }

    /**
     * Apply multiple plugin/theme/core updates with progress (live or staging dry-run).
     *
     * Payload:
     *  - mode: 'live'|'staging'
     *  - items: list of {type: plugin|theme|core, slug?: string}
     */
    public static function update_batch(array $payload): array
    {
        $mode = (($payload['mode'] ?? 'live') === 'staging') ? 'staging' : 'live';
        $items = self::normalize_batch_items($payload);
        if ($items === []) {
            return ['ok' => false, 'error' => 'Keine Updates ausgewählt'];
        }

        if ($mode === 'staging') {
            $status = WWC_Agent_Staging::status();
            if (! ($status['exists'] ?? false)) {
                return ['ok' => false, 'error' => 'Kein Staging – zuerst Development anlegen'];
            }
        }

        $total = count($items);
        WWC_Agent_Job_Progress::report(3, sprintf('%s-Batch: %d Update(s)', $mode === 'staging' ? 'Dry-Run' : 'Live', $total), true);

        $results = [];
        $failed = 0;
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $type = (string) ($item['type'] ?? 'plugin');
            $slug = (string) ($item['slug'] ?? '');
            $label = $type === 'core' ? 'WordPress Core' : ($slug !== '' ? $slug : $type);
            $from = (int) floor(($i / $total) * 90) + 5;
            $to = (int) floor(($n / $total) * 90) + 5;
            WWC_Agent_Job_Progress::pushScope($from, max($from + 1, $to));
            WWC_Agent_Job_Progress::report(0, sprintf('%d/%d: %s (%s)', $n, $total, $label, $mode), true);

            try {
                if ($mode === 'staging') {
                    $result = match ($type) {
                        'theme' => WWC_Agent_Staging::run_update('update_theme', ['slug' => $slug]),
                        'core' => ['ok' => false, 'error' => 'Core Dry-Run nicht unterstützt'],
                        default => WWC_Agent_Staging::run_update('update_plugin', ['slug' => $slug]),
                    };
                } else {
                    $result = match ($type) {
                        'theme' => self::update_theme($slug),
                        'core' => self::update_core(),
                        default => self::update_plugin($slug),
                    };
                }
            } catch (WWC_Agent_Cancelled_Exception $e) {
                // User cancelled the job – abort the whole batch, don't swallow it.
                WWC_Agent_Job_Progress::popScope();
                throw $e;
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }

            $ok = ($result['ok'] ?? false) === true;
            if (! $ok) {
                $failed++;
            }
            WWC_Agent_Job_Progress::log(
                sprintf('%s %s: %s', $ok ? 'OK' : 'FEHLER', $label, $ok ? ($result['message'] ?? 'aktualisiert') : ($result['error'] ?? 'fehlgeschlagen')),
                100,
                true
            );
            WWC_Agent_Job_Progress::popScope();
            $results[] = [
                'type' => $type,
                'slug' => $slug,
                'ok' => $ok,
                'error' => $result['error'] ?? null,
                'message' => $result['message'] ?? null,
            ];
        }

        WWC_Agent_Job_Progress::report(
            98,
            $failed === 0
                ? sprintf('Alle %d Updates erfolgreich', $total)
                : sprintf('%d/%d Updates fehlgeschlagen', $failed, $total),
            true
        );

        return [
            'ok' => $failed === 0,
            'mode' => $mode,
            'total' => $total,
            'failed' => $failed,
            'results' => $results,
            'error' => $failed > 0 ? sprintf('%d von %d Updates fehlgeschlagen', $failed, $total) : null,
        ];
    }

    /**
     * @return list<array{type:string,slug:string}>
     */
    private static function normalize_batch_items(array $payload): array
    {
        $items = [];
        if (is_array($payload['items'] ?? null)) {
            foreach ($payload['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? 'plugin');
                if (! in_array($type, ['plugin', 'theme', 'core'], true)) {
                    $type = 'plugin';
                }
                $items[] = [
                    'type' => $type,
                    'slug' => (string) ($item['slug'] ?? ''),
                ];
            }
        }
        foreach ((array) ($payload['plugins'] ?? []) as $slug) {
            $items[] = ['type' => 'plugin', 'slug' => (string) $slug];
        }
        foreach ((array) ($payload['themes'] ?? []) as $slug) {
            $items[] = ['type' => 'theme', 'slug' => (string) $slug];
        }
        if (! empty($payload['core'])) {
            $items[] = ['type' => 'core', 'slug' => ''];
        }

        // de-dupe
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $key = $item['type'].':'.$item['slug'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        return $unique;
    }

    private static function resolve_plugin_file(string $slugOrFile): ?string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        if (isset($plugins[$slugOrFile])) {
            return $slugOrFile;
        }
        foreach ($plugins as $file => $data) {
            if ($file === $slugOrFile || dirname($file) === $slugOrFile || str_contains($file, $slugOrFile)) {
                return $file;
            }
        }

        return null;
    }
}

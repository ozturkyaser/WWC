<?php

declare(strict_types=1);

/**
 * Stoppt verdächtige Aktionen, wenn das Portal auto_block / Regeln gesetzt hat.
 * Regeln kommen per Heartbeat (Option wwc_agent_guard).
 */
final class WWC_Agent_Guard
{
    private const OPTION = 'wwc_agent_guard';

    private static bool $restoring_theme = false;

    public static function register(): void
    {
        add_filter('pre_insert_user_data', [self::class, 'filter_new_user'], 5, 4);
        add_filter('editable_roles', [self::class, 'filter_roles']);
        add_action('set_user_role', [self::class, 'block_role'], 1, 3);
        add_filter('user_has_cap', [self::class, 'filter_caps'], 20, 4);
        add_action('activate_plugin', [self::class, 'block_plugin_activate'], 1, 1);
        add_filter('upgrader_pre_install', [self::class, 'block_install'], 5, 2);
        add_action('switch_theme', [self::class, 'block_theme'], 1, 3);
        add_filter('map_meta_cap', [self::class, 'block_file_caps'], 20, 2);
        add_action('deleted_user', [self::class, 'note_delete'], 1, 1);
    }

    public static function rules(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge([
            'enabled' => false,
            'auto_block' => false,
            'block' => [],
        ], is_array($stored) ? $stored : []);
    }

    public static function apply(array $rules): void
    {
        update_option(self::OPTION, [
            'enabled' => (bool) ($rules['enabled'] ?? false),
            'auto_block' => (bool) ($rules['auto_block'] ?? false),
            'block' => array_values(array_filter((array) ($rules['block'] ?? []))),
            'updated_at' => gmdate('c'),
        ], false);
    }

    public static function blocks(string $rule): bool
    {
        $r = self::rules();
        if (! ($r['enabled'] && $r['auto_block'])) {
            return false;
        }

        return in_array($rule, $r['block'] ?? [], true);
    }

    public static function filter_new_user($data, bool $update, $userId, $userdata)
    {
        if ($update || ! is_array($data)) {
            return $data;
        }
        $role = (string) ($userdata['role'] ?? ($data['role'] ?? 'subscriber'));
        if ($role === 'administrator' && self::blocks('new_admin')) {
            self::deny('new_admin', 'Anlegen eines Administrators blockiert');
        }

        return $data;
    }

    public static function filter_roles(array $roles): array
    {
        if (self::blocks('role_escalate')) {
            unset($roles['administrator']);
        }

        return $roles;
    }

    public static function block_role(int $userId, string $role, array $old): void
    {
        if ($role !== 'administrator' || ! self::blocks('role_escalate')) {
            return;
        }
        if (in_array('administrator', $old, true)) {
            return;
        }
        $u = get_userdata($userId);
        if ($u) {
            $u->set_role($old[0] ?? 'subscriber');
        }
        WWC_Agent_Activity::log('action_blocked', 'Rollen-Erhöhung zu Administrator gestoppt', 'critical', [
            'rule' => 'role_escalate',
            'target_user_id' => $userId,
        ]);
        wp_die('WWC: Diese Rollenänderung wurde als verdächtig blockiert.', 'Aktion blockiert', ['response' => 403]);
    }

    public static function filter_caps(array $caps, array $required, array $args, $user): array
    {
        if (! self::blocks('plugin_install') && ! self::blocks('file_edit')) {
            return $caps;
        }
        $cap = $args[0] ?? '';
        if (self::blocks('plugin_install') && in_array($cap, ['install_plugins', 'upload_plugins', 'delete_plugins'], true)) {
            $caps['do_not_allow'] = true;
        }
        if (self::blocks('file_edit') && in_array($cap, ['edit_plugins', 'edit_themes', 'edit_files'], true)) {
            $caps['do_not_allow'] = true;
        }

        return $caps;
    }

    public static function block_plugin_activate(string $plugin): void
    {
        if (! self::blocks('plugin_install')) {
            return;
        }
        // Aktivieren bestehender Plugins bleibt erlaubt; nur neue Installationen
        // werden ueber install_plugins-Cap gestoppt.
    }

    public static function block_install($response, array $hookExtra)
    {
        $type = (string) ($hookExtra['type'] ?? '');
        $action = (string) ($hookExtra['action'] ?? '');
        if ($action === 'install' && $type === 'plugin' && self::blocks('plugin_install')) {
            WWC_Agent_Activity::log('action_blocked', 'Plugin-Installation blockiert', 'critical', ['rule' => 'plugin_install']);

            return new WP_Error('wwc_blocked', 'WWC: Plugin-Installation wurde als verdächtig blockiert.');
        }
        if ($action === 'install' && $type === 'theme' && self::blocks('theme_switch')) {
            WWC_Agent_Activity::log('action_blocked', 'Theme-Installation blockiert', 'critical', ['rule' => 'theme_switch']);

            return new WP_Error('wwc_blocked', 'WWC: Theme-Installation wurde blockiert.');
        }

        return $response;
    }

    public static function block_theme(string $theme, $newTheme = null, $oldTheme = null): void
    {
        if (self::$restoring_theme || ! self::blocks('theme_switch')) {
            return;
        }
        if ($oldTheme instanceof WP_Theme) {
            self::$restoring_theme = true;
            switch_theme($oldTheme->get_stylesheet());
            self::$restoring_theme = false;
        }
        WWC_Agent_Activity::log('action_blocked', 'Theme-Wechsel blockiert: '.$theme, 'critical', [
            'rule' => 'theme_switch',
            'theme' => $theme,
        ]);
        wp_die('WWC: Theme-Wechsel wurde als verdächtig blockiert.', 'Aktion blockiert', ['response' => 403]);
    }

    public static function block_file_caps(array $caps, string $cap): array
    {
        if (self::blocks('file_edit') && in_array($cap, ['edit_files', 'edit_plugins', 'edit_themes'], true)) {
            $caps[] = 'do_not_allow';
        }

        return $caps;
    }

    public static function note_delete(int $userId): void
    {
        if (! self::blocks('user_delete_admin')) {
            return;
        }
        // Zu spaet zum Stoppen (Hook nach Loeschung). Nur dokumentieren.
    }

    public static function deny(string $rule, string $message): void
    {
        WWC_Agent_Activity::log('action_blocked', $message, 'critical', ['rule' => $rule]);
        wp_die('WWC: '.$message, 'Aktion blockiert', ['response' => 403]);
    }
}

<?php

declare(strict_types=1);

/**
 * Schreibt ein nachvollziehbares WordPress-Protokoll: wer, was, wann, von wo.
 * Keine Passwoerter. Die Wache (class-guard) kann dieselben Hooks vorher blocken.
 */
final class WWC_Agent_Activity
{
    public static function register(): void
    {
        add_action('wp_login', [self::class, 'login_ok'], 10, 2);
        add_action('wp_logout', [self::class, 'logout']);
        add_action('user_register', [self::class, 'user_created']);
        add_action('deleted_user', [self::class, 'user_deleted'], 10, 3);
        add_action('set_user_role', [self::class, 'role_changed'], 10, 3);
        add_action('add_user_role', [self::class, 'role_added'], 10, 2);
        add_action('profile_update', [self::class, 'profile_updated'], 10, 2);
        add_action('wp_create_application_password', [self::class, 'app_password'], 10, 2);

        add_action('activated_plugin', [self::class, 'plugin_on'], 20, 1);
        add_action('deactivated_plugin', [self::class, 'plugin_off'], 20, 1);
        add_action('deleted_plugin', [self::class, 'plugin_deleted'], 10, 2);
        add_action('upgrader_process_complete', [self::class, 'upgrade'], 20, 2);

        add_action('switch_theme', [self::class, 'theme_switched'], 20, 1);
        add_action('customize_save_after', [self::class, 'customizer']);

        add_action('wp_insert_post', [self::class, 'post_saved'], 20, 3);
        add_action('deleted_post', [self::class, 'post_deleted'], 10, 2);
        add_action('wp_trash_post', [self::class, 'post_trashed']);

        add_action('updated_option', [self::class, 'option_updated'], 10, 3);
        add_action('add_option', [self::class, 'option_added'], 10, 2);

        add_action('wp_update_nav_menu', [self::class, 'menu_updated']);
    }

    public static function actor(): array
    {
        $user = wp_get_current_user();
        $id = ($user instanceof WP_User && $user->ID) ? (int) $user->ID : 0;

        return [
            'user_id' => $id ?: null,
            'user_login' => $id ? (string) $user->user_login : null,
            'user_email' => $id ? (string) $user->user_email : null,
            'roles' => $id ? array_values((array) $user->roles) : [],
            'ip' => self::ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 240) : null,
        ];
    }

    public static function log(string $type, string $title, string $severity = 'info', array $payload = []): void
    {
        WWC_Agent_Event_Queue::push($type, $title, $severity, array_merge(self::actor(), $payload));
    }

    public static function login_ok(string $login, $user): void
    {
        $uid = $user instanceof WP_User ? (int) $user->ID : 0;
        self::log('user_login', 'Anmeldung: '.$login, 'info', [
            'user_id' => $uid ?: null,
            'user_login' => $login,
            'roles' => $user instanceof WP_User ? array_values((array) $user->roles) : [],
        ]);
    }

    public static function logout($userId = null): void
    {
        self::log('user_logout', 'Abmeldung', 'info', ['target_user_id' => $userId]);
    }

    public static function user_created(int $userId): void
    {
        $u = get_userdata($userId);
        $roles = $u ? array_values((array) $u->roles) : [];
        $admin = in_array('administrator', $roles, true);
        self::log(
            'user_created',
            'Benutzer angelegt: '.($u->user_login ?? $userId),
            $admin ? 'warning' : 'info',
            ['target_user_id' => $userId, 'target_login' => $u->user_login ?? null, 'target_roles' => $roles]
        );
    }

    public static function user_deleted(int $userId, $reassign, $user): void
    {
        $login = $user instanceof WP_User ? $user->user_login : (string) $userId;
        self::log('user_deleted', 'Benutzer gelöscht: '.$login, 'warning', [
            'target_user_id' => $userId,
            'target_login' => $login,
        ]);
    }

    public static function role_changed(int $userId, string $role, array $old = []): void
    {
        $u = get_userdata($userId);
        $escalate = $role === 'administrator' && ! in_array('administrator', $old, true);
        self::log(
            'user_role',
            'Rolle geändert: '.($u->user_login ?? $userId).' → '.$role,
            $escalate ? 'critical' : 'warning',
            ['target_user_id' => $userId, 'new_role' => $role, 'old_roles' => array_values($old)]
        );
    }

    public static function role_added(int $userId, string $role): void
    {
        if ($role !== 'administrator') {
            return;
        }
        $u = get_userdata($userId);
        self::log('user_role', 'Administrator-Rolle vergeben: '.($u->user_login ?? $userId), 'critical', [
            'target_user_id' => $userId,
            'new_role' => $role,
        ]);
    }

    public static function profile_updated(int $userId, $old): void
    {
        $u = get_userdata($userId);
        self::log('user_updated', 'Profil geändert: '.($u->user_login ?? $userId), 'info', [
            'target_user_id' => $userId,
        ]);
    }

    public static function app_password($userId, $item): void
    {
        self::log('app_password', 'Application Password erzeugt', 'warning', [
            'target_user_id' => is_object($userId) ? ($userId->ID ?? null) : $userId,
        ]);
    }

    public static function plugin_on(string $plugin): void
    {
        self::log('plugin_activated', 'Plugin aktiviert: '.$plugin, 'info', ['plugin' => $plugin]);
    }

    public static function plugin_off(string $plugin): void
    {
        self::log('plugin_deactivated', 'Plugin deaktiviert: '.$plugin, 'info', ['plugin' => $plugin]);
    }

    public static function plugin_deleted(string $plugin, bool $ok): void
    {
        self::log('plugin_deleted', 'Plugin gelöscht: '.$plugin, 'warning', ['plugin' => $plugin, 'ok' => $ok]);
    }

    public static function upgrade($upgrader, array $options): void
    {
        $type = (string) ($options['type'] ?? '');
        $action = (string) ($options['action'] ?? '');
        $severity = $action === 'install' ? 'warning' : 'info';
        self::log('wp_upgrade', trim($action.' '.$type).' abgeschlossen', $severity, [
            'type' => $type,
            'action' => $action,
            'plugins' => $options['plugins'] ?? null,
            'themes' => $options['themes'] ?? null,
        ]);
    }

    public static function theme_switched(string $theme): void
    {
        self::log('theme_switched', 'Theme gewechselt: '.$theme, 'warning', ['theme' => $theme]);
    }

    public static function customizer($wpCustomize): void
    {
        self::log('customizer', 'Customizer gespeichert', 'info');
    }

    public static function post_saved(int $postId, $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (! $post instanceof WP_Post || in_array($post->post_type, ['nav_menu_item', 'customize_changeset', 'revision'], true)) {
            return;
        }
        if ($post->post_status === 'auto-draft') {
            return;
        }
        $verb = $update ? 'aktualisiert' : 'angelegt';
        $sev = $post->post_type === 'page' && ! $update ? 'info' : 'info';
        self::log('content_'.$post->post_status, $post->post_type.' '.$verb.': '.$post->post_title, $sev, [
            'post_id' => $postId,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'update' => $update,
        ]);
    }

    public static function post_deleted(int $postId, $post): void
    {
        $type = $post instanceof WP_Post ? $post->post_type : 'post';
        $title = $post instanceof WP_Post ? $post->post_title : (string) $postId;
        self::log('content_deleted', $type.' gelöscht: '.$title, 'warning', [
            'post_id' => $postId,
            'post_type' => $type,
        ]);
    }

    public static function post_trashed(int $postId): void
    {
        $post = get_post($postId);
        self::log('content_trashed', 'In den Papierkorb: '.($post->post_title ?? $postId), 'info', [
            'post_id' => $postId,
        ]);
    }

    public static function option_updated(string $option, $old, $new): void
    {
        if (! self::is_sensitive_option($option)) {
            return;
        }
        self::log('option_updated', 'Einstellung geändert: '.$option, 'warning', [
            'option' => $option,
        ]);
    }

    public static function option_added(string $option, $value): void
    {
        if (! self::is_sensitive_option($option)) {
            return;
        }
        self::log('option_updated', 'Einstellung gesetzt: '.$option, 'warning', ['option' => $option]);
    }

    public static function menu_updated($id): void
    {
        self::log('menu_updated', 'Menü geändert', 'info', ['menu_id' => $id]);
    }

    private static function is_sensitive_option(string $option): bool
    {
        $watch = [
            'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role',
            'permalink_structure', 'active_plugins', 'template', 'stylesheet',
            'WPLANG', 'blog_public', 'upload_path',
        ];

        return in_array($option, $watch, true) || str_starts_with($option, 'wwc_');
    }

    private static function ip(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }
}

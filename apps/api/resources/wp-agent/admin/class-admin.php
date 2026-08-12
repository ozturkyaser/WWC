<?php

declare(strict_types=1);

final class WWC_Agent_Admin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wwc_agent_pair', [self::class, 'handle_pair']);
        add_action('admin_post_wwc_agent_disconnect', [self::class, 'handle_disconnect']);
        add_action('admin_post_wwc_agent_sync', [self::class, 'handle_sync']);
    }

    public static function menu(): void
    {
        add_options_page('WWC Agent', 'WWC Agent', 'manage_options', 'wwc-agent', [self::class, 'render']);
    }

    private static function default_api_url(): string
    {
        if (defined('WWC_AGENT_API_URL') && is_string(WWC_AGENT_API_URL) && WWC_AGENT_API_URL !== '') {
            return WWC_AGENT_API_URL;
        }

        // Prefer LAN IP – works from LocalWP, VMs, and most Docker setups.
        // Override in wp-config.php: define('WWC_AGENT_API_URL', 'http://...');
        return 'http://192.168.1.30:8081';
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $cfg = WWC_Agent_Config::all();
        $paired = WWC_Agent_Config::is_paired();
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash((string) $_GET['error'])) : '';
        $notice = isset($_GET['notice']) ? sanitize_text_field(wp_unslash((string) $_GET['notice'])) : '';
        $pairedFlag = isset($_GET['paired']);
        ?>
        <div class="wrap">
            <h1>WWC Agent</h1>

            <?php if ($error !== ''): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>
            <?php if ($notice !== ''): ?>
                <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <?php if ($pairedFlag): ?>
                <div class="notice notice-success"><p>Pairing erfolgreich. Site ist mit dem WWC-Portal verbunden.</p></div>
            <?php endif; ?>

            <?php if ($paired): ?>
                <div class="notice notice-success"><p>Verbunden mit Site-ID <code><?php echo esc_html($cfg['site_id']); ?></code></p></div>
                <p>API: <code><?php echo esc_html($cfg['api_url']); ?></code></p>
                <p>Agent-Version: <code><?php echo esc_html(WWC_AGENT_VERSION); ?></code>
                    <?php
                    $latest = WWC_Agent_Self_Updater::fetch_latest_public();
                    if (is_array($latest) && ! empty($latest['version']) && version_compare(WWC_AGENT_VERSION, (string) $latest['version'], '<')):
                    ?>
                        <span class="wwc-update-badge" style="margin-left:8px;padding:2px 8px;border-radius:999px;background:#d63638;color:#fff;font-size:12px;">
                            Update <?php echo esc_html((string) $latest['version']); ?> verfügbar
                        </span>
                    <?php endif; ?>
                </p>
                <?php if (! empty($cfg['last_error'])): ?>
                    <div class="notice notice-warning"><p>Letzter Sync-Fehler: <?php echo esc_html((string) $cfg['last_error']); ?></p></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('wwc_agent_sync'); ?>
                    <input type="hidden" name="action" value="wwc_agent_sync">
                    <button class="button button-primary">Jetzt synchronisieren</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('wwc_agent_self_update'); ?>
                    <input type="hidden" name="action" value="wwc_agent_self_update">
                    <button class="button">Agent aktualisieren</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <?php wp_nonce_field('wwc_agent_disconnect'); ?>
                    <input type="hidden" name="action" value="wwc_agent_disconnect">
                    <button class="button button-secondary">Verbindung trennen / neu pairen</button>
                </form>
                <p class="description" style="margin-top:12px;">Updates erscheinen auch unter <strong>Plugins → Installierte Plugins</strong>. Nach Portal-Release aktualisiert der Agent sich beim nächsten Heartbeat automatisch.</p>
            <?php else: ?>
                <p><strong>Wichtig:</strong> Nicht <code>localhost</code> verwenden, wenn WordPress in LocalWP, Docker oder einer VM läuft.</p>
                <p>Empfohlen: LAN-IP deines Macs, z. B. <code>http://192.168.1.30:8081</code></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wwc_agent_pair'); ?>
                    <input type="hidden" name="action" value="wwc_agent_pair">
                    <table class="form-table">
                        <tr>
                            <th><label for="api_url">API URL</label></th>
                            <td>
                                <input class="regular-text" type="url" name="api_url" id="api_url" value="<?php echo esc_attr(self::default_api_url()); ?>" required>
                                <p class="description">
                                    Alternativen:<br>
                                    Docker Desktop: <code>http://172.17.0.1:8081</code> oder <code>http://host.docker.internal:8081</code><br>
                                    WWC-Compose WordPress: <code>http://host.docker.internal:8081</code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="code">Pairing-Code</label></th>
                            <td><input class="regular-text" type="text" name="code" id="code" required placeholder="XXXXXX-XXXXXX" autocomplete="off"></td>
                        </tr>
                    </table>
                    <?php submit_button('Verbinden & synchronisieren'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_pair(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_pair');

        // Don't use esc_url_raw here – it can mangle non-public hosts like host.docker.internal in some WP versions.
        $api = trim((string) wp_unslash($_POST['api_url'] ?? ''));
        $code = sanitize_text_field((string) wp_unslash($_POST['code'] ?? ''));

        $result = WWC_Agent_Api_Client::pair($api, $code);
        if (is_wp_error($result)) {
            self::redirect(['error' => $result->get_error_message()]);
        }

        $sync = WWC_Agent_Heartbeat::send(true);
        if (is_wp_error($sync)) {
            WWC_Agent_Config::update(['last_error' => $sync->get_error_message()]);
            self::redirect([
                'paired' => '1',
                'error' => 'Verbunden, aber Sync fehlgeschlagen: '.$sync->get_error_message(),
            ]);
        }

        WWC_Agent_Config::update(['last_error' => '']);
        self::redirect(['paired' => '1', 'notice' => 'Verbunden und synchronisiert.']);
    }

    public static function handle_sync(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_sync');

        $sync = WWC_Agent_Heartbeat::send(true);
        if (is_wp_error($sync)) {
            WWC_Agent_Config::update(['last_error' => $sync->get_error_message()]);
            self::redirect(['error' => 'Sync fehlgeschlagen: '.$sync->get_error_message()]);
        }

        WWC_Agent_Config::update(['last_error' => '']);
        self::redirect(['notice' => 'Sync erfolgreich.']);
    }

    public static function handle_disconnect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_disconnect');
        WWC_Agent_Config::clear();
        self::redirect(['notice' => 'Verbindung getrennt. Du kannst erneut pairen.']);
    }

    private static function redirect(array $args): void
    {
        $args['page'] = 'wwc-agent';
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }
}

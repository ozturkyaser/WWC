<?php

declare(strict_types=1);

final class WWC_Agent_Admin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wwc_agent_pair', [self::class, 'handle_pair']);
        add_action('admin_post_wwc_agent_support', [self::class, 'handle_support']);
        add_action('admin_post_wwc_agent_support_revoke', [self::class, 'handle_support_revoke']);
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
            return rtrim(WWC_AGENT_API_URL, '/');
        }
        if (defined('WWC_AGENT_DEFAULT_API_URL') && is_string(WWC_AGENT_DEFAULT_API_URL) && WWC_AGENT_DEFAULT_API_URL !== '') {
            return rtrim(WWC_AGENT_DEFAULT_API_URL, '/');
        }

        return 'https://wwc.kiservicehub.de';
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
                <?php if (! empty($cfg['support_mode'])): ?>
                    <div class="notice notice-info">
                        <p><strong>Support-Zugang ist aktiv.</strong> WWC darf warten, Backups und Inhalte nur für den Support nutzen. Du kannst das jederzeit beenden.</p>
                    </div>
                <?php endif; ?>
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
                <?php if (! empty($cfg['support_mode'])): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;" onsubmit="return confirm('Support-Zugang wirklich beenden? WWC verliert den Zugriff.');">
                    <?php wp_nonce_field('wwc_agent_support_revoke'); ?>
                    <input type="hidden" name="action" value="wwc_agent_support_revoke">
                    <button class="button button-secondary">Support beenden</button>
                </form>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <?php wp_nonce_field('wwc_agent_disconnect'); ?>
                    <input type="hidden" name="action" value="wwc_agent_disconnect">
                    <button class="button button-secondary">Verbindung trennen / neu pairen</button>
                </form>
                <p class="description" style="margin-top:12px;">Updates erscheinen auch unter <strong>Plugins → Installierte Plugins</strong>. Nach Portal-Release aktualisiert der Agent sich beim nächsten Heartbeat automatisch.</p>
            <?php else: ?>
                <p>Standard-API-URL ist das WWC-Portal: <code><?php echo esc_html(self::default_api_url()); ?></code></p>

                <h2>Support freigeben</h2>
                <p>Ohne Pairing-Code. Danach erscheint diese Website im WWC-Wartungsportal, und der Support kann helfen.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wwc_agent_support'); ?>
                    <input type="hidden" name="action" value="wwc_agent_support">
                    <table class="form-table">
                        <tr>
                            <th><label for="support_api_url">API URL</label></th>
                            <td>
                                <input class="regular-text" type="url" name="api_url" id="support_api_url" value="<?php echo esc_attr(self::default_api_url()); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="support_contact">Kontakt (optional)</label></th>
                            <td><input class="regular-text" type="text" name="contact" id="support_contact" placeholder="Name oder E-Mail"></td>
                        </tr>
                        <tr>
                            <th><label for="support_note">Anliegen (optional)</label></th>
                            <td><input class="regular-text" type="text" name="note" id="support_note" placeholder="z. B. Backup hängt, SEO prüfen" maxlength="500"></td>
                        </tr>
                    </table>
                    <?php submit_button('Für WWC-Support freigeben', 'primary', 'submit', false); ?>
                </form>

                <hr>
                <h2>Mit Pairing-Code verbinden</h2>
                <p>Nur wenn ihr bereits eine Site im Portal angelegt und einen Code bekommen habt.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wwc_agent_pair'); ?>
                    <input type="hidden" name="action" value="wwc_agent_pair">
                    <table class="form-table">
                        <tr>
                            <th><label for="api_url">API URL</label></th>
                            <td>
                                <input class="regular-text" type="url" name="api_url" id="api_url" value="<?php echo esc_attr(self::default_api_url()); ?>" required>
                                <p class="description">Für Kundenseiten immer die Portal-URL, nicht localhost oder eine LAN-IP.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="code">Pairing-Code</label></th>
                            <td><input class="regular-text" type="text" name="code" id="code" required placeholder="XXXXXX-XXXXXX" autocomplete="off"></td>
                        </tr>
                    </table>
                    <?php submit_button('Verbinden'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_pair(): void
    {
        self::silence_debug_output();
        @ini_set('memory_limit', '512M');
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_pair');

        $api = trim((string) wp_unslash($_POST['api_url'] ?? ''));
        $code = sanitize_text_field((string) wp_unslash($_POST['code'] ?? ''));

        try {
            $result = WWC_Agent_Api_Client::pair($api, $code);
        } catch (\Throwable $e) {
            self::redirect(['error' => 'Pairing abgebrochen: '.$e->getMessage()]);
        }
        if (is_wp_error($result)) {
            self::redirect(['error' => $result->get_error_message()]);
        }
        if (! WWC_Agent_Config::is_paired()) {
            self::redirect(['error' => 'Pairing am Portal ok, Schlüssel konnte lokal nicht gespeichert werden. Bitte erneut verbinden.']);
        }

        self::redirect(['paired' => '1', 'notice' => 'Verbunden. Updates und Backups erscheinen nach dem nächsten Sync im Portal.']);
    }

    public static function handle_support(): void
    {
        self::silence_debug_output();
        @ini_set('memory_limit', '512M');
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_support');

        $api = trim((string) wp_unslash($_POST['api_url'] ?? ''));
        $contact = sanitize_text_field((string) wp_unslash($_POST['contact'] ?? ''));
        $note = sanitize_text_field((string) wp_unslash($_POST['note'] ?? ''));

        try {
            $result = WWC_Agent_Api_Client::grant_support($api, [
                'contact' => $contact,
                'note' => $note,
            ]);
        } catch (\Throwable $e) {
            self::redirect(['error' => 'Freigabe abgebrochen: '.$e->getMessage()]);
        }
        if (is_wp_error($result)) {
            self::redirect(['error' => $result->get_error_message()]);
        }
        if (! WWC_Agent_Config::is_paired()) {
            self::redirect(['error' => 'Freigabe am Portal ok, Schlüssel konnte lokal nicht gespeichert werden.']);
        }

        self::redirect(['notice' => 'Support-Zugang ist aktiv. Die Website erscheint jetzt im WWC-Portal.']);
    }

    public static function handle_support_revoke(): void
    {
        self::silence_debug_output();
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_support_revoke');
        $result = WWC_Agent_Api_Client::revoke_support();
        if (is_wp_error($result)) {
            WWC_Agent_Config::clear();
            self::redirect(['error' => 'Lokal getrennt. Portal: '.$result->get_error_message()]);
        }
        self::redirect(['notice' => 'Support-Zugang beendet. WWC hat keinen Zugriff mehr.']);
    }

    public static function handle_sync(): void
    {
        self::silence_debug_output();
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
        self::silence_debug_output();
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_disconnect');
        WWC_Agent_Config::clear();
        self::redirect(['notice' => 'Verbindung getrennt. Du kannst erneut pairen.']);
    }

    private static function silence_debug_output(): void
    {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
    }

    private static function redirect(array $args): void
    {
        $args['page'] = 'wwc-agent';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }
}

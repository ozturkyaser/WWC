<?php

return [
    'agent_version' => env('WWC_AGENT_VERSION', '0.6.10'),
    'repo_path' => env('WWC_REPO_PATH', base_path('../..')),
    'deploy_remote' => env('WWC_DEPLOY_REMOTE', 'origin'),
    'deploy_branch' => env('WWC_DEPLOY_BRANCH', 'main'),
    'deploy_command' => env('WWC_DEPLOY_COMMAND', '/usr/local/bin/wwc-deploy'),
    'agent_update_public_key' => env('WWC_AGENT_UPDATE_PUBLIC_KEY'),
    // Public URL WordPress sites should use (LAN IP recommended for local WP/VMs)
    'public_api_url' => env('WWC_PUBLIC_API_URL', env('APP_URL', 'http://localhost:8081')),
    // Portal base used to build staging subdomains: {slug}.dev.{portal-host}
    'portal_url' => env('WWC_PORTAL_URL', 'http://localhost:3000'),
    'staging_subdomain_suffix' => env('WWC_STAGING_SUBDOMAIN_SUFFIX', 'dev'),

    // Off-site backups: how many stored full backups to keep per site on the WWC server
    'backups_keep_per_site' => (int) env('WWC_BACKUPS_KEEP_PER_SITE', 5),

    // Nach wie vielen Minuten ohne Heartbeat eine Site als offline gilt
    'offline_after_minutes' => (int) env('WWC_OFFLINE_AFTER_MINUTES', 15),

    /*
    | Dev-Clones: Kopien der Kundensites aus Off-Site-Backups, gehostet auf dem
    | WWC-Server in eigenen Docker-Stacks. Belastet den Kundenserver nicht.
    */
    'clones_host_dir' => env('WWC_CLONES_HOST_DIR'),
    'clone_base_url' => env('WWC_CLONE_BASE_URL', 'http://localhost'),
    'clone_port_min' => (int) env('WWC_CLONE_PORT_MIN', 9100),
    'clone_port_max' => (int) env('WWC_CLONE_PORT_MAX', 9299),

    // Optional: OpenAI-compatible API for Impressum-Extraktion
    'ai_api_key' => env('WWC_AI_API_KEY', env('OPENAI_API_KEY')),
    'ai_api_base' => env('WWC_AI_API_BASE', 'https://api.openai.com/v1'),
    'ai_model' => env('WWC_AI_MODEL', 'gpt-4o-mini'),

    /*
    | Wartungsstufen (Monatsabo). Preise in Cent.
    | scope wird mit Project::DEFAULT_SCOPE gemerged.
    */
    'maintenance_tiers' => [
        '1' => [
            'key' => '1',
            'label' => '1. Stufe',
            'description' => 'Updates überwachen, Security-Scan, Login-Monitoring',
            'monthly_cents' => (int) env('WWC_TIER1_CENTS', 9900),
            'scope' => [
                'core_updates' => true,
                'plugin_updates' => true,
                'theme_updates' => true,
                'security_scans' => true,
                'failed_login_monitoring' => true,
                'uptime' => true,
                'backup_verify' => false,
                'auto_apply_safe_updates' => false,
                'hours_included' => 1,
            ],
        ],
        '2' => [
            'key' => '2',
            'label' => '2. Stufe',
            'description' => 'Alles aus Stufe 1 + sichere Auto-Updates + mehr Support',
            'monthly_cents' => (int) env('WWC_TIER2_CENTS', 14900),
            'scope' => [
                'core_updates' => true,
                'plugin_updates' => true,
                'theme_updates' => true,
                'security_scans' => true,
                'failed_login_monitoring' => true,
                'uptime' => true,
                'backup_verify' => true,
                'auto_apply_safe_updates' => true,
                'hours_included' => 2,
            ],
        ],
        '3' => [
            'key' => '3',
            'label' => '3. Stufe',
            'description' => 'Vollwartung inkl. Staging/Dev, Priorität und erweitertem Support',
            'monthly_cents' => (int) env('WWC_TIER3_CENTS', 24900),
            'scope' => [
                'core_updates' => true,
                'plugin_updates' => true,
                'theme_updates' => true,
                'security_scans' => true,
                'failed_login_monitoring' => true,
                'uptime' => true,
                'backup_verify' => true,
                'auto_apply_safe_updates' => true,
                'hours_included' => 4,
            ],
        ],
        'custom' => [
            'key' => 'custom',
            'label' => 'Custom',
            'description' => 'Individueller Monatspreis und Umfang',
            'monthly_cents' => null,
            'scope' => [
                'core_updates' => true,
                'plugin_updates' => true,
                'theme_updates' => true,
                'security_scans' => true,
                'failed_login_monitoring' => true,
                'uptime' => true,
                'backup_verify' => true,
                'auto_apply_safe_updates' => false,
                'hours_included' => 2,
            ],
        ],
    ],
];

<?php
/**
 * Plugin Name: WWC Agent Quiet Bootstrap
 * Description: Unterdrückt Debug-Notices auf WWC-Agent-Requests, damit Pairing und REST trotz WP_DEBUG funktionieren.
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

$wwcAction = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
$wwcUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$wwcQuiet = str_contains($wwcUri, '/wp-json/wwc/')
    || str_contains($wwcUri, 'rest_route=/wwc/')
    || str_contains($wwcUri, 'rest_route=%2Fwwc')
    || in_array($wwcAction, ['wwc_agent_pair', 'wwc_agent_disconnect', 'wwc_agent_sync', 'wwc_agent_self_update'], true);

if (! $wwcQuiet) {
    return;
}

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
if (ob_get_level() === 0) {
    ob_start();
}

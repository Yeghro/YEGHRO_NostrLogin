<?php
/*
Plugin Name: YEGHRO Nostr Login
Plugin URI: https://github.com/Yeghro/YEGHRO_NostrLogin
Description: A plugin that allows users to log in using Nostr.
Version: 1.1
Author: Your YEGHRO
Author URI: https://YEGHRO.site/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: YEGHROnostrLogin
*/


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/class-nostr-login.php';

function nostr_login_plugin_init() {
    $nostr_login = new Nostr_Login_Handler();
    $nostr_login->init();
}
add_action('plugins_loaded', 'nostr_login_plugin_init');

function nostr_login_use_avatar_url($url, $id_or_email, $args) {
    $user = false;
    if (is_numeric($id_or_email)) {
        $user = get_user_by('id', $id_or_email);
    } elseif (is_object($id_or_email)) {
        if (!empty($id_or_email->user_id)) {
            $user = get_user_by('id', $id_or_email->user_id);
        }
    } else {
        $user = get_user_by('email', $id_or_email);
    }

    if ($user && is_object($user)) {
        $nostr_avatar = get_user_meta($user->ID, 'nostr_avatar', true);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Attempting to use Nostr avatar for user {$user->ID}: " . $nostr_avatar);
        }
        if ($nostr_avatar) {
            return $nostr_avatar;
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Using default avatar URL: " . $url);
    }
    return $url;
}
add_filter('get_avatar_url', 'nostr_login_use_avatar_url', 10, 3);

// Load plugin text domain
function nostr_login_load_textdomain() {
    load_plugin_textdomain('nostr-login', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'nostr_login_load_textdomain');

// Add a debug logging function
function nostr_login_debug_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Nostr Login: ' . $message);
    }
}

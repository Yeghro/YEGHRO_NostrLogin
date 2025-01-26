<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use swentel\nostr\Event\Event;

class Nostr_Import_Handler {
    private $ndk_initialized = false;
    private $default_relays;
    private $errors = [];

    public function __construct() {
        // Get relay list from settings
        $this->default_relays = $this->get_relay_urls();
    }

    public function init() {
        // Add admin menu item under Tools
        add_action('admin_menu', array($this, 'add_import_menu'));
        // Add AJAX handlers
        add_action('wp_ajax_nostr_import_preview', array($this, 'ajax_import_preview'));
        add_action('wp_ajax_nostr_import_posts', array($this, 'ajax_import_posts'));
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    private function get_relay_urls() {
        $relays_option = get_option('nostr_login_relays', '');
        $relays_array = explode("\n", $relays_option);
        return array_filter(array_map('esc_url', array_map('trim', $relays_array)));
    }

    public function add_import_menu() {
        add_management_page(
            __('Import from Nostr', 'nostr-login'),
            __('Nostr Import', 'nostr-login'),
            'manage_options',
            'nostr-import',
            array($this, 'render_import_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('tools_page_nostr-import' !== $hook) {
            return;
        }

        // Enqueue our built import script from assets directory
        wp_enqueue_script(
            'nostr-import',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/nostr-imports.min.js',
            array('jquery'),
            '1.0',
            true
        );

        wp_localize_script('nostr-import', 'nostrImport', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nostr-import-nonce'),
            'relays' => $this->default_relays
        ));
    }

    public function render_import_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import from Nostr', 'nostr-login'); ?></h1>
            
            <div class="nostr-import-form">
                <form id="nostr-import-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="author_pubkey"><?php esc_html_e('Author Public Key', 'nostr-login'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="author_pubkey" name="author_pubkey" class="regular-text" />
                                <p class="description"><?php esc_html_e('Enter the Nostr public key (hex or npub) of the author', 'nostr-login'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Date Range', 'nostr-login'); ?></th>
                            <td>
                                <label>
                                    <input type="date" id="date_from" name="date_from" />
                                    <?php esc_html_e('From', 'nostr-login'); ?>
                                </label>
                                <label>
                                    <input type="date" id="date_to" name="date_to" />
                                    <?php esc_html_e('To', 'nostr-login'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Import Options', 'nostr-login'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="import_replies" value="1" />
                                    <?php esc_html_e('Import replies', 'nostr-login'); ?>
                                </label>
                                <br />
                                <label>
                                    <input type="checkbox" name="import_mentions" value="1" />
                                    <?php esc_html_e('Import mentions', 'nostr-login'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary" id="preview-import">
                            <?php esc_html_e('Preview Import', 'nostr-login'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <div id="import-preview" style="display: none;">
                <h2><?php esc_html_e('Preview', 'nostr-login'); ?></h2>
                <div id="preview-content"></div>
                <p>
                    <button type="button" class="button button-primary" id="start-import">
                        <?php esc_html_e('Start Import', 'nostr-login'); ?>
                    </button>
                </p>
            </div>

            <div id="import-progress" style="display: none;">
                <h2><?php esc_html_e('Import Progress', 'nostr-login'); ?></h2>
                <div class="progress-bar">
                    <div class="progress-bar-fill"></div>
                </div>
                <p id="import-status"></p>
            </div>
        </div>
        <?php
    }

    public function ajax_import_preview() {
        check_ajax_referer('nostr-import-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access', 'nostr-login')]);
        }

        $pubkey = sanitize_text_field($_POST['author_pubkey']);
        $date_from = sanitize_text_field($_POST['date_from']);
        $date_to = sanitize_text_field($_POST['date_to']);
        
        try {
            $events = $this->fetch_nostr_events($pubkey, $date_from, $date_to);
            wp_send_json_success([
                'events' => $events,
                'count' => count($events)
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function fetch_nostr_events($pubkey, $date_from, $date_to) {
        // This is a placeholder - you'll need to implement the actual NDK fetching logic
        // This will be implemented in the next part
        return [];
    }

    public function ajax_import_posts() {
        check_ajax_referer('nostr-import-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access', 'nostr-login')]);
        }

        $event_data = json_decode(stripslashes($_POST['event']), true);
        
        if (!$event_data) {
            wp_send_json_error(['message' => __('Invalid event data', 'nostr-login')]);
        }

        try {
            $post_id = $this->import_event($event_data);
            wp_send_json_success([
                'post_id' => $post_id,
                'message' => sprintf(__('Successfully imported post #%d', 'nostr-login'), $post_id)
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function import_event($event) {
        // Create post array
        $post_data = array(
            'post_title' => wp_trim_words($event['content'], 10, '...'),
            'post_content' => wp_kses_post($event['content']),
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_type' => 'post',
            'post_date' => date('Y-m-d H:i:s', $event['created_at'])
        );

        // Insert post
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            throw new Exception($post_id->get_error_message());
        }

        // Store Nostr metadata
        update_post_meta($post_id, '_nostr_event_id', sanitize_text_field($event['id']));
        update_post_meta($post_id, '_nostr_pubkey', sanitize_text_field($event['pubkey']));
        
        // Handle tags
        if (!empty($event['tags'])) {
            $this->process_tags($post_id, $event['tags']);
        }

        return $post_id;
    }

    private function process_tags($post_id, $tags) {
        $wp_tags = array();
        
        foreach ($tags as $tag) {
            if ($tag[0] === 't') { // Hashtag
                $wp_tags[] = sanitize_text_field($tag[1]);
            }
            // Store other tag types as post meta
            update_post_meta(
                $post_id, 
                '_nostr_tag_' . sanitize_key($tag[0]), 
                sanitize_text_field($tag[1])
            );
        }

        if (!empty($wp_tags)) {
            wp_set_post_tags($post_id, $wp_tags, true);
        }
    }
} 
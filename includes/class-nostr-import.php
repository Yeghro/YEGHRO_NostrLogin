<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

class Nostr_Import_Handler {
    private $ndk_initialized = false;
    private $default_relays;
    private $errors = [];
    private const BATCH_SIZE = 50;
    private $relay_set;

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
        // Add filter for content display
        add_filter('the_content', array($this, 'maybe_add_nostr_images'), 20);
    }

    private function initialize_client() {
        if ($this->ndk_initialized) {
            return;
        }

        try {
            // Check if required classes exist
            if (!class_exists('swentel\nostr\Relay\Relay')) {
                throw new Exception('Required Nostr PHP library not found. Please ensure dependencies are installed.');
            }

            // Initialize RelaySet with all configured relays
            $relays = [];
            foreach ($this->default_relays as $relay_url) {
                nostr_login_debug_log("Adding relay: " . $relay_url);
                $relays[] = new Relay($relay_url);
            }
            
            $this->relay_set = new RelaySet();
            $this->relay_set->setRelays($relays);
            $this->ndk_initialized = true;
            
            nostr_login_debug_log("Successfully initialized relay set");
        } catch (Exception $e) {
            nostr_login_debug_log("Failed to initialize client: " . $e->getMessage());
            throw new Exception('Failed to initialize client: ' . $e->getMessage());
        }
    }

    private function get_relay_urls() {
        $relays_option = get_option('nostr_login_relays', '');
        $relays_array = explode("\n", $relays_option);
        $relays = array_filter(array_map('esc_url', array_map('trim', $relays_array)));
        
        // Use default relays if none are configured
        if (empty($relays)) {
            $relays = [
                "wss://purplepag.es",
                "wss://relay.nostr.band",
                "wss://relay.primal.net",
                "wss://relay.damus.io",
                "wss://nostr.wine"
            ];
        }
        
        return $relays;
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

        wp_enqueue_script('jquery');
        wp_enqueue_style('wp-components'); // For WordPress styling

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
                        <tr>
                            <th scope="row"><?php esc_html_e('Content Filters', 'nostr-login'); ?></th>
                            <td>
                                <label>
                                    <input type="text" name="tag_filter" />
                                    <?php esc_html_e('Filter by hashtag', 'nostr-login'); ?>
                                </label>
                                <br />
                                <label>
                                    <input type="checkbox" name="include_reposts" value="1" />
                                    <?php esc_html_e('Include reposts', 'nostr-login'); ?>
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
        $tag_filter = !empty($_POST['tag_filter']) ? sanitize_text_field($_POST['tag_filter']) : '';
        
        try {
            nostr_login_debug_log("Import preview request - Pubkey: $pubkey, Tag filter: $tag_filter");
            
            $events = $this->fetch_nostr_events($pubkey, $date_from, $date_to, $tag_filter);
            
            wp_send_json_success([
                'events' => $events,
                'count' => count($events)
            ]);
        } catch (Exception $e) {
            nostr_login_debug_log("Error in import preview: " . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function fetch_nostr_events($pubkey, $date_from, $date_to, $tag_filter = '') {
        try {
            // Since we're having issues with the PHP library, let's use the JavaScript NDK instead
            wp_send_json_success([
                'use_js_ndk' => true,
                'params' => [
                    'pubkey' => $pubkey,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                    'tag_filter' => $tag_filter
                ]
            ]);
            
        } catch (Exception $e) {
            nostr_login_debug_log("Error fetching events: " . $e->getMessage());
            throw new Exception('Failed to fetch events: ' . $e->getMessage());
        }
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
        try {
            // Validate required event data
            if (empty($event['id']) || empty($event['content']) || empty($event['created_at'])) {
                throw new Exception('Missing required event data');
            }

            // Check if post already exists
            if ($existing_id = $this->get_post_by_event_id($event['id'])) {
                throw new Exception("Post already exists with ID: {$existing_id}");
            }

            // Handle threading
            $parent_id = null;
            foreach ($event['tags'] as $tag) {
                if ($tag[0] === 'e' && !empty($tag[1])) {
                    $parent_id = $this->get_post_by_event_id($tag[1]);
                    break;
                }
            }
            
            // Prepare post content with proper formatting
            $content = $this->prepare_post_content($event['content'], $event['tags']);
            
            $post_data = array(
                'post_title' => wp_trim_words($content, 10, '...'),
                'post_content' => wp_kses_post($content),
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
                'post_type' => 'post',
                'post_date' => date('Y-m-d H:i:s', $event['created_at']),
                'post_parent' => $parent_id,
            );

            // Insert post with error handling
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }

            // Store Nostr metadata with sanitization
            $this->store_post_metadata($post_id, $event);
            
            // Process tags with enhanced handling
            $this->process_tags($post_id, $event['tags']);

            return $post_id;
        } catch (Exception $e) {
            $this->log_error('Import event failed', [
                'event_id' => $event['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function prepare_post_content($content, $tags) {
        // Extract and process image URLs from content
        $processed_content = $this->process_image_urls($content);
        
        // Convert Nostr mentions to readable format
        $processed_content = $this->process_mentions($processed_content, $tags);
        
        // Convert URLs to clickable links
        $processed_content = make_clickable($processed_content);
        
        // Convert Markdown to HTML if needed
        if (function_exists('wpmarkdown_markdown_to_html')) {
            $processed_content = wpmarkdown_markdown_to_html($processed_content);
        }
        
        return $processed_content;
    }

    private function process_image_urls($content) {
        // Regular expressions for common image URLs
        $patterns = [
            '/(https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png|webp))(\s|$|\"|\')/i',
            '/\[image\](https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png|webp))\[\/image\]/i'
        ];
        
        foreach ($patterns as $pattern) {
            $content = preg_replace_callback($pattern, function($matches) {
                $url = $matches[1];
                $suffix = $matches[2] ?? '';
                
                // Validate URL
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return $matches[0];
                }
                
                // Create image HTML with responsive class
                $image_html = sprintf(
                    '<figure class="nostr-image-container"><img src="%s" alt="" class="nostr-imported-image" loading="lazy" /><figcaption class="nostr-image-caption">Imported from Nostr</figcaption></figure>',
                    esc_url($url)
                );
                
                return $image_html . $suffix;
            }, $content);
        }
        
        return $content;
    }

    private function process_mentions($content, $tags) {
        // Create lookup of mentions
        $mentions = [];
        foreach ($tags as $tag) {
            if ($tag[0] === 'p' && !empty($tag[1])) {
                $mentions[$tag[1]] = !empty($tag[2]) ? $tag[2] : $tag[1];
            }
        }
        
        // Replace mentions in content
        foreach ($mentions as $pubkey => $name) {
            $content = str_replace(
                "nostr:pubkey:" . $pubkey,
                sprintf('@%s', esc_html($name)),
                $content
            );
        }
        
        return $content;
    }

    private function store_post_metadata($post_id, $event) {
        update_post_meta($post_id, '_nostr_event_id', sanitize_text_field($event['id']));
        update_post_meta($post_id, '_nostr_pubkey', sanitize_text_field($event['pubkey']));
        
        if (!empty($event['seen_on']) && is_array($event['seen_on'])) {
            update_post_meta($post_id, '_nostr_relays', array_map('esc_url', $event['seen_on']));
        }
        
        // Store original event JSON for reference
        update_post_meta($post_id, '_nostr_original_event', wp_json_encode($event));
        
        // Extract and store image URLs
        $image_urls = $this->extract_image_urls($event['content']);
        if (!empty($image_urls)) {
            update_post_meta($post_id, '_nostr_image_urls', array_map('esc_url', $image_urls));
        }
    }

    private function extract_image_urls($content) {
        $image_urls = [];
        
        // Match common image URL patterns
        $patterns = [
            '/(https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png|webp))/i',
            '/\[image\](https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png|webp))\[\/image\]/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $image_urls = array_merge($image_urls, $matches[1]);
            }
        }
        
        return array_unique($image_urls);
    }

    private function process_tags($post_id, $tags) {
        $wp_tags = array();
        $mentions = array();
        $references = array();
        
        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            
            switch ($tag[0]) {
                case 't': // Hashtags
                    $tag_name = sanitize_text_field($tag[1]);
                    if (!empty($tag_name)) {
                        $wp_tags[] = $tag_name;
                    }
                    break;
                
                case 'p': // Mentions
                    if (!empty($tag[1])) {
                        $mentions[] = sanitize_text_field($tag[1]);
                        update_post_meta(
                            $post_id,
                            '_nostr_mention_' . count($mentions),
                            $tag[1]
                        );
                    }
                    break;
                
                case 'e': // Note references
                    if (!empty($tag[1])) {
                        $references[] = sanitize_text_field($tag[1]);
                        update_post_meta(
                            $post_id,
                            '_nostr_reference_' . count($references),
                            $tag[1]
                        );
                    }
                    break;
            }
        }

        // Set WordPress tags
        if (!empty($wp_tags)) {
            wp_set_post_tags($post_id, $wp_tags, true);
        }

        // Store counts
        update_post_meta($post_id, '_nostr_mention_count', count($mentions));
        update_post_meta($post_id, '_nostr_reference_count', count($references));
    }

    private function get_post_by_event_id($event_id) {
        $posts = get_posts([
            'meta_key' => '_nostr_event_id',
            'meta_value' => $event_id,
            'posts_per_page' => 1
        ]);
        
        return !empty($posts) ? $posts[0]->ID : 0;
    }

    private function log_error($message, $context = []) {
        $this->errors[] = [
            'message' => $message,
            'context' => $context,
            'time' => current_time('mysql')
        ];
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Nostr Import] %s | Context: %s',
                $message,
                json_encode($context)
            ));
        }
    }

    public function maybe_add_nostr_images($content) {
        // Only modify single post views
        if (!is_single()) {
            return $content;
        }
        
        $post_id = get_the_ID();
        $image_urls = get_post_meta($post_id, '_nostr_image_urls', true);
        
        if (empty($image_urls)) {
            return $content;
        }
        
        // Images are already embedded in content through prepare_post_content()
        return $content;
    }
} 
<?php
if (!defined('ABSPATH')) {
    exit;
}

class Nostr_Import_Handler {
    private $default_relays;

    public function __construct() {
        $this->default_relays = [
            "wss://purplepag.es",
            "wss://relay.nostr.band",
            "wss://relay.primal.net",
            "wss://relay.damus.io",
        ];
    }

    public function init() {
        // Add menu items and initialize admin functionality
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handlers
        add_action('wp_ajax_nostr_import_posts', array($this, 'handle_import_posts'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',                              // Parent slug
            __('Nostr Post Importer', 'nostr-login'), // Page title
            __('Nostr Importer', 'nostr-login'),      // Menu title
            'manage_options',                         // Capability
            'nostr-post-importer',                   // Menu slug
            array($this, 'render_admin_page')        // Callback function
        );
    }

    public function register_settings() {
        register_setting('nostr_import_options', 'nostr_import_relays');
        register_setting('nostr_import_options', 'nostr_import_post_status', array(
            'type' => 'string',
            'default' => 'draft',
            'sanitize_callback' => array($this, 'sanitize_post_status')
        ));
    }

    public function sanitize_post_status($status) {
        $allowed_statuses = array('publish', 'draft', 'private');
        return in_array($status, $allowed_statuses) ? $status : 'draft';
    }

    public function enqueue_scripts($hook) {
        if ('tools_page_nostr-post-importer' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'nostr-import',
            plugins_url('assets/js/nostr-imports.min.js', dirname(__FILE__)),
            array('jquery'),
            '1.0.0',
            true
        );

        // Get relay URLs and add debug logging
        $relay_urls = $this->get_relay_urls();
        error_log('Configured relay URLs: ' . print_r($relay_urls, true));
        
        wp_localize_script('nostr-import', 'nostrImport', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nostr_import_nonce'),
            'relays' => $relay_urls // Pass as direct array
        ));
    }

    private function get_relay_urls() {
        // Get the saved relays option and split by newlines
        $relays_option = get_option('nostr_import_relays');
        
        // If the option exists and isn't empty, process it
        if (!empty($relays_option)) {
            $relays_array = array_filter(
                array_map('trim', explode("\n", $relays_option)),
                function($url) {
                    return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
                }
            );
            
            // Only return default relays if no valid URLs were found
            if (!empty($relays_array)) {
                return array_values($relays_array);
            }
        }
        
        // Return default relays as fallback
        return $this->default_relays;
    }

    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // First, let's add the settings section
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Nostr Post Importer Settings', 'nostr-login'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('nostr_import_options');
                do_settings_sections('nostr_import_options');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Import Relays', 'nostr-login'); ?></th>
                        <td>
                            <textarea name="nostr_import_relays" rows="5" cols="50"><?php 
                                echo esc_textarea(get_option('nostr_import_relays', implode("\n", $this->default_relays))); 
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Enter one relay URL per line.', 'nostr-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Default Post Status', 'nostr-login'); ?></th>
                        <td>
                            <select name="nostr_import_post_status">
                                <option value="draft" <?php selected(get_option('nostr_import_post_status', 'draft'), 'draft'); ?>>
                                    <?php esc_html_e('Draft', 'nostr-login'); ?>
                                </option>
                                <option value="publish" <?php selected(get_option('nostr_import_post_status', 'draft'), 'publish'); ?>>
                                    <?php esc_html_e('Published', 'nostr-login'); ?>
                                </option>
                                <option value="private" <?php selected(get_option('nostr_import_post_status', 'draft'), 'private'); ?>>
                                    <?php esc_html_e('Private', 'nostr-login'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'nostr-login')); ?>
            </form>

            <hr>

            <h2><?php echo esc_html__('Import Nostr Posts', 'nostr-login'); ?></h2>
            
            <?php
            // Load the import form template
            require_once plugin_dir_path(dirname(__FILE__)) . 'admin/templates/import-page.php';
            ?>
        </div>
        <?php
    }

    public function handle_import_posts() {
        check_ajax_referer('nostr_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'nostr-login')));
        }

        $event_json = wp_unslash($_POST['event'] ?? '');
        $event = json_decode($event_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Failed to decode event JSON: ' . json_last_error_msg());
            error_log('Received event JSON: ' . $event_json);
            wp_send_json_error(array('message' => __('Invalid event data.', 'nostr-login')));
        }

        error_log('Processing event with ' . 
            (isset($event['comments']) ? count($event['comments']) : 0) . 
            ' comments');

        // Create post from event with comments included in content
        $post_data = array(
            'post_content' => wp_kses_post($event['content']), // Content now includes formatted comments
            'post_title' => wp_trim_words(
                // Strip HTML for title generation
                strip_tags($event['content']), 
                10, 
                '...'
            ),
            'post_status' => get_option('nostr_import_post_status', 'draft'),
            'post_author' => get_current_user_id(),
            'post_date' => date('Y-m-d H:i:s', $event['created_at']),
            'post_type' => 'post',
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            error_log('Failed to create post: ' . $post_id->get_error_message());
            wp_send_json_error(array('message' => $post_id->get_error_message()));
        }

        // Store Nostr metadata
        update_post_meta($post_id, 'nostr_event_id', sanitize_text_field($event['id']));
        update_post_meta($post_id, 'nostr_pubkey', sanitize_text_field($event['pubkey']));
        
        // Store comment count metadata
        if (!empty($event['comments'])) {
            update_post_meta($post_id, 'nostr_comment_count', count($event['comments']));
        }

        // Handle categories and tags
        if (isset($_POST['categories'])) {
            $categories = array_map('intval', json_decode(wp_unslash($_POST['categories']), true));
            if (!empty($categories)) {
                wp_set_post_categories($post_id, $categories, false);
            }
        }

        if (!empty($event['tags'])) {
            $tags = array_filter($event['tags'], function($tag) {
                return $tag[0] === 't';
            });
            
            if (!empty($tags)) {
                $tag_names = array_map(function($tag) {
                    return sanitize_text_field($tag[1]);
                }, $tags);
                wp_set_post_tags($post_id, $tag_names, true);
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Post imported successfully with %d comments included.', 'nostr-login'),
                !empty($event['comments']) ? count($event['comments']) : 0
            ),
            'post_id' => $post_id
        ));
    }

    private function validateComment($comment, $parentEvent) {
        if (empty($comment['id']) || empty($comment['content']) || empty($comment['created_at'])) {
            error_log('Comment validation failed: missing required fields');
            return false;
        }

        // Verify the comment references the parent event
        $hasValidReference = false;
        if (!empty($comment['tags'])) {
            foreach ($comment['tags'] as $tag) {
                if ($tag[0] === 'e' && $tag[1] === $parentEvent['id']) {
                    $hasValidReference = true;
                    break;
                }
            }
        }

        if (!$hasValidReference) {
            error_log('Comment validation failed: no valid reference to parent event');
            return false;
        }

        return true;
    }

    private function import_comment($comment, $post_id) {
        error_log('Processing comment for post ' . $post_id . ': ' . print_r($comment, true));

        $commentdata = array(
            'comment_post_ID' => $post_id,
            'comment_content' => wp_kses_post($comment['content']),
            'comment_date' => date('Y-m-d H:i:s', $comment['created_at']),
            'comment_approved' => 1,
            'comment_type' => '', // Use default comment type since these are regular kind:1 notes
        );

        // Try to get author information from metadata
        $author_name = 'Nostr User';
        $author_url = '';
        
        // Use metadata if available
        if (!empty($comment['metadata'])) {
            $metadata = $comment['metadata'];
            $author_name = sanitize_text_field(
                $metadata['display_name'] ?? 
                $metadata['name'] ?? 
                substr($comment['pubkey'], 0, 8) . '...'
            );
            
            // Store the complete metadata
            add_comment_meta($comment_id, 'nostr_author_metadata', wp_json_encode($metadata));
        }
        
        // Add the comment author's pubkey as the author URL
        if (!empty($comment['pubkey'])) {
            $author_url = 'nostr:' . sanitize_text_field($comment['pubkey']);
        }

        // Add author data
        $commentdata['comment_author'] = $author_name;
        $commentdata['comment_author_url'] = $author_url;
        
        error_log('Inserting comment with data: ' . print_r($commentdata, true));
        
        // Store the comment and get the comment ID
        $comment_id = wp_insert_comment($commentdata);

        if ($comment_id) {
            error_log('Successfully created comment with ID: ' . $comment_id);
            
            // Store Nostr metadata for the comment
            add_comment_meta($comment_id, 'nostr_event_id', sanitize_text_field($comment['id']));
            add_comment_meta($comment_id, 'nostr_pubkey', sanitize_text_field($comment['pubkey']));
            
            // Store the parent event ID reference
            $parent_event_id = '';
            foreach ($comment['tags'] as $tag) {
                if ($tag[0] === 'e') {
                    $parent_event_id = $tag[1];
                    break;
                }
            }
            if ($parent_event_id) {
                add_comment_meta($comment_id, 'nostr_parent_event_id', sanitize_text_field($parent_event_id));
            }
        } else {
            error_log('Failed to create comment');
        }

        return $comment_id;
    }
}
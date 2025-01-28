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

        // Add custom CSS for imported images
        add_action('wp_head', array($this, 'add_image_styles'));
        add_action('admin_head', array($this, 'add_image_styles'));
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
        // Add capability check before registering settings
        if (!current_user_can('manage_options')) {
            return;
        }
        
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

        // Add WordPress media elements
        wp_enqueue_style('wp-mediaelement');
        wp_enqueue_script('wp-mediaelement');
        
        wp_enqueue_script(
            'nostr-import',
            plugins_url('assets/js/nostr-imports.min.js', dirname(__FILE__)),
            array('jquery', 'wp-mediaelement'), // Add mediaelement as dependency
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
        // Add strict capability check with specific error message
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('You do not have sufficient permissions to perform this action.', 'nostr-login')
            ), 403);
        }
        
        check_ajax_referer('nostr_import_nonce', 'nonce');

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

        // Process content and import images
        $processed_content = $this->process_content_with_media($event['content'], $event);

        // Create post from event with comments included in content
        $post_data = array(
            'post_content' => wp_kses_post($processed_content), // Use processed content with embedded images
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

    /**
     * Process content and import images into WordPress media library
     */
    private function process_content_with_images($content) {
        // Sanitize content before processing
        $content = wp_kses_post($content);
        
        // Regular expression to find image URLs
        $pattern = '/(https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png|webp))/i';
        
        return preg_replace_callback($pattern, function($matches) {
            $url = esc_url_raw($matches[1]);
            
            if (!$url) {
                return '';
            }
            
            $image_id = $this->import_media_to_library($url);
            
            if ($image_id) {
                return sprintf(
                    '<figure class="wp-block-image size-large">%s</figure>',
                    wp_get_attachment_image(
                        $image_id,
                        'large',
                        false,
                        array(
                            'class' => 'wp-image-' . intval($image_id) . ' nostr-imported-image',
                            'style' => 'max-width: 100%; height: auto;'
                        )
                    )
                );
            }
            
            return esc_url($url);
        }, $content);
    }

    /**
     * Import an image from URL to WordPress media library
     */
    private function import_media_to_library($url, $type = 'image') {
        try {
            // Validate URL before processing
            $url = esc_url_raw($url);
            if (!$url || !wp_http_validate_url($url)) {
                error_log('Invalid media URL: ' . $url);
                return false;
            }
            
            // Required for wp_handle_sideload
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            // Download URL to temp file
            $tmp = download_url($url);
            
            if (is_wp_error($tmp)) {
                error_log('Failed to download media: ' . $tmp->get_error_message());
                return false;
            }

            // Define allowed mime types with more comprehensive video support
            $allowed_types = array(
                'image' => array(
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/webp',
                    'image/avif',
                ),
                'video' => array(
                    'video/mp4',
                    'video/webm',
                    'video/ogg',
                    'video/quicktime',
                    'video/x-m4v',
                    'video/mpeg',
                    'video/x-msvideo'
                ),
            );
            
            // Get file info with better MIME type detection
            $file_info = wp_check_filetype(basename($url), null);
            $mime_type = $file_info['type'];
            
            if (!$mime_type) {
                // Fallback MIME type detection
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
            
            error_log("Detected MIME type for $url: $mime_type");
            
            // Validate mime type
            if (!$mime_type || !in_array($mime_type, $allowed_types[$type])) {
                @unlink($tmp);
                error_log("Invalid $type mime type: $mime_type");
                return false;
            }

            // Set file parameters with proper extension
            $file_array = array(
                'name' => sanitize_file_name(
                    pathinfo($url, PATHINFO_FILENAME) . '.' . $file_info['ext']
                ),
                'tmp_name' => $tmp,
                'type' => $mime_type,
            );

            // Add special handling for video uploads
            add_filter('upload_mimes', function($mimes) use ($allowed_types) {
                return array_merge($mimes, array_combine(
                    array_map(function($mime) { 
                        return '.' . explode('/', $mime)[1]; 
                    }, $allowed_types['video']),
                    $allowed_types['video']
                ));
            });

            // Do the validation and storage stuff
            $id = media_handle_sideload($file_array, 0);

            // Cleanup temp file
            @unlink($tmp);

            if (is_wp_error($id)) {
                error_log('Failed to import media: ' . $id->get_error_message());
                return false;
            }

            // Add extra meta for videos
            if ($type === 'video') {
                update_post_meta($id, '_wp_attachment_is_nostr_video', '1');
                wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, get_attached_file($id)));
            }

            return $id;
        } catch (Exception $e) {
            error_log('Exception while importing media: ' . $e->getMessage());
            if (isset($tmp) && file_exists($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    // Add SVG validation
    private function validate_svg($file) {
        // Read SVG file content
        $content = file_get_contents($file);
        
        // Basic security checks
        if (false === $content) {
            return false;
        }
        
        // Check for suspicious content
        $suspicious = array(
            'script',
            'onclick',
            'onload',
            'onunload',
            'onerror',
            'eval',
            'javascript:',
            'alert(',
        );
        
        foreach ($suspicious as $pattern) {
            if (stripos($content, $pattern) !== false) {
                nostr_login_debug_log('Suspicious SVG content detected');
                return false;
            }
        }
        
        return true;
    }

    // Modify process_content to handle both images and videos
    public function process_content_with_media($content, $event) {
        // Process regular content first
        $content = $this->process_content_with_images($content);
        
        // Add videos at the end of the content if they exist
        if (!empty($event['media']['videos'])) {
            foreach ($event['media']['videos'] as $video_url) {
                // Add video URL as a WordPress video block
                $content .= "\n\n<!-- wp:video -->\n";
                $content .= sprintf(
                    '<figure class="wp-block-video"><video controls src="%s"></video></figure>',
                    esc_url($video_url)
                );
                $content .= "\n<!-- /wp:video -->\n";
            }
        }
        
        return $content;
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

    public function add_image_styles() {
        ?>
        <style type="text/css">
            /* Responsive image styling */
            .nostr-imported-image {
                max-width: 100% !important;
                height: auto !important;
                display: block !important;
                margin: 1em auto !important;
            }
            
            /* Container for better scaling */
            .wp-block-image {
                max-width: 800px !important; /* Maximum width for large screens */
                margin-left: auto !important;
                margin-right: auto !important;
            }
            
            /* Ensure images don't overflow on mobile */
            @media (max-width: 800px) {
                .wp-block-image {
                    width: 100% !important;
                    padding: 0 10px !important;
                }
            }
            
            /* Preview Styling */
            .nostr-preview-container {
                max-width: 800px;
                margin: 20px 0;
            }

            .nostr-preview-item {
                background: #fff;
                border: 1px solid #ddd;
                padding: 15px;
                margin-bottom: 15px;
                border-radius: 4px;
                display: grid;
                grid-template-columns: auto 1fr;
                gap: 15px;
            }

            .nostr-preview-checkbox {
                align-self: center;
            }

            .nostr-preview-content {
                grid-column: 2;
                margin: 10px 0;
                white-space: pre-line;
            }

            .nostr-preview-date,
            .nostr-preview-comments,
            .nostr-preview-tags {
                grid-column: 2;
                color: #666;
                font-size: 0.9em;
            }

            .nostr-tag {
                display: inline-block;
                background: #f0f0f1;
                padding: 2px 8px;
                border-radius: 3px;
                margin: 2px;
                font-size: 0.9em;
            }

            /* User Metadata Styling */
            .nostr-user-metadata {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                margin-bottom: 20px;
                overflow: hidden;
            }

            .profile-header {
                position: relative;
            }

            .profile-banner img {
                width: 100%;
                height: 200px;
                object-fit: cover;
            }

            .profile-info {
                padding: 20px;
                display: flex;
                gap: 20px;
            }

            .profile-picture img {
                width: 100px;
                height: 100px;
                border-radius: 50%;
                border: 4px solid #fff;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .profile-details h3 {
                margin: 0 0 10px 0;
            }

            .profile-details p {
                margin: 5px 0;
                color: #666;
            }

            /* Pagination Styling */
            .nostr-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 15px;
                margin: 20px 0;
            }

            .page-info {
                color: #666;
            }

            /* Progress Bar Improvements */
            .progress-bar {
                background-color: #f0f0f1;
                height: 24px;
                border-radius: 12px;
                overflow: hidden;
                border: 1px solid #ddd;
            }

            .progress-bar-fill {
                background-color: #2271b1;
                height: 100%;
                transition: width 0.3s ease-in-out;
            }

            /* Loading Indicator */
            .nostr-loading-indicator {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 10px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                margin: 10px 0;
            }

            /* Improved video styling */
            .wp-block-video {
                max-width: 800px;
                margin: 2em auto;
                position: relative;
                aspect-ratio: 16/9;
            }
            
            .wp-block-video video {
                width: 100%;
                height: 100%;
                display: block;
                border-radius: 8px;
                background: #000;
                object-fit: contain;
            }
            
            /* Ensure controls are visible */
            .wp-block-video video::-webkit-media-controls {
                display: flex !important;
                visibility: visible !important;
            }
            
            .wp-block-video video::-webkit-media-controls-enclosure {
                display: flex !important;
                visibility: visible !important;
            }
        </style>
        <?php
    }
}
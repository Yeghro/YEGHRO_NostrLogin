<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
// Include the file containing the debug log function
require_once plugin_dir_path(__FILE__) . '../nostrLogin.php';

class Nostr_Login_Handler {
    private static $field_added = false;
    private $default_relays = [
        "wss://purplepag.es",
        "wss://relay.nostr.band",
        "wss://relay.primal.net",
        "wss://relay.damus.io",
        "wss://nostr.wine",
        "wss://relay.snort.social",
        "wss://eden.nostr.land",
        "wss://nostr.bitcoiner.social",
        "wss://nostrpub.yeghro.site",
    ];



    public function init() {
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('login_form', array($this, 'add_nostr_login_field'));
        add_action('wp_ajax_nostr_login', array($this, 'ajax_nostr_login'));
        add_action('wp_ajax_nopriv_nostr_login', array($this, 'ajax_nostr_login'));
        add_action('wp_ajax_nostr_register', array($this, 'ajax_nostr_register'));
        add_action('show_user_profile', array($this, 'add_custom_user_profile_fields'));
        add_action('edit_user_profile', array($this, 'add_custom_user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_custom_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_custom_user_profile_fields'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        nostr_login_debug_log("Nostr_Login_Handler class initialized");
    }

    public function add_admin_menu() {
        add_options_page(__('Nostr Login Settings', 'nostr-login'), __('Nostr Login', 'nostr-login'), 'manage_options', 'nostr-login', array($this, 'options_page'));
    }

    public function register_settings() {
        register_setting('nostr_login_options', 'nostr_login_relays');
    }

    public function options_page() {
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Nostr Login Settings', 'nostr-login'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('nostr_login_options'); ?>
                <?php do_settings_sections('nostr_login_options'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Nostr Relays', 'nostr-login'); ?></th>
                        <td>
                            <textarea name="nostr_login_relays" rows="5" cols="50"><?php echo esc_textarea(get_option('nostr_login_relays', implode("\n", $this->default_relays))); ?></textarea>
                            <p class="description"><?php esc_html_e('Enter one relay URL per line.', 'nostr-login'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }


    public function add_custom_user_profile_fields($user) {
        ?>
        <h3><?php esc_html_e("Nostr Information", "nostr-login"); ?></h3>
    
        <table class="form-table">
            <tr>
                <th><label for="nostr_public_key"><?php esc_html_e("Nostr Public Key", "nostr-login"); ?></label></th>
                <td>
                    <input type="text" name="nostr_public_key" id="nostr_public_key" value="<?php echo esc_attr(get_user_meta($user->ID, 'nostr_public_key', true)); ?>" class="regular-text" readonly />
                    <p class="description"><?php esc_html_e("Your Nostr public key.", "nostr-login"); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="nip05"><?php esc_html_e("Nostr Nip05", "nostr-login"); ?></label></th>
                <td>
                    <input type="text" name="nip05" id="nip05" value="<?php echo esc_attr(get_user_meta($user->ID, 'nip05', true)); ?>" class="regular-text" readonly />
                    <p class="description"><?php esc_html_e("Your Nostr Nip05 address.", "nostr-login"); ?></p>
                </td>
            </tr>

            <!-- Add more custom fields here -->
        </table>
        <?php
    }

    public function save_custom_user_profile_fields($user_id) {
        // Verify nonce to prevent CSRF attacks
        if ( ! isset( $_POST['nostr_login_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['nostr_login_nonce'] ), 'nostr_login_save_profile' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            // Nonce is invalid; stop processing
            return;
        }        
    
        // Check user permissions to ensure that only authorized users can edit
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            // User does not have permission; stop processing
            return false;
        }
    
        // Save Nostr public key securely
        if ( isset( $_POST['nostr_public_key'] ) ) {
            update_user_meta( $user_id, 'nostr_public_key', sanitize_text_field( wp_unslash( $_POST['nostr_public_key'] ) ) );
        }

        // Save Nip05 securely
        if ( isset( $_POST['nip05'] ) ) {
            update_user_meta( $user_id, 'nip05', sanitize_text_field( wp_unslash( $_POST['nip05'] ) ) );
        }
    }
    

    public function ajax_nostr_login() {
        check_ajax_referer('nostr-login-nonce', 'nonce');
    
        $public_key = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
        $metadata_json = isset($_POST['metadata']) ? wp_kses_post(wp_unslash($_POST['metadata'])) : '';
    
        if (empty($public_key)) {
            nostr_login_debug_log('Public key is empty');
            wp_send_json_error(array('message' => __('Public key is required.', 'nostr-login')));
        }
    
        // Decode the metadata JSON
        $metadata = json_decode($metadata_json, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            nostr_login_debug_log('Invalid metadata JSON: ' . json_last_error_msg());
            wp_send_json_error(array('message' => __('Invalid metadata:', 'nostr-login') . ' ' . json_last_error_msg()));
        }
    
        // Check if a user with this public key already exists
        $user = $this->get_user_by_public_key($public_key);
    
        if (!$user) {
            // Create a new user if one doesn't exist
            $user_id = $this->create_new_user($public_key, $metadata);
            if (is_wp_error($user_id)) {
                nostr_login_debug_log('Failed to create new user: ' . $user_id->get_error_message());
                wp_send_json_error(array('message' => $user_id->get_error_message()));
            }
            $user = get_user_by('ID', $user_id);
            nostr_login_debug_log('New user created with ID: ' . $user_id);
        } else {
            // Update existing user's metadata
            $this->update_user_metadata($user->ID, $metadata);
            nostr_login_debug_log('Updated metadata for user ID: ' . $user->ID);
        }
    
        if ($user) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
            nostr_login_debug_log('User logged in successfully: ' . $user->ID);
            wp_send_json_success(array('redirect' => admin_url()));
        } else {
            nostr_login_debug_log('Login failed for public key: ' . $public_key);
            wp_send_json_error(array('message' => __('Login failed. Please try again.', 'nostr-login')));
        }
    }    
     
    private function get_user_by_public_key($public_key) {
        $users = get_users(array(
            'meta_key' => 'nostr_public_key',
            'meta_value' => sanitize_text_field($public_key),
            'number' => 1,
            'count_total' => false
        ));
    
        return !empty($users) ? $users[0] : false;
    }
    
    private function create_new_user($public_key, $metadata) {
        $username = !empty($metadata['name']) ? sanitize_user($metadata['name']) : 'nostr_' . substr(sanitize_text_field($public_key), 0, 8);
        $email = !empty($metadata['email']) ? sanitize_email($metadata['email']) : sanitize_text_field($public_key) . '@nostr.local';
    
        $user_id = wp_create_user($username, wp_generate_password(), $email);
    
        if (!is_wp_error($user_id)) {
            update_user_meta($user_id, 'nostr_public_key', sanitize_text_field($public_key));
            $this->update_user_metadata($user_id, $metadata);
        }
    
        return $user_id;
    }
    
    private function update_user_metadata($user_id, $metadata) {
        if (!empty($metadata['name'])) {
            wp_update_user(array('ID' => $user_id, 'display_name' => sanitize_text_field($metadata['name'])));
        }
        if (!empty($metadata['about'])) {
            update_user_meta($user_id, 'description', sanitize_textarea_field($metadata['about']));
        }
        if (!empty($metadata['nip05'])) {
            update_user_meta($user_id, 'nip05', sanitize_text_field($metadata['nip05']));
        }

        if (!empty($metadata['image'])) {
            $avatar_url = esc_url_raw($metadata['image']);
            update_user_meta($user_id, 'nostr_avatar', $avatar_url);
            $saved_avatar_url = get_user_meta($user_id, 'nostr_avatar', true);
            nostr_login_debug_log("Saved Nostr avatar URL for user $user_id: " . esc_url($saved_avatar_url));
    
        }        
        if (!empty($metadata['website'])) {
            wp_update_user(array(
                'ID' => $user_id,
                'user_url' => esc_url_raw($metadata['website'])
            ));
        }
        // Add more metadata fields as needed
    }

        
    public function enqueue_scripts() {
        wp_enqueue_script('nostr-login', plugin_dir_url(dirname(__FILE__)) . 'assets/js/nostr-login.min.js', array('jquery'), '1.0', true);
        wp_localize_script('nostr-login', 'nostr_login_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nostr-login-nonce'),
            'relays' => array_filter(explode("\n", get_option('nostr_login_relays', implode("\n", $this->default_relays))))
        ));
    }

    public function add_nostr_login_field() {
        if (self::$field_added) {
            return;
        }
        self::$field_added = true;
        ?>
        <div class="nostr-login-container">
            <label for="nostr_login_toggle" class="nostr-toggle-label">
                <input type="checkbox" id="nostr_login_toggle">
                <span><?php esc_html_e('Use Nostr Login', 'nostr-login'); ?></span>
            </label>
            <?php wp_nonce_field('nostr_login_action', 'nostr_login_nonce'); ?>
        </div>
        <p class="nostr-login-field" style="display:none;">
            <label for="nostr_private_key"><?php esc_html_e('Nostr Private Key', 'nostr-login'); ?></label>
            <input type="password" name="nostr_private_key" id="nostr_private_key" class="input" size="20" autocapitalize="off" />
        </p>
        <p class="nostr-login-buttons" style="display:none;">
            <button type="button" id="use_nostr_extension" class="button"><?php esc_html_e('Use Nostr Extension', 'nostr-login'); ?></button>
            <input type="submit" name="wp-submit" id="nostr-wp-submit" class="button button-primary" value="<?php esc_attr_e('Log In with Nostr', 'nostr-login'); ?>">
        </p>
        <div id="nostr-login-feedback" style="display:none;"></div>
        <?php
        remove_action('login_form', array($this, 'add_nostr_login_field'));
    }
                    
    public function authenticate_nostr_user($user, $username, $password) {
        // We'll implement this method later
        return $user;
    }

    public function ajax_nostr_register() {
        // We'll implement this method later
        wp_die();
    }
}   
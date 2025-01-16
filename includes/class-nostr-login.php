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
    ];

    public function init() {
        add_action( 'login_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'login_form', array( $this, 'add_nostr_login_field' ) );
        add_action( 'wp_ajax_nostr_login', array( $this, 'ajax_nostr_login' ) );
        add_action( 'wp_ajax_nopriv_nostr_login', array( $this, 'ajax_nostr_login' ) );
        add_action( 'wp_ajax_nostr_register', array( $this, 'ajax_nostr_register' ) );
        add_action( 'show_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_custom_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_custom_user_profile_fields' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_nostr_sync_profile', array( $this, 'ajax_nostr_sync_profile' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function add_admin_menu() {
        add_options_page( __( 'Nostr Login Settings', 'nostr-login' ), __( 'Nostr Login', 'nostr-login' ), 'manage_options', 'nostr-login', array( $this, 'options_page' ) );
    }

    public function register_settings() {
        register_setting(
            'nostr_login_options',
            'nostr_login_redirect',
            array(
                'type' => 'string',
                'sanitize_callback' => array($this, 'sanitize_redirect_setting'),
                'default' => 'admin'
            )
        );
        register_setting( 'nostr_login_options', 'nostr_login_relays' );
    }

    public function sanitize_redirect_setting($value) {
        $allowed_values = array('admin', 'home', 'profile');
        return in_array($value, $allowed_values) ? $value : 'admin';
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Nostr Login Settings', 'nostr-login' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'nostr_login_options' ); ?>
                <?php do_settings_sections( 'nostr_login_options' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Nostr Relays', 'nostr-login' ); ?></th>
                        <td>
                            <textarea name="nostr_login_relays" rows="5" cols="50"><?php echo esc_textarea( get_option( 'nostr_login_relays', implode( "\n", $this->default_relays ) ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Enter one relay URL per line.', 'nostr-login' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Redirect After Login', 'nostr-login' ); ?></th>
                        <td>
                            <select name="nostr_login_redirect">
                                <option value="admin" <?php selected( get_option( 'nostr_login_redirect', 'admin' ), 'admin' ); ?>>
                                    <?php esc_html_e( 'Admin Dashboard', 'nostr-login' ); ?>
                                </option>
                                <option value="home" <?php selected( get_option( 'nostr_login_redirect', 'admin' ), 'home' ); ?>>
                                    <?php esc_html_e( 'Home Page', 'nostr-login' ); ?>
                                </option>
                                <option value="profile" <?php selected( get_option( 'nostr_login_redirect', 'admin' ), 'profile' ); ?>>
                                    <?php esc_html_e( 'User Profile', 'nostr-login' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function add_custom_user_profile_fields( $user ) {
        ?>
        <h3><?php esc_html_e( "Nostr Information", "nostr-login" ); ?></h3>
        <?php wp_nonce_field('nostr_login_save_profile', 'nostr_login_nonce'); ?>

        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e("Connect Nostr Account", "nostr-login"); ?></label></th>
                <td>
                    <?php if (!get_user_meta($user->ID, 'nostr_public_key', true)): ?>
                        <button type="button" id="nostr-connect-extension" class="button">
                            <?php esc_html_e("Sync with Nostr Extension", "nostr-login"); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e("Connect your Nostr account to sync your public key, NIP-05, and avatar", "nostr-login"); ?>
                        </p>
                    <?php else: ?>
                        <button type="button" id="nostr-resync-extension" class="button">
                            <?php esc_html_e("Resync Nostr Data", "nostr-login"); ?>
                        </button>
                    <?php endif; ?>
                    <div id="nostr-connect-feedback" style="display:none; margin-top:10px;"></div>
                </td>
            </tr>
            
            <!-- Existing fields as read-only -->
            <tr>
                <th><label><?php esc_html_e("Nostr Public Key", "nostr-login"); ?></label></th>
                <td>
                    <input type="text" id="nostr_public_key" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'nostr_public_key', true)); ?>" 
                           class="regular-text" readonly />
                </td>
            </tr>
            <tr>
                <th><label><?php esc_html_e("Nostr NIP-05", "nostr-login"); ?></label></th>
                <td>
                    <input type="text" id="nip05" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'nip05', true)); ?>" 
                           class="regular-text" readonly />
                </td>
            </tr>
            <!-- Add more custom fields here -->
        </table>
        <?php
    }

    public function save_custom_user_profile_fields( $user_id ) {
        // Verify nonce to prevent CSRF attacks
        if ( ! isset( $_POST['nostr_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nostr_login_nonce'] ) ), 'nostr_login_save_profile' ) ) {
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
            $nostr_public_key = sanitize_text_field( wp_unslash( $_POST['nostr_public_key'] ) );
            if ( $this->is_valid_public_key( $nostr_public_key ) ) {
                update_user_meta( $user_id, 'nostr_public_key', $nostr_public_key );
            } else {
                // Handle invalid public key
            }
        }

        // Save Nip05 securely
        if ( isset( $_POST['nip05'] ) ) {
            $nip05 = sanitize_text_field( wp_unslash( $_POST['nip05'] ) );
            if ( $this->is_valid_nip05( $nip05 ) ) {
                update_user_meta( $user_id, 'nip05', $nip05 );
            } else {
                // Handle invalid nip05
            }
        }
    }

    private function is_valid_public_key( $key ) {
        // Implement your validation logic for Nostr public keys
        return preg_match( '/^[a-f0-9]{64}$/i', $key );
    }

    private function is_valid_nip05( $nip05 ) {
        // Implement your validation logic for NIP-05 identifiers
        return true; // Placeholder; replace with actual validation
    }

    public function ajax_nostr_login() {
        // Sanitize and verify nonce
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'nostr-login-nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'nostr-login' ) ) );
            wp_die();
        }

        // Sanitize input data
        $public_key    = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
        $metadata_json = isset( $_POST['metadata'] ) ? sanitize_text_field( wp_unslash( $_POST['metadata'] ) ) : '';
        
        if ( empty( $public_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Public key is required.', 'nostr-login' ) ) );
        }

        // Validate public key format
        if ( ! $this->is_valid_public_key( $public_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid public key format.', 'nostr-login' ) ) );
        }

        // Decode and sanitize metadata
        $metadata = json_decode( $metadata_json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'message' => __( 'Invalid metadata: ', 'nostr-login' ) . json_last_error_msg() ) );
        }

        // Sanitize and validate each field
        $sanitized_metadata = array();
        if ( isset( $metadata['name'] ) ) {
            $sanitized_metadata['name'] = sanitize_text_field( $metadata['name'] );
        }
        if ( isset( $metadata['about'] ) ) {
            $sanitized_metadata['about'] = sanitize_textarea_field( $metadata['about'] );
        }
        if ( isset( $metadata['nip05'] ) ) {
            $sanitized_metadata['nip05'] = sanitize_text_field( $metadata['nip05'] );
            // Optionally validate nip05 format
        }
        if ( isset( $metadata['image'] ) ) {
            $sanitized_metadata['image'] = esc_url_raw( $metadata['image'] );
            // Optionally validate URL
        }
        if ( isset( $metadata['website'] ) ) {
            $sanitized_metadata['website'] = esc_url_raw( $metadata['website'] );
            // Optionally validate URL
        }
        if ( isset( $metadata['email'] ) ) {
            $sanitized_metadata['email'] = sanitize_email( $metadata['email'] );
            if ( ! is_email( $sanitized_metadata['email'] ) ) {
                // Handle invalid email
                $sanitized_metadata['email'] = '';
            }
        }

        // Check if a user with this public key already exists
        $user = $this->get_user_by_public_key( $public_key );

        if ( ! $user ) {
            // Create a new user if one doesn't exist
            $user_id = $this->create_new_user( $public_key, $sanitized_metadata );
            if ( is_wp_error( $user_id ) ) {
                wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
            }
            $user = get_user_by( 'ID', $user_id );
        } else {
            // Update existing user's metadata
            $this->update_user_metadata( $user->ID, $sanitized_metadata );
        }

        if ( $user ) {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID );
            $redirect_type = get_option('nostr_login_redirect', 'admin');
            $redirect_url = match($redirect_type) {
                'home' => home_url(),
                'profile' => get_edit_profile_url($user->ID),
                default => admin_url()
            };
            wp_send_json_success(array('redirect' => $redirect_url));
        } else {
            wp_send_json_error( array( 'message' => __( 'Login failed. Please try again.', 'nostr-login' ) ) );
        }
    }

    private function get_user_by_public_key( $public_key ) {
        $users = get_users( array(
            'meta_key'     => 'nostr_public_key',
            'meta_value'   => sanitize_text_field( $public_key ),
            'number'       => 1,
            'count_total'  => false,
            'fields'       => 'all',
        ) );

        return ! empty( $users ) ? $users[0] : false;
    }

    private function create_new_user( $public_key, $sanitized_metadata ) {
        $username = ! empty( $sanitized_metadata['name'] ) ? sanitize_user( $sanitized_metadata['name'], true ) : 'nostr_' . substr( sanitize_text_field( $public_key ), 0, 8 );
        if ( username_exists( $username ) ) {
            $username .= '_' . wp_generate_password( 4, false ); // Append random characters
        }

        $email = ! empty( $sanitized_metadata['email'] ) ? sanitize_email( $sanitized_metadata['email'] ) : sanitize_text_field( $public_key ) . '@nostr.local';

        if ( ! is_email( $email ) ) {
            // Handle invalid email, perhaps generate a default one
            $email = sanitize_text_field( $public_key ) . '@nostr.local';
        }

        $user_id = wp_create_user( $username, wp_generate_password(), $email );

        if ( ! is_wp_error( $user_id ) ) {
            update_user_meta( $user_id, 'nostr_public_key', sanitize_text_field( $public_key ) );
            $this->update_user_metadata( $user_id, $sanitized_metadata );
        }

        return $user_id;
    }

    private function update_user_metadata( $user_id, $sanitized_metadata ) {
        if ( ! empty( $sanitized_metadata['name'] ) ) {
            wp_update_user( array( 'ID' => $user_id, 'display_name' => sanitize_text_field( $sanitized_metadata['name'] ) ) );
        }
        if ( ! empty( $sanitized_metadata['about'] ) ) {
            update_user_meta( $user_id, 'description', sanitize_textarea_field( $sanitized_metadata['about'] ) );
        }
        if ( ! empty( $sanitized_metadata['nip05'] ) ) {
            update_user_meta( $user_id, 'nip05', sanitize_text_field( $sanitized_metadata['nip05'] ) );
        }
        if ( ! empty( $sanitized_metadata['image'] ) ) {
            $avatar_url = esc_url_raw( $sanitized_metadata['image'] );
            update_user_meta( $user_id, 'nostr_avatar', $avatar_url );
            $saved_avatar_url = get_user_meta( $user_id, 'nostr_avatar', true );
        }
        if ( ! empty( $sanitized_metadata['website'] ) ) {
            wp_update_user( array(
                'ID'       => $user_id,
                'user_url' => esc_url_raw( $sanitized_metadata['website'] ),
            ) );
        }
        // Add more metadata fields as needed
    }

    public function enqueue_scripts($hook = '') {
        // Check if we're on the login page
        if (in_array($GLOBALS['pagenow'], array('wp-login.php')) || did_action('login_enqueue_scripts')) {
            wp_enqueue_script('nostr-login', plugin_dir_url(dirname(__FILE__)) . 'assets/js/nostr-login.min.js', array('jquery'), '1.0', true);
            
            // Sanitize relay URLs
            $relays_option = get_option('nostr_login_relays', implode("\n", $this->default_relays));
            $relays_array = explode("\n", $relays_option);
            $relays = array_filter(array_map('esc_url', array_map('trim', $relays_array)));

            wp_localize_script('nostr-login', 'nostr_login_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr-login-nonce'),
                'relays' => $relays,
            ));
        }
        
        // For profile page
        if (in_array($hook, array('profile.php', 'user-edit.php'))) {
            wp_enqueue_script('nostr-login', plugin_dir_url(dirname(__FILE__)) . 'assets/js/nostr-login.min.js', array('jquery'), '1.0', true);
            
            wp_localize_script('nostr-login', 'nostr_login_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('nostr-login-nonce'),
                'relays' => $this->get_relay_urls()
            ));
        }
    }

    private function get_relay_urls() {
        $relays_option = get_option('nostr_login_relays', implode("\n", $this->default_relays));
        $relays_array = explode("\n", $relays_option);
        return array_filter(array_map('esc_url', array_map('trim', $relays_array)));
    }

    public function add_nostr_login_field() {
        if ( self::$field_added ) {
            return;
        }
        self::$field_added = true;
        ?>
        <div class="nostr-login-container">
            <label for="nostr_login_toggle" class="nostr-toggle-label">
                <input type="checkbox" id="nostr_login_toggle">
                <span><?php esc_html_e( 'Use Nostr Login', 'nostr-login' ); ?></span>
            </label>
            <?php wp_nonce_field( 'nostr-login-nonce', 'nostr_login_nonce' ); ?>
        </div>
        <p class="nostr-login-field" style="display:none;">
            <label for="nostr_private_key"><?php esc_html_e( 'Nostr Private Key', 'nostr-login' ); ?></label>
            <input type="password" name="nostr_private_key" id="nostr_private_key" class="input" size="20" autocapitalize="off" />
        </p>
        <p class="nostr-login-buttons" style="display:none;">
            <button type="button" id="use_nostr_extension" class="button"><?php esc_html_e( 'Use Nostr Extension', 'nostr-login' ); ?></button>
            <input type="submit" name="wp-submit" id="nostr-wp-submit" class="button button-primary" value="<?php esc_attr_e( 'Log In with Nostr', 'nostr-login' ); ?>">
        </p>
        <div id="nostr-login-feedback" style="display:none;"></div>
        <?php
        remove_action( 'login_form', array( $this, 'add_nostr_login_field' ) );
    }

    public function authenticate_nostr_user( $user, $username, $password ) {
        // We'll implement this method later
        return $user;
    }

    public function ajax_nostr_register() {
        // We'll implement this method later
        wp_die();
    }

    public function ajax_nostr_sync_profile() {
        try {
            if (!check_ajax_referer('nostr-login-nonce', 'nonce', false)) {
                throw new Exception(__('Security check failed.', 'nostr-login'));
            }

            if (!is_user_logged_in()) {
                throw new Exception(__('You must be logged in.', 'nostr-login'));
            }

            $user_id = get_current_user_id();
            if (!current_user_can('edit_user', $user_id)) {
                throw new Exception(__('You do not have permission to perform this action.', 'nostr-login'));
            }

            // Validate and sanitize metadata input
            if (!isset($_POST['metadata']) || empty($_POST['metadata'])) {
                throw new Exception(__('No metadata provided.', 'nostr-login'));
            }

            // Sanitize the JSON string before decoding
            $raw_metadata = sanitize_text_field(wp_unslash($_POST['metadata']));
            $metadata = json_decode($raw_metadata, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Invalid metadata format.', 'nostr-login'));
            }

            // Validate public key
            if (empty($metadata['public_key']) || !$this->is_valid_public_key($metadata['public_key'])) {
                throw new Exception(__('Invalid public key.', 'nostr-login'));
            }

            // Check for existing public key
            $existing_user = $this->get_user_by_public_key($metadata['public_key']);
            if ($existing_user && $existing_user->ID !== $user_id) {
                throw new Exception(__('This Nostr account is already linked to another user.', 'nostr-login'));
            }

            // Update Nostr-specific data
            update_user_meta($user_id, 'nostr_public_key', sanitize_text_field($metadata['public_key']));
            
            if (!empty($metadata['nip05'])) {
                update_user_meta($user_id, 'nip05', sanitize_text_field($metadata['nip05']));
            }
            
            if (!empty($metadata['image'])) {
                $avatar_url = esc_url_raw($metadata['image']);
                update_user_meta($user_id, 'nostr_avatar', $avatar_url);
            }

            wp_send_json_success(array('message' => __('Nostr data successfully synced!', 'nostr-login')));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}

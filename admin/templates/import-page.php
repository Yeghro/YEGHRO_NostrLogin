<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="nostr-import-container">
    <form id="nostr-import-form" method="post">
        <?php wp_nonce_field('nostr_import_nonce', 'nostr_import_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="author_pubkey"><?php echo esc_html__('Author Pubkey/Npub', 'nostr-login'); ?></label>
                </th>
                <td>
                    <input type="text" id="author_pubkey" name="author_pubkey" class="regular-text" required>
                    <p class="description"><?php echo esc_html__('Enter the Nostr public key (hex or npub format)', 'nostr-login'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="date_from"><?php echo esc_html__('Date From', 'nostr-login'); ?></label>
                </th>
                <td>
                    <input type="date" id="date_from" name="date_from" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="date_to"><?php echo esc_html__('Date To', 'nostr-login'); ?></label>
                </th>
                <td>
                    <input type="date" id="date_to" name="date_to" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tag_filter"><?php echo esc_html__('Tag Filter', 'nostr-login'); ?></label>
                </th>
                <td>
                    <input type="text" id="tag_filter" name="tag_filter" class="regular-text">
                    <p class="description"><?php echo esc_html__('Optional: Filter by tag (without #)', 'nostr-login'); ?></p>
                </td>
            </tr>
        </table>

        <div class="nostr-loading-indicator" style="display: none;">
            <span class="spinner is-active"></span>
            <span><?php echo esc_html__('Loading...', 'nostr-login'); ?></span>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php echo esc_html__('Preview Posts', 'nostr-login'); ?>
            </button>
        </p>
    </form>

    <div id="preview-content"></div>
    
    <div id="import-preview" style="display: none;">
        <div id="import-progress" style="display: none;">
            <div class="progress-bar">
                <div class="progress-bar-fill" style="width: 0%"></div>
            </div>
            <div id="import-status"></div>
        </div>
        <p>
            <button id="start-import" class="button button-primary">
                <?php echo esc_html__('Import Selected Posts', 'nostr-login'); ?>
            </button>
        </p>
    </div>
</div>

<style>
    .progress-bar {
        width: 100%;
        height: 20px;
        background-color: #f0f0f1;
        border-radius: 3px;
        margin: 10px 0;
    }
    .progress-bar-fill {
        height: 100%;
        background-color: #2271b1;
        border-radius: 3px;
        transition: width 0.3s ease-in-out;
    }
    .nostr-loading-indicator {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 10px 0;
    }
    .nostr-loading-indicator .spinner {
        float: none;
        margin: 0;
    }
</style> 
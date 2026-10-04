<?php
/**
 * GLG Debug Toggle
 * Provides a simple Tools page to toggle debug logging for the free-gift/fVoucher scripts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Default option
if ( get_option( 'glg_free_gifts_debug' ) === false ) {
    add_option( 'glg_free_gifts_debug', false );
}

add_action( 'admin_menu', function() {
    // Add under Tools
    add_management_page(
        'GLG Free Gifts Debug',
        'GLG Debug',
        'manage_options',
        'glg-free-gifts-debug',
        'glg_free_gifts_debug_page'
    );
} );

function glg_free_gifts_debug_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Insufficient permissions', 'glg' ) );
    }

    // Handle save
    if ( isset( $_POST['glg_debug_nonce'] ) ) {
        if ( ! check_admin_referer( 'glg_debug_save', 'glg_debug_nonce' ) ) {
            echo '<div class="notice notice-error"><p>Security check failed.</p></div>';
        } else {
            $new = isset( $_POST['glg_free_gifts_debug'] ) && $_POST['glg_free_gifts_debug'] === '1';
            update_option( 'glg_free_gifts_debug', (bool) $new );
            echo '<div class="updated"><p>' . esc_html__( 'Debug setting updated.', 'glg' ) . '</p></div>';
        }
    }

    $current = (bool) get_option( 'glg_free_gifts_debug', false );
    ?>
    <div class="wrap">
        <h1>GLG Free Gifts Debug</h1>
        <form method="post">
            <?php wp_nonce_field( 'glg_debug_save', 'glg_debug_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Enable debug logging</th>
                    <td>
                        <label><input type="checkbox" name="glg_free_gifts_debug" value="1" <?php checked( $current ); ?> /> Enable console logging for debug</label>
                        <p class="description">When enabled, the free-gift/free-voucher client scripts will write small info logs to the browser console for troubleshooting.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


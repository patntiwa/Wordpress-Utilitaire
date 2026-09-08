<?php
/**
 * Plugin Name: Patineb - Transfer admin
 * Description: Outil temporaire pour transferer l'administration du site a un compte existant.
 * Version: 1.0.0
 * Author: Patineb
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'icx_register_admin_transfer_page' );
add_action( 'admin_post_icx_transfer_admin', 'icx_handle_admin_transfer' );

function icx_register_admin_transfer_page(): void {
    add_management_page(
        'Transferer l administration',
        'Transferer l administration',
        'manage_options',
        'icx-admin-transfer',
        'icx_render_admin_transfer_page'
    );
}

function icx_render_admin_transfer_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acces refuse.', 'inchclass-admin-transfer' ) );
    }

    $users = get_users(
        [
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'display_name', 'user_login', 'user_email', 'roles' ],
        ]
    );
    ?>
    <div class="wrap">
        <h1>Transferer l administration du site</h1>
        <p>
            Choisissez un compte existant. Ce compte recevra le role Administrateur
            et son adresse e-mail deviendra l adresse e-mail generale du site.
            Les autres administrateurs ne seront pas supprimes.
        </p>

        <?php if ( isset( $_GET['icx_transfer'] ) && 'success' === $_GET['icx_transfer'] ) : ?>
            <div class="notice notice-success is-dismissible"><p>Le transfert a ete effectue.</p></div>
        <?php elseif ( isset( $_GET['icx_transfer'] ) && 'error' === $_GET['icx_transfer'] ) : ?>
            <div class="notice notice-error is-dismissible"><p>Le transfert n a pas ete effectue.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="icx_transfer_admin">
            <?php wp_nonce_field( 'icx_transfer_admin' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="icx_target_user">Compte destinataire</label></th>
                    <td>
                        <select name="target_user" id="icx_target_user" required>
                            <option value="">-- Choisir un compte --</option>
                            <?php foreach ( $users as $user ) : ?>
                                <option value="<?php echo esc_attr( (string) $user->ID ); ?>">
                                    <?php echo esc_html( $user->display_name . ' - ' . $user->user_login . ' - ' . $user->user_email ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Confirmation</th>
                    <td>
                        <label>
                            <input type="checkbox" name="confirm_transfer" value="1" required>
                            Je confirme que le compte choisi doit administrer ce site.
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Transferer l administration', 'primary', 'submit', true ); ?>
        </form>
    </div>
    <?php
}

function icx_handle_admin_transfer(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acces refuse.', 'inchclass-admin-transfer' ) );
    }

    check_admin_referer( 'icx_transfer_admin' );

    $user_id = isset( $_POST['target_user'] ) ? absint( $_POST['target_user'] ) : 0;
    $confirmed = ! empty( $_POST['confirm_transfer'] );
    $user = $user_id ? get_userdata( $user_id ) : false;

    if ( ! $confirmed || ! $user || ! is_email( $user->user_email ) ) {
        wp_safe_redirect( add_query_arg( 'icx_transfer', 'error', admin_url( 'tools.php?page=icx-admin-transfer' ) ) );
        exit;
    }

    $user->set_role( 'administrator' );
    update_option( 'admin_email', sanitize_email( $user->user_email ) );

    wp_safe_redirect( add_query_arg( 'icx_transfer', 'success', admin_url( 'tools.php?page=icx-admin-transfer' ) ) );
    exit;
}

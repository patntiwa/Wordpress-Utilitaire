<?php
/**
 * Plugin Name: patineb - Reset password ponctuel
 * Description: Réinitialisation ponctuelle et protégée des comptes WordPress.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'icx_register_password_reset_page' );
add_action( 'admin_post_icx_reset_password', 'icx_handle_password_reset' );

function icx_register_password_reset_page(): void {
    add_management_page(
        'Réinitialiser un mot de passe',
        'Réinitialiser un mot de passe',
        'manage_options',
        'icx-password-reset',
        'icx_render_password_reset_page'
    );
}

function icx_render_password_reset_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Accès refusé.', 'inchclass-password-reset' ) );
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
        <h1>Réinitialiser un mot de passe</h1>
        <?php if ( isset( $_GET['icx_reset'] ) && 'success' === $_GET['icx_reset'] ) : ?>
            <div class="notice notice-success">
                <p><strong>Le mot de passe temporaire a été généré.</strong></p>
                <p>Note-le maintenant : il ne sera pas enregistré ni réaffiché.</p>
                <?php $temporary_password = get_transient( 'icx_reset_password_display_' . get_current_user_id() ); ?>
                <p><code style="font-size: 18px; user-select: all;"><?php echo esc_html( $temporary_password ?: 'Mot de passe non disponible' ); ?></code></p>
                <?php delete_transient( 'icx_reset_password_display_' . get_current_user_id() ); ?>
                <p>Connecte-toi avec ce mot de passe, puis supprime immédiatement le fichier <code>inchclass-password-reset.php</code> du dossier <code>wp-content/mu-plugins/</code>.</p>
            </div>
        <?php endif; ?>
        <p>Sélectionne n’importe quel compte WordPress. Aucun e-mail de validation ne sera envoyé.</p>
        <p>Un mot de passe aléatoire sera affiché une seule fois dans cette page. Le mot de passe actuel ne peut pas être récupéré.</p>
        <?php if ( ! $users ) : ?>
            <div class="notice notice-error"><p>Aucun compte WordPress disponible.</p></div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="icx_reset_password">
                <?php wp_nonce_field( 'icx_reset_password' ); ?>
                <p>
                    <label for="icx_target_user"><strong>Compte à réinitialiser</strong></label><br>
                    <select name="target_user" id="icx_target_user" required>
                        <option value="">-- Choisir un compte --</option>
                        <?php foreach ( $users as $user ) : ?>
                            <option value="<?php echo esc_attr( (string) $user->ID ); ?>">
                                <?php echo esc_html( $user->display_name . ' - ' . $user->user_login . ' - ' . $user->user_email ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p><label><input type="checkbox" name="confirm_reset" value="1" required> Je confirme réinitialiser le compte choisi.</label></p>
                <?php submit_button( 'Générer un nouveau mot de passe', 'primary' ); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function icx_handle_password_reset(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Accès refusé.', 'inchclass-password-reset' ) );
    }

    check_admin_referer( 'icx_reset_password' );

    if ( empty( $_POST['confirm_reset'] ) ) {
        wp_safe_redirect( admin_url( 'tools.php?page=icx-password-reset' ) );
        exit;
    }

    $user_id = isset( $_POST['target_user'] ) ? absint( $_POST['target_user'] ) : 0;
    $user = $user_id ? get_userdata( $user_id ) : false;
    if ( ! $user ) {
        wp_die( esc_html__( 'Compte introuvable.', 'inchclass-password-reset' ) );
    }

    $password = wp_generate_password( 28, true, true );
    wp_set_password( $password, $user->ID );

    set_transient( 'icx_reset_password_display_' . get_current_user_id(), $password, MINUTE_IN_SECONDS * 5 );
    wp_safe_redirect( admin_url( 'tools.php?page=icx-password-reset&icx_reset=success' ) );
    exit;
}

<?php
/**
 * Plugin Name: Force Default Single Post Template (Theme Builder)
 * Description: One-time / maintenance script to force ALL posts to use the default Elementor Theme Builder Single Post template.
 *              It removes Elementor builder mode from posts so they fall back to your Theme Builder template.
 *              Safe, with backup + dry-run mode.
 * Version: 1.0
 * Author: Arena Assistant (for Grayscale Geopolitics)
 *
 * === UTILISATION ===
 * 1. Copie ce fichier dans wp-content/mu-plugins/
 * 2. Va dans Outils → "Force Default Template"
 * 3. Commence toujours par "Dry Run" (Aperçu)
 * 4. Fais une sauvegarde WPvivid
 * 5. Clique sur "Appliquer à tous les articles"
 * 6. Une fois terminé, tu peux supprimer ce fichier du dossier mu-plugins.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ID du template Single Post que tu veux forcer (optionnel - laisse vide pour "tout ce qui est en Theme Builder")
define( 'GSG_TARGET_SINGLE_POST_TEMPLATE_ID', 0 ); // 0 = ne force pas un ID précis, juste enlève le mode Elementor builder

/**
 * Récupère tous les articles qui sont encore en mode Elementor builder
 */
function gsg_get_elementor_builder_posts( $limit = -1 ) {
    return get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $limit,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_elementor_edit_mode',
                'value'   => 'builder',
                'compare' => '=',
            ),
        ),
    ) );
}

/**
 * Force un article à utiliser le template par défaut (enlève le mode Elementor builder)
 */
function gsg_force_default_template_on_post( $post_id ) {
    $edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );

    if ( 'builder' !== $edit_mode ) {
        return array( false, 'Pas en mode Elementor builder' );
    }

    // Sauvegarde de sécurité
    if ( '' === get_post_meta( $post_id, '_gsg_force_default_backup', true ) ) {
        add_post_meta( $post_id, '_gsg_force_default_backup', $edit_mode, true );
    }

    // Supprime le mode builder → l'article utilise maintenant le Theme Builder
    delete_post_meta( $post_id, '_elementor_edit_mode' );

    // Optionnel : on peut aussi nettoyer d'autres metas Elementor si on veut un reset plus complet
    // delete_post_meta( $post_id, '_elementor_data' ); // DANGER - ne fais pas ça sauf si tu es sûr

    return array( true, 'Passé en mode template par défaut' );
}

/**
 * Compte les articles encore en mode builder
 */
function gsg_count_builder_posts() {
    return count( gsg_get_elementor_builder_posts( -1 ) );
}

/* ===================== ADMIN PAGE ===================== */

add_action( 'admin_menu', function () {
    add_management_page(
        'Force Default Single Post Template',
        'Force Default Template',
        'manage_options',
        'gsg-force-default-template',
        'gsg_force_default_template_page'
    );
} );

function gsg_force_default_template_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $action = isset( $_POST['gsg_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gsg_action'] ) ) : '';
    $msg    = '';

    if ( $action ) {
        check_admin_referer( 'gsg_force_default' );
    }

    // === ACTIONS ===
    if ( 'apply' === $action ) {
        $ids = gsg_get_elementor_builder_posts( 100 ); // Limite de sécurité
        $done = 0;
        $skipped = 0;

        foreach ( $ids as $post_id ) {
            list( $success ) = gsg_force_default_template_on_post( $post_id );
            if ( $success ) {
                $done++;
            } else {
                $skipped++;
            }
        }
        $msg = sprintf( '%d article(s) passé(s) en template par défaut. %d ignoré(s).', $done, $skipped );
    }

    // === AFFICHAGE ===
    $builder_count = gsg_count_builder_posts();
    $total_posts   = wp_count_posts( 'post' )->publish + wp_count_posts( 'post' )->draft + wp_count_posts( 'post' )->private;

    echo '<div class="wrap">';
    echo '<h1>Forcer le modèle Single Post par défaut</h1>';

    if ( $msg ) {
        echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
    }

    echo '<p><strong>Articles encore en mode Elementor builder :</strong> ' . intval( $builder_count ) . ' / ' . intval( $total_posts ) . '</p>';

    echo '<form method="post">';
    wp_nonce_field( 'gsg_force_default' );

    echo '<p>';
    echo '<button type="submit" name="gsg_action" value="apply" class="button button-primary" ' . ( $builder_count > 0 ? '' : 'disabled' ) . ' onclick="return confirm(\'ATTENTION : Fais d\'abord une sauvegarde WPvivid ! Continuer ?\')">Appliquer à tous les articles (max 100)</button>';
    echo ' <span class="description">Cette action enlève le mode Elementor builder. Les articles utiliseront ton template Theme Builder par défaut.</span>';
    echo '</p>';

    echo '</form>';

    // Aperçu des 15 premiers
    echo '<h2>Aperçu (aucune modification)</h2>';
    $preview = gsg_get_elementor_builder_posts( 15 );

    if ( empty( $preview ) ) {
        echo '<p style="color:green"><strong>Parfait !</strong> Aucun article n’est plus en mode Elementor builder.</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>ID</th><th>Titre</th><th>Statut</th></tr></thead><tbody>';
        foreach ( $preview as $id ) {
            $post = get_post( $id );
            echo '<tr>';
            echo '<td>' . intval( $id ) . '</td>';
            echo '<td>' . esc_html( $post->post_title ) . '</td>';
            echo '<td>' . esc_html( $post->post_status ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ( $builder_count > 15 ) {
            echo '<p class="description">... et ' . ( $builder_count - 15 ) . ' autres articles.</p>';
        }
    }

    echo '<hr>';
    echo '<p class="description"><strong>Conseil :</strong> Une fois que tous les articles sont passés en template par défaut, tu peux supprimer ce fichier du dossier mu-plugins.</p>';
    echo '</div>';
}

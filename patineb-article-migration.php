<?php
/**
 * Plugin Name: Patineb - Migration articles Elementor -> corps propre
 * Description: Outil ONE-SHOT pour convertir les articles bâtis avec Elementor en contenu "corps seul", compatible avec le template Single Post. Dry-run par défaut, réversible. v1.1 : extraction indépendante de la structure + diagnostic.
 * Version: 1.1
 * Author: Patrick Ntiwa
 *
 * UTILISATION (d'ABORD sur la copie locale) :
 *   1. Remplacer l'ancien fichier dans wp-content/mu-plugins/ par celui-ci.
 *   2. Outils -> "Migration articles".
 *   3. "Aperçu (dry-run)" : AUCUNE modif. Vérifier l'état + le corps/diagnostic.
 *   4. Sauvegarde WPvivid, PUIS "Convertir le prochain lot (20)".
 *   5. Souci -> "Rollback".
 *
 * v1.1 : au lieu d'exiger la structure exacte du modèle, l'extracteur parcourt
 *        tout l'arbre Elementor, IGNORE les colonnes "sidebar" (celles qui
 *        contiennent toc/post-list/posts/template SANS le chrome de l'article),
 *        et collecte le corps (text-editor / heading / image ...) partout ailleurs.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function gsg_body_types() {
	return array( 'text-editor', 'heading', 'image', 'video', 'audio', 'blockquote', 'icon-list', 'image-gallery', 'image-carousel', 'text-path' );
}

/* liste à plat des widgetTypes présents sous un noeud */
function gsg_subtree_types( $el ) {
	$types = array();
	$stack = array( $el );
	while ( $stack ) {
		$n = array_pop( $stack );
		if ( ! is_array( $n ) ) { continue; }
		if ( ! empty( $n['widgetType'] ) ) { $types[] = $n['widgetType']; }
		if ( ! empty( $n['elements'] ) && is_array( $n['elements'] ) ) {
			foreach ( $n['elements'] as $c ) { $stack[] = $c; }
		}
	}
	return $types;
}

/* un conteneur est une SIDEBAR pure s'il contient toc/post-list/posts/template
   mais AUCUN élément "chrome" de la colonne article */
function gsg_is_sidebar( $el ) {
	$types    = gsg_subtree_types( $el );
	$sidebar  = array_intersect( $types, array( 'table-of-contents', 'post-list', 'posts', 'template' ) );
	$chrome   = array_intersect( $types, array( 'hfe-breadcrumbs-widget', 'page-title', 'author-box', 'post-info', 'share-buttons' ) );
	return ! empty( $sidebar ) && empty( $chrome );
}

function gsg_collect_body( $el, &$out ) {
	if ( ! is_array( $el ) ) { return; }
	$wt = isset( $el['widgetType'] ) ? $el['widgetType'] : '';

	if ( $wt ) { // widget feuille
		$s = ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? $el['settings'] : array();
		if ( in_array( $wt, gsg_body_types(), true ) ) {
			if ( 'text-editor' === $wt && ! empty( $s['editor'] ) && is_string( $s['editor'] ) ) {
				$out[] = $s['editor'];
			} elseif ( 'heading' === $wt && ! empty( $s['title'] ) && is_string( $s['title'] ) ) {
				$tag = ! empty( $s['header_size'] ) ? strtolower( $s['header_size'] ) : 'h2';
				if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) { $tag = 'h2'; }
				$out[] = '<' . $tag . '>' . wp_kses_post( $s['title'] ) . '</' . $tag . '>';
			} elseif ( 'image' === $wt && ! empty( $s['image']['url'] ) ) {
				$out[] = '<figure><img src="' . esc_url( $s['image']['url'] ) . '" alt=""></figure>';
			}
		}
		return;
	}

	// conteneur / section / colonne
	if ( gsg_is_sidebar( $el ) ) { return; } // on saute toute la sidebar
	if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
		foreach ( $el['elements'] as $child ) { gsg_collect_body( $child, $out ); }
	}
}

function gsg_extract_body( $elementor_data ) {
	$data = json_decode( $elementor_data, true );
	if ( ! is_array( $data ) || empty( $data ) ) { return null; }
	$out = array();
	foreach ( $data as $node ) { gsg_collect_body( $node, $out ); }
	$html = trim( implode( "\n", $out ) );
	return ( '' !== $html ) ? $html : null;
}

/* diagnostic : widgetTypes présents dans l'article (comptés) */
function gsg_diag_widgets( $elementor_data ) {
	$data = json_decode( $elementor_data, true );
	if ( ! is_array( $data ) ) { return '(données illisibles)'; }
	$all = array();
	foreach ( $data as $node ) { $all = array_merge( $all, gsg_subtree_types( $node ) ); }
	if ( empty( $all ) ) { return '(aucun widget)'; }
	$counts = array_count_values( $all );
	arsort( $counts );
	$parts = array();
	foreach ( $counts as $t => $c ) { $parts[] = $t . '×' . $c; }
	return implode( ', ', array_slice( $parts, 0, 12 ) );
}

/* ---- articles Elementor pas encore convertis ---- */
function gsg_pending_ids( $limit = -1 ) {
	return get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_elementor_edit_mode', 'value' => 'builder' ),
			array( 'key' => '_gsg_converted', 'compare' => 'NOT EXISTS' ),
		),
	) );
}

function gsg_convert_one( $post_id ) {
	$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
	if ( 'builder' !== $edit_mode ) { return array( false, 'pas Elementor' ); }
	$data = get_post_meta( $post_id, '_elementor_data', true );
	$body = gsg_extract_body( $data );
	if ( null === $body ) { return array( false, 'corps vide -> à faire à la main' ); }
	$post = get_post( $post_id );
	if ( '' === get_post_meta( $post_id, '_gsg_content_backup', true ) ) {
		add_post_meta( $post_id, '_gsg_content_backup', $post->post_content, true );
	}
	if ( '' === get_post_meta( $post_id, '_gsg_elementor_backup', true ) ) {
		add_post_meta( $post_id, '_gsg_elementor_backup', $data, true );
	}
	add_post_meta( $post_id, '_gsg_editmode_backup', $edit_mode, true );
	wp_update_post( array( 'ID' => $post_id, 'post_content' => $body ) );
	delete_post_meta( $post_id, '_elementor_edit_mode' );
	update_post_meta( $post_id, '_gsg_converted', 1 );
	return array( true, 'converti' );
}

function gsg_rollback_one( $post_id ) {
	$content = get_post_meta( $post_id, '_gsg_content_backup', true );
	$mode    = get_post_meta( $post_id, '_gsg_editmode_backup', true );
	if ( '' === $mode ) { return false; }
	wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
	update_post_meta( $post_id, '_elementor_edit_mode', $mode ? $mode : 'builder' );
	delete_post_meta( $post_id, '_gsg_converted' );
	return true;
}

add_action( 'admin_menu', function () {
	add_management_page( 'Migration articles', 'Migration articles', 'manage_options', 'gsg-migration', 'gsg_migration_page' );
} );

function gsg_migration_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$action = isset( $_POST['gsg_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gsg_action'] ) ) : '';
	$msg = '';
	if ( $action ) { check_admin_referer( 'gsg_migration' ); }

	if ( 'convert' === $action ) {
		$ok = 0; $skip = 0;
		foreach ( gsg_pending_ids( 20 ) as $id ) { list( $done ) = gsg_convert_one( $id ); $done ? $ok++ : $skip++; }
		$msg = "Lot : {$ok} converti(s), {$skip} ignoré(s) (corps vide, à faire à la main).";
	} elseif ( 'rollback' === $action ) {
		$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => 20, 'fields' => 'ids',
			'meta_query' => array( array( 'key' => '_gsg_converted', 'value' => 1 ) ) ) );
		$ok = 0;
		foreach ( $ids as $id ) { if ( gsg_rollback_one( $id ) ) { $ok++; } }
		$msg = "Rollback : {$ok} article(s) restauré(s).";
	}

	$pending = gsg_pending_ids( -1 );
	$total_pending = count( $pending );
	$converted = count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => '_gsg_converted', 'value' => 1 ) ) ) ) );

	echo '<div class="wrap"><h1>Migration articles Elementor &rarr; corps propre</h1>';
	if ( $msg ) { echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>'; }
	echo '<p><strong>À convertir :</strong> ' . intval( $total_pending ) . ' &nbsp;|&nbsp; <strong>Déjà convertis :</strong> ' . intval( $converted ) . '</p>';

	echo '<form method="post" style="display:inline">';
	wp_nonce_field( 'gsg_migration' );
	echo '<input type="hidden" name="gsg_action" value="convert">';
	echo '<button class="button button-primary" ' . ( $total_pending ? '' : 'disabled' ) . ' onclick="return confirm(\'Sauvegarde WPvivid faite ? Convertir le prochain lot de 20 ?\')">Convertir le prochain lot (20)</button>';
	echo '</form> ';
	echo '<form method="post" style="display:inline;margin-left:8px">';
	wp_nonce_field( 'gsg_migration' );
	echo '<input type="hidden" name="gsg_action" value="rollback">';
	echo '<button class="button" onclick="return confirm(\'Restaurer le dernier lot converti (20) ?\')">Rollback (20)</button>';
	echo '</form>';

	echo '<h2 style="margin-top:24px">Aperçu (dry-run) — aucune modification</h2>';
	$preview_ids = array_slice( $pending, 0, 10 );
	if ( empty( $preview_ids ) ) {
		echo '<p>Aucun article Elementor en attente.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Titre</th><th>État</th><th>Aperçu du corps / diagnostic widgets</th></tr></thead><tbody>';
		foreach ( $preview_ids as $id ) {
			$data = get_post_meta( $id, '_elementor_data', true );
			$body = gsg_extract_body( $data );
			if ( null === $body ) {
				$state = '<span style="color:#b32d2e">vide</span>';
				$cell  = '<em>widgets détectés :</em> ' . esc_html( gsg_diag_widgets( $data ) );
			} else {
				$state = '<span style="color:#1a7f37">OK</span> (' . strlen( $body ) . ' car.)';
				$cell  = esc_html( wp_trim_words( wp_strip_all_tags( $body ), 40, '…' ) );
			}
			echo '<tr><td>' . intval( $id ) . '</td><td>' . esc_html( get_the_title( $id ) ) . '</td><td>' . $state . '</td><td>' . $cell . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Rien n\'est modifié ici. Pour les lignes « vide », le diagnostic montre les widgets présents afin d\'adapter l\'extraction si besoin.</p>';
	}
	echo '</div>';
}

<?php
/**
 * Compatibilità con il plugin Intranet UNIPV.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

function desiitse_intranet_unipv_plugin_file(): string {
	return 'intranet-unipv/intranet-frontend-saml.php';
}

function desiitse_is_intranet_unipv_installed(): bool {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];
	return isset( $plugins[ desiitse_intranet_unipv_plugin_file() ] );
}

function desiitse_is_intranet_unipv_active(): bool {
	if ( ! desiitse_is_intranet_unipv_installed() ) {
		return false;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin = desiitse_intranet_unipv_plugin_file();
	return ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) )
		|| ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin ) );
}

function desiitse_intranet_protected_meta_key(): string {
	return '_intra_protect';
}

function desiitse_is_intranet_protected_post( $post_or_id ): bool {
	if ( ! desiitse_is_intranet_unipv_active() ) {
		return false;
	}

	$post_id = $post_or_id instanceof WP_Post ? (int) $post_or_id->ID : (int) $post_or_id;
	if ( $post_id <= 0 ) {
		return false;
	}

	return get_post_meta( $post_id, desiitse_intranet_protected_meta_key(), true ) === '1';
}

function desiitse_is_publicly_reachable_post( $post_or_id ): bool {
	return ! desiitse_is_intranet_protected_post( $post_or_id );
}

function desiitse_intranet_public_meta_query(): array {
	if ( ! desiitse_is_intranet_unipv_active() ) {
		return [];
	}

	return [
		'relation' => 'OR',
		[
			'key'     => desiitse_intranet_protected_meta_key(),
			'compare' => 'NOT EXISTS',
		],
		[
			'key'     => desiitse_intranet_protected_meta_key(),
			'value'   => '1',
			'compare' => '!=',
		],
	];
}

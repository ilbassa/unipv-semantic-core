<?php
/**
 * Cache JSON-LD a due livelli: transient + option persistente.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

function desiitse_all_slugs(): array {
	return [
		'unipv-graph',
		'persone',
		'strutture',
		'eventi',
		'progetti',
		'indirizzi-di-ricerca',
		'pubblicazioni',
	];
}

function desiitse_active_slugs(): array {
	return desiitse_all_slugs();
}

function desiitse_cpt_slug_map(): array {
	return [
		'persona'              => [ 'persone', 'unipv-graph' ],
		'struttura'            => [ 'strutture', 'unipv-graph' ],
		'evento'               => [ 'eventi', 'unipv-graph' ],
		'progetto'             => [ 'progetti', 'unipv-graph' ],
		'indirizzo-di-ricerca' => [ 'indirizzi-di-ricerca', 'unipv-graph' ],
		'pubblicazione'        => [ 'pubblicazioni', 'unipv-graph' ],
	];
}

function desiitse_transient_key( string $slug ): string {
	return 'desiitse_graph_' . sanitize_key( $slug );
}

function desiitse_transient_get( string $slug ): ?array {
	$data = get_transient( desiitse_transient_key( $slug ) );
	return ( is_array( $data ) && ! empty( $data['@graph'] ) ) ? $data : null;
}

function desiitse_transient_set( string $slug, array $data ): void {
	set_transient( desiitse_transient_key( $slug ), $data, DESIITSE_CACHE_TTL );
}

function desiitse_transient_delete( string $slug ): void {
	delete_transient( desiitse_transient_key( $slug ) );
}

function desiitse_option_key( string $slug ): string {
	return 'desiitse_graph_option_' . sanitize_key( $slug );
}

function desiitse_option_get( string $slug ): ?array {
	$data = get_option( desiitse_option_key( $slug ), null );
	return ( is_array( $data ) && ! empty( $data['@graph'] ) ) ? $data : null;
}

function desiitse_option_set( string $slug, array $data ): void {
	update_option( desiitse_option_key( $slug ), $data, false );
}

function desiitse_option_delete( string $slug ): void {
	delete_option( desiitse_option_key( $slug ) );
}

function desiitse_cached_response( string $slug, callable $builder ): WP_REST_Response {
	$data = desiitse_transient_get( $slug );
	if ( $data !== null ) {
		return rest_ensure_response( $data );
	}

	$data = desiitse_option_get( $slug );
	if ( $data !== null ) {
		desiitse_transient_set( $slug, $data );
		return rest_ensure_response( $data );
	}

	return rest_ensure_response( desiitse_build_and_persist( $slug, $builder ) );
}

function desiitse_build_and_persist( string $slug, callable $builder ): array {
	$nodes = array_values( array_filter( $builder() ) );
	$data  = [
		'@context' => desiitse_context(),
		'@graph'   => $nodes,
	];

	desiitse_option_set( $slug, $data );
	desiitse_transient_set( $slug, $data );

	return $data;
}

function desiitse_invalidate( string $slug ): void {
	desiitse_transient_delete( $slug );
	desiitse_option_delete( $slug );
}

function desiitse_invalidate_all(): void {
	foreach ( desiitse_all_slugs() as $slug ) {
		desiitse_invalidate( $slug );
	}
}

function desiitse_invalidate_for_post( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$map = desiitse_cpt_slug_map();
	if ( ! isset( $map[ $post->post_type ] ) ) {
		return;
	}

	foreach ( $map[ $post->post_type ] as $slug ) {
		desiitse_invalidate( $slug );
		desiitse_mark_dirty( $slug );
	}
}

register_activation_hook( DESIITSE_PLUGIN_FILE, function () {
	desiitse_invalidate_all();
	desiitse_schedule_rebuild_all();
} );

register_deactivation_hook( DESIITSE_PLUGIN_FILE, 'desiitse_invalidate_all' );

add_action( 'save_post', function ( int $post_id, WP_Post $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	desiitse_invalidate_for_post( $post_id );
}, 10, 2 );

add_action( 'wp_trash_post', 'desiitse_invalidate_for_post' );
add_action( 'before_delete_post', 'desiitse_invalidate_for_post' );

add_action( 'added_post_meta', function ( $meta_id, int $post_id ) {
	desiitse_invalidate_for_post( $post_id );
}, 10, 2 );
add_action( 'updated_post_meta', function ( $meta_id, int $post_id ) {
	desiitse_invalidate_for_post( $post_id );
}, 10, 2 );
add_action( 'deleted_post_meta', function ( $meta_id, int $post_id ) {
	desiitse_invalidate_for_post( $post_id );
}, 10, 2 );

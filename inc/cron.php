<?php
/**
 * Rebuild asincrono dei grafi JSON-LD via WP-Cron.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

define( 'DESIITSE_CRON_DELAY', (int) apply_filters( 'desiitse_cron_delay', 60 ) );
define( 'DESIITSE_DIRTY_OPTION', 'desiitse_dirty_slugs' );
define( 'DESIITSE_CRON_HOOK', 'desiitse_rebuild_dirty' );

function desiitse_slug_builder_map(): array {
	return [
		'unipv-graph'          => 'desiitse_build_unipv_nodes',
		'persone'              => 'desiitse_build_unipv_persona_nodes',
		'strutture'            => 'desiitse_build_unipv_struttura_nodes',
		'eventi'               => 'desiitse_build_unipv_evento_nodes',
		'progetti'             => 'desiitse_build_unipv_progetto_nodes',
		'indirizzi-di-ricerca' => 'desiitse_build_unipv_indirizzo_nodes',
		'pubblicazioni'        => 'desiitse_build_unipv_pubblicazione_nodes',
	];
}

function desiitse_mark_dirty( string $slug ): void {
	$dirty          = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	$dirty[ $slug ] = true;
	update_option( DESIITSE_DIRTY_OPTION, $dirty, false );

	if ( ! wp_next_scheduled( DESIITSE_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + DESIITSE_CRON_DELAY, DESIITSE_CRON_HOOK );
	}
}

function desiitse_schedule_rebuild_all(): void {
	update_option( DESIITSE_DIRTY_OPTION, array_fill_keys( desiitse_active_slugs(), true ), false );

	if ( ! wp_next_scheduled( DESIITSE_CRON_HOOK ) ) {
		wp_schedule_single_event( time() + 10, DESIITSE_CRON_HOOK );
	}
}

function desiitse_rebuild_dirty_graphs(): void {
	$dirty = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	if ( empty( $dirty ) ) {
		return;
	}

	update_option( DESIITSE_DIRTY_OPTION, [], false );
	$map = desiitse_slug_builder_map();

	foreach ( array_keys( $dirty ) as $slug ) {
		if ( isset( $map[ $slug ] ) && is_callable( $map[ $slug ] ) ) {
			desiitse_build_and_persist( $slug, $map[ $slug ] );
		}
	}
}
add_action( DESIITSE_CRON_HOOK, 'desiitse_rebuild_dirty_graphs' );

register_deactivation_hook( DESIITSE_PLUGIN_FILE, function () {
	$timestamp = wp_next_scheduled( DESIITSE_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, DESIITSE_CRON_HOOK );
	}
	delete_option( DESIITSE_DIRTY_OPTION );
} );

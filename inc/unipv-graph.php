<?php
/**
 * Exporter JSON-LD per i custom post type UNIPV.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

const DESIITSE_UNIPV_REST_NAMESPACE = 'unipv/v1';

add_action( 'rest_api_init', function () {
	$routes = [
		'/graph'                      => [ 'unipv-graph', 'desiitse_build_unipv_nodes' ],
		'/graph/persone'              => [ 'persone', 'desiitse_build_unipv_persona_nodes' ],
		'/graph/strutture'            => [ 'strutture', 'desiitse_build_unipv_struttura_nodes' ],
		'/graph/eventi'               => [ 'eventi', 'desiitse_build_unipv_evento_nodes' ],
		'/graph/progetti'             => [ 'progetti', 'desiitse_build_unipv_progetto_nodes' ],
		'/graph/indirizzi-di-ricerca' => [ 'indirizzi-di-ricerca', 'desiitse_build_unipv_indirizzo_nodes' ],
		'/graph/pubblicazioni'        => [ 'pubblicazioni', 'desiitse_build_unipv_pubblicazione_nodes' ],
	];

	foreach ( $routes as $route => [ $slug, $builder ] ) {
		register_rest_route( DESIITSE_UNIPV_REST_NAMESPACE, $route, [
			'methods'             => 'GET',
			'callback'            => fn( WP_REST_Request $request ) => desiitse_cached_response( $slug, $builder ),
			'permission_callback' => '__return_true',
		] );
	}
} );

function desiitse_unipv_posts( string $post_type ): array {
	if ( ! post_type_exists( $post_type ) ) {
		return [];
	}

	return get_posts( [
		'post_type'      => $post_type,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );
}

function desiitse_unipv_meta( int $post_id, string $key ): string {
	return desiitse_clean_text( get_post_meta( $post_id, $key, true ) );
}

function desiitse_unipv_url_meta( int $post_id, string $key ): string {
	return esc_url_raw( (string) get_post_meta( $post_id, $key, true ) );
}

function desiitse_unipv_date( int $post_id, string $date_key, string $time_key = '' ): string {
	$date = trim( (string) get_post_meta( $post_id, $date_key, true ) );
	if ( $date === '' ) {
		return '';
	}

	$time   = $time_key !== '' ? trim( (string) get_post_meta( $post_id, $time_key, true ) ) : '';
	$raw    = trim( $date . ' ' . $time );
	$format = $time !== '' ? 'd/m/Y H:i' : 'd/m/Y';
	$dt     = DateTime::createFromFormat( $format, $raw, wp_timezone() );

	return $dt instanceof DateTime ? $dt->format( DATE_ATOM ) : desiitse_clean_text( $raw );
}

function desiitse_unipv_refs( int $post_id, string $meta_key ): array {
	$raw = maybe_unserialize( get_post_meta( $post_id, $meta_key, true ) );
	if ( empty( $raw ) ) {
		return [];
	}

	$ids  = [];
	$walk = function ( $value ) use ( &$walk, &$ids ) {
		if ( $value instanceof WP_Post ) {
			$ids[] = (int) $value->ID;
			return;
		}
		if ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( [ 'ID', 'id' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
					$ids[] = (int) $value[ $key ];
					return;
				}
			}
			foreach ( $value as $item ) {
				$walk( $item );
			}
		}
	};
	$walk( $raw );

	return array_values( array_unique( array_filter( $ids ) ) );
}

function desiitse_unipv_ref_list( array $ids ): array {
	return array_values( array_map( fn( $id ) => [ '@id' => desiitse_node_id( (int) $id ) ], $ids ) );
}

function desiitse_build_unipv_nodes(): array {
	return desiitse_unique_nodes( array_merge(
		[
			desiitse_university_data(),
			[
				'@type'         => 'foaf:Document',
				'@id'           => home_url( '/' ),
				'dct:title'     => desiitse_clean_text( get_bloginfo( 'name' ) ),
				'dct:publisher' => desiitse_university_ref(),
			],
		],
		desiitse_build_unipv_persona_nodes(),
		desiitse_build_unipv_struttura_nodes(),
		desiitse_build_unipv_evento_nodes(),
		desiitse_build_unipv_progetto_nodes(),
		desiitse_build_unipv_indirizzo_nodes(),
		desiitse_build_unipv_pubblicazione_nodes()
	) );
}

function desiitse_build_unipv_persona_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'persona' ) as $post ) {
		$nome    = desiitse_unipv_meta( $post->ID, 'nome' );
		$cognome = desiitse_unipv_meta( $post->ID, 'cognome' );
		$email   = desiitse_unipv_meta( $post->ID, 'email' );
		$phone   = desiitse_unipv_meta( $post->ID, 'telefono' );
		$url     = desiitse_unipv_url_meta( $post->ID, 'sito_web' );
		$role    = desiitse_unipv_meta( $post->ID, 'titolo' );
		$title   = trim( $nome . ' ' . $cognome ) ?: desiitse_clean_text( get_the_title( $post ) );

		$node = [
			'@type'     => 'cpv:Person',
			'@id'       => desiitse_node_id( $post ),
			'dct:title' => $title,
		];

		if ( $nome !== '' ) {
			$node['cpv:givenName'] = $nome;
		}
		if ( $cognome !== '' ) {
			$node['cpv:familyName'] = $cognome;
		}
		if ( $email !== '' ) {
			$node['sm:email'] = $email;
		}
		if ( $phone !== '' ) {
			$node['sm:telephone'] = $phone;
		}
		if ( $url !== '' ) {
			$node['sm:URL'] = $url;
		}
		if ( $role !== '' ) {
			$node['ro:withRole'] = $role;
		}

		desiitse_add_descriptions( $node, [ $post->post_content ] );
		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

function desiitse_build_unipv_struttura_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'struttura' ) as $post ) {
		$title = desiitse_clean_text( get_the_title( $post ) );
		$node  = [
			'@type'               => 'cov:Organization',
			'@id'                 => desiitse_node_id( $post ),
			'dct:title'           => $title,
			'cov:legalName'       => $title,
			'cov:hasOrganization' => desiitse_university_ref(),
		];

		desiitse_add_descriptions( $node, [
			desiitse_unipv_meta( $post->ID, 'descrizione_breve' ),
			$post->post_content,
			desiitse_unipv_meta( $post->ID, 'strumentazione' ),
			desiitse_unipv_meta( $post->ID, 'software' ),
		] );

		foreach ( [
			'persone-struttura',
			'progetti-struttura',
			'pubblicazioni-struttura',
		] as $meta_key ) {
			$ids = desiitse_unipv_refs( $post->ID, $meta_key );
			if ( ! empty( $ids ) ) {
				desiitse_add_ref_property( $node, 'dct:relation', desiitse_unipv_ref_list( $ids ) );
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $ids ) );
			}
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

function desiitse_build_unipv_evento_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'evento' ) as $post ) {
		$title = desiitse_clean_text( get_the_title( $post ) );
		$node  = [
			'@type'           => 'cpev:PublicEvent',
			'@id'             => desiitse_node_id( $post ),
			'dct:title'       => $title,
			'cpev:eventTitle' => $title,
			'dct:publisher'   => desiitse_university_ref(),
		];

		$start = desiitse_unipv_date( $post->ID, 'data_inizio', 'orario_inizio' );
		$end   = desiitse_unipv_date( $post->ID, 'data_fine' );
		$url   = desiitse_unipv_url_meta( $post->ID, 'sitoweb' );
		$place = desiitse_unipv_meta( $post->ID, 'luogo' );

		if ( $start !== '' ) {
			$node['ti:startTime'] = $start;
		}
		if ( $end !== '' ) {
			$node['ti:endTime'] = $end;
		}
		if ( $url !== '' ) {
			$node['sm:URL'] = $url;
		}
		if ( $place !== '' ) {
			$node['cpev:takesPlaceIn'] = [
				'@id'       => desiitse_node_id( $post, '#place' ),
				'@type'     => 'l0:Location',
				'dct:title' => $place,
			];
		}

		desiitse_add_descriptions( $node, [ desiitse_unipv_meta( $post->ID, 'descrizione_breve' ), $post->post_content ] );

		foreach ( [
			'persone'              => 'dct:contributor',
			'progetto'             => 'dct:relation',
			'indirizzo_di_ricerca' => 'dct:subject',
		] as $meta_key => $property ) {
			$ids = desiitse_unipv_refs( $post->ID, $meta_key );
			if ( ! empty( $ids ) ) {
				desiitse_add_ref_property( $node, $property, desiitse_unipv_ref_list( $ids ) );
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $ids ) );
			}
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

function desiitse_build_unipv_progetto_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'progetto' ) as $post ) {
		$title = desiitse_clean_text( get_the_title( $post ) );
		$node  = [
			'@type'         => 'her:PublicResearchProject',
			'@id'           => desiitse_node_id( $post ),
			'dct:title'     => $title,
			'dct:publisher' => desiitse_university_ref(),
		];

		$start = desiitse_unipv_date( $post->ID, 'data_inizio' );
		$end   = desiitse_unipv_date( $post->ID, 'data_fine' );
		$url   = desiitse_unipv_url_meta( $post->ID, 'url' );

		if ( $start !== '' ) {
			$node['ti:startTime'] = $start;
		}
		if ( $end !== '' ) {
			$node['ti:endTime'] = $end;
		}
		if ( $url !== '' ) {
			$node['sm:URL'] = $url;
		}

		desiitse_add_descriptions( $node, [ desiitse_unipv_meta( $post->ID, 'descrizione_breve' ), $post->post_content ] );

		foreach ( [
			'responsabile_del_progetto'              => 'dct:contributor',
			'persone'                               => 'dct:contributor',
			'elenco_indirizzi_di_ricerca_correlati' => 'dct:subject',
			'pubblicazioni'                         => 'dct:relation',
		] as $meta_key => $property ) {
			$ids = desiitse_unipv_refs( $post->ID, $meta_key );
			if ( ! empty( $ids ) ) {
				desiitse_add_ref_property( $node, $property, desiitse_unipv_ref_list( $ids ) );
				$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $ids ) );
			}
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

function desiitse_build_unipv_indirizzo_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'indirizzo-di-ricerca' ) as $post ) {
		$title = desiitse_clean_text( get_the_title( $post ) );
		$node  = [
			'@type'         => 'skos:Concept',
			'@id'           => desiitse_node_id( $post ),
			'dct:title'     => $title,
			'skos:prefLabel' => $title,
			'dct:publisher' => desiitse_university_ref(),
		];

		foreach ( [
			'sitioweb'  => 'sm:URL',
			'email'    => 'sm:email',
			'telefono' => 'sm:telephone',
		] as $meta_key => $property ) {
			$value = $property === 'sm:URL' ? desiitse_unipv_url_meta( $post->ID, $meta_key ) : desiitse_unipv_meta( $post->ID, $meta_key );
			if ( $value !== '' ) {
				$node[ $property ] = $value;
			}
		}

		desiitse_add_descriptions( $node, [ desiitse_unipv_meta( $post->ID, 'descrizione_breve' ), $post->post_content ] );

		$leader_ids = desiitse_unipv_refs( $post->ID, 'responsabile_attivita_di_ricerca' );
		if ( ! empty( $leader_ids ) ) {
			$node['dct:contributor'] = desiitse_unipv_ref_list( $leader_ids );
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $leader_ids ) );
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

function desiitse_build_unipv_pubblicazione_nodes(): array {
	$nodes = [];
	foreach ( desiitse_unipv_posts( 'pubblicazione' ) as $post ) {
		$title = desiitse_clean_text( get_the_title( $post ) );
		$node  = [
			'@type'         => 'foaf:Document',
			'@id'           => desiitse_node_id( $post ),
			'dct:title'     => $title,
			'dct:publisher' => desiitse_university_ref(),
		];

		foreach ( [
			'anno'          => 'dct:issued',
			'autori'        => 'dct:creator',
			'pubblicato-in' => 'dct:isPartOf',
		] as $meta_key => $property ) {
			$value = desiitse_unipv_meta( $post->ID, $meta_key );
			if ( $value !== '' ) {
				$node[ $property ] = $value;
			}
		}

		$url = desiitse_unipv_url_meta( $post->ID, 'url' );
		if ( $url !== '' ) {
			$node['sm:URL'] = $url;
		}

		$image = desiitse_unipv_url_meta( $post->ID, 'url_immagine' );
		if ( $image !== '' ) {
			$node['sm:hasImage'] = [ '@id' => $image ];
		}

		$internal_authors = desiitse_unipv_refs( $post->ID, 'autori-interni' );
		if ( ! empty( $internal_authors ) ) {
			$author_refs = desiitse_unipv_ref_list( $internal_authors );
			if ( isset( $node['dct:creator'] ) ) {
				$node['dct:creator'] = array_merge( [ $node['dct:creator'] ], $author_refs );
			} else {
				$node['dct:creator'] = $author_refs;
			}
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $internal_authors ) );
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

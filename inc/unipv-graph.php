<?php
/**
 * Exporter JSON-LD per i custom post type UNIPV.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

const DESIITSE_UNIPV_REST_NAMESPACE = 'unipv/v1';
const DESIITSE_UNIPV_NETWORK_SITES_OPTION = 'desiitse_unipv_network_published_site_ids';
const DESIITSE_UNIPV_NETWORK_EXCLUDED_SITES_OPTION = 'desiitse_unipv_network_excluded_site_ids';
const DESIITSE_UNIPV_NETWORK_CACHE_KEY = 'desiitse_unipv_network_graph_index';

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

add_action( 'rest_api_init', function () {
	if ( ! is_multisite() ) {
		return;
	}

	register_rest_route( DESIITSE_UNIPV_REST_NAMESPACE, '/network/graphs', [
		'methods'             => 'GET',
		'callback'            => fn( WP_REST_Request $request ) => rest_ensure_response( desiitse_unipv_network_graph_index() ),
		'permission_callback' => '__return_true',
	] );
} );

function desiitse_unipv_network_graph_index(): array {
	// Esegue l'eventuale migrazione dalla precedente allowlist prima di leggere la cache.
	desiitse_unipv_network_excluded_site_ids();

	$cached = get_site_transient( DESIITSE_UNIPV_NETWORK_CACHE_KEY );
	if ( is_array( $cached ) && isset( $cached['graphs'] ) && is_array( $cached['graphs'] ) ) {
		return $cached;
	}

	$index = [
		'@context' => [
			'dct' => 'http://purl.org/dc/terms/',
			'sm'  => 'https://w3id.org/italia/onto/SM/',
		],
		'graphs'   => desiitse_unipv_network_graph_rows(),
	];

	set_site_transient( DESIITSE_UNIPV_NETWORK_CACHE_KEY, $index, DESIITSE_CACHE_TTL );

	return $index;
}

function desiitse_unipv_invalidate_network_graph_index(): void {
	if ( is_multisite() ) {
		delete_site_transient( DESIITSE_UNIPV_NETWORK_CACHE_KEY );
	}
}

/**
 * Normalizza un elenco di blog ID.
 *
 * @return int[]
 */
function desiitse_unipv_normalize_site_ids( $value ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
	sort( $ids, SORT_NUMERIC );

	return $ids;
}

/**
 * Siti che possono essere pubblicati nell'indice del network corrente.
 *
 * @return WP_Site[]
 */
function desiitse_unipv_network_candidate_sites(): array {
	if ( ! is_multisite() ) {
		return [];
	}

	return get_sites( [
		'number'     => 0,
		'network_id' => get_current_network_id(),
		'public'     => 1,
		'archived'   => 0,
		'mature'     => 0,
		'deleted'    => 0,
		'spam'       => 0,
	] );
}

/**
 * IDs dei siti esclusi dall'indice di network.
 *
 * Senza esclusioni configurate, tutti i siti pubblici sono inclusi. La
 * precedente allowlist viene convertita una sola volta per conservare le
 * esclusioni già scelte.
 *
 * @return int[]
 */
function desiitse_unipv_network_excluded_site_ids(): array {
	if ( ! is_multisite() ) {
		return [];
	}

	$value = get_site_option( DESIITSE_UNIPV_NETWORK_EXCLUDED_SITES_OPTION, null );
	if ( $value !== null ) {
		return desiitse_unipv_normalize_site_ids( $value );
	}

	$legacy_published = get_site_option( DESIITSE_UNIPV_NETWORK_SITES_OPTION, null );
	if ( $legacy_published === null ) {
		return [];
	}

	$candidate_ids = array_map(
		fn( WP_Site $site ) => (int) $site->blog_id,
		desiitse_unipv_network_candidate_sites()
	);
	$excluded_ids = desiitse_unipv_normalize_site_ids(
		array_diff( $candidate_ids, desiitse_unipv_normalize_site_ids( $legacy_published ) )
	);

	update_site_option( DESIITSE_UNIPV_NETWORK_EXCLUDED_SITES_OPTION, $excluded_ids );
	delete_site_option( DESIITSE_UNIPV_NETWORK_SITES_OPTION );
	desiitse_unipv_invalidate_network_graph_index();

	return $excluded_ids;
}

function desiitse_unipv_network_site_is_published( int $blog_id ): bool {
	return ! in_array( $blog_id, desiitse_unipv_network_excluded_site_ids(), true );
}

// Completa l'eventuale migrazione prima che nello stesso request venga creato un nuovo sito.
add_action( 'init', 'desiitse_unipv_network_excluded_site_ids', 1 );

function desiitse_unipv_site_type_options(): array {
	return [
		'evento'                 => 'Evento',
		'laboratorio_di_ricerca' => 'Laboratorio di ricerca',
		'struttura_di_ateneo'    => 'Struttura di Ateneo',
		'progetto_di_ricerca'    => 'Progetto di ricerca',
	];
}

function desiitse_unipv_site_type_label( string $type ): string {
	$options = desiitse_unipv_site_type_options();
	return desiitse_clean_text( $options[ $type ] ?? str_replace( '_', ' ', $type ) );
}

function desiitse_unipv_network_graph_rows(): array {
	if ( ! is_multisite() ) {
		return [];
	}

	$rows = [];
	foreach ( desiitse_unipv_network_candidate_sites() as $site ) {
		if ( ! desiitse_unipv_network_site_is_published( (int) $site->blog_id ) ) {
			continue;
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			$type = desiitse_unipv_site_option( 'tipologia_sito' );

			$rows[] = [
				'name'          => desiitse_clean_text( get_bloginfo( 'name' ) ),
				'tipologia'     => $type,
				'tipologiaName' => $type !== '' ? desiitse_unipv_site_type_label( $type ) : '',
				'home_url'      => home_url( '/' ),
				'rest_url'      => rest_url( DESIITSE_UNIPV_REST_NAMESPACE . '/graph' ),
			];
		} finally {
			restore_current_blog();
		}
	}

	return $rows;
}

function desiitse_unipv_maybe_invalidate_network_graph_index( string $option ): void {
	$watched = array_merge(
		[ 'blogname', 'home', 'siteurl' ],
		desiitse_unipv_option_containers(),
		array_map(
			fn( $prefix ) => $prefix . 'tipologia_sito',
			desiitse_unipv_option_prefixes()
		)
	);

	if ( in_array( $option, $watched, true ) || str_starts_with( $option, 'theme_mods_' ) ) {
		desiitse_unipv_invalidate_network_graph_index();
	}
}

add_action( 'added_option', 'desiitse_unipv_maybe_invalidate_network_graph_index', 10, 1 );
add_action( 'updated_option', 'desiitse_unipv_maybe_invalidate_network_graph_index', 10, 1 );
add_action( 'deleted_option', 'desiitse_unipv_maybe_invalidate_network_graph_index', 10, 1 );
add_action( 'wp_initialize_site', 'desiitse_unipv_invalidate_network_graph_index', 10, 0 );
add_action( 'wp_update_site', 'desiitse_unipv_invalidate_network_graph_index', 10, 0 );
add_action( 'wp_delete_site', 'desiitse_unipv_invalidate_network_graph_index', 10, 0 );

function desiitse_unipv_posts( string $post_type ): array {
	if ( ! post_type_exists( $post_type ) ) {
		return [];
	}

	$args = [
		'post_type'      => $post_type,
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'title',
		'order'          => 'ASC',
	];

	$meta_query = desiitse_intranet_public_meta_query();
	if ( ! empty( $meta_query ) ) {
		$args['meta_query'] = $meta_query;
	}

	return get_posts( $args );
}

function desiitse_unipv_meta( int $post_id, string $key ): string {
	return desiitse_clean_text( get_post_meta( $post_id, $key, true ) );
}

function desiitse_unipv_url_meta( int $post_id, string $key ): string {
	return esc_url_raw( (string) get_post_meta( $post_id, $key, true ) );
}

function desiitse_unipv_department_options(): array {
	return [
		'dipartimento_biologia_biotecnologie_lazzaro_spallanzani'             => 'Dipartimento di Biologia e Biotecnologie "Lazzaro Spallanzani"',
		'dipartimento_chimica'                                                => 'Dipartimento di Chimica',
		'dipartimento_fisica'                                                 => 'Dipartimento di Fisica',
		'dipartimento_giurisprudenza'                                         => 'Dipartimento di Giurisprudenza',
		'dipartimento_ingegneria_civile_architettura'                         => 'Dipartimento di Ingegneria Civile e Architettura',
		'dipartimento_ingegneria_industriale_informazione'                    => 'Dipartimento di Ingegneria Industriale e dell\'Informazione',
		'dipartimento_matematica'                                             => 'Dipartimento di Matematica',
		'dipartimento_medicina_interna_terapia_medica'                        => 'Dipartimento di Medicina Interna e Terapia Medica',
		'dipartimento_medicina_molecolare'                                    => 'Dipartimento di Medicina Molecolare',
		'dipartimento_sanita_pubblica_medicina_sperimentale_forense'          => 'Dipartimento di Sanita Pubblica, Medicina Sperimentale e Forense',
		'dipartimento_scienze_clinico_chirurgiche_diagnostiche_pediatriche'   => 'Dipartimento di Scienze Clinico Chirurgiche, Diagnostiche e Pediatriche',
		'dipartimento_scienze_economiche_aziendali'                           => 'Dipartimento di Scienze Economiche e Aziendali',
		'dipartimento_scienze_farmaco'                                        => 'Dipartimento di Scienze del Farmaco',
		'dipartimento_musicologia_beni_culturali'                             => 'Dipartimento di Musicologia e Beni Culturali',
		'dipartimento_scienze_politiche_sociali'                              => 'Dipartimento di Scienze Politiche e Sociali',
		'dipartimento_scienze_sistema_nervoso_comportamento'                  => 'Dipartimento di Scienze del Sistema Nervoso e del Comportamento (dal 1 gennaio 2013)',
		'dipartimento_scienze_terra_ambiente'                                 => 'Dipartimento di Scienze della Terra e dell\'Ambiente',
		'dipartimento_studi_umanistici'                                       => 'Dipartimento di Studi Umanistici',
	];
}

function desiitse_unipv_option_prefixes(): array {
	return apply_filters( 'desiitse_unipv_option_prefixes', [
		'',
		'_design_unipv_ginevra_',
		'design_unipv_ginevra_',
		'_unipv_',
		'unipv_',
	] );
}

function desiitse_unipv_option_containers(): array {
	return apply_filters( 'desiitse_unipv_option_containers', [
		'dli_options',
		'presentazione',
		'socials',
		'hero',
		'design_unipv_ginevra',
		'_design_unipv_ginevra',
		'design_unipv_ginevra_options',
		'_design_unipv_ginevra_options',
		'design_unipv_ginevra_header_options',
		'_design_unipv_ginevra_header_options',
		'header_options',
		'_header_options',
		'unipv_header_options',
		'_unipv_header_options',
	] );
}

function desiitse_unipv_find_array_value( $data, array $keys ): string {
	if ( ! is_array( $data ) ) {
		return '';
	}

	foreach ( $keys as $key ) {
		if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
			return desiitse_clean_text( $data[ $key ] );
		}
	}

	foreach ( $data as $value ) {
		if ( is_array( $value ) ) {
			$found = desiitse_unipv_find_array_value( $value, $keys );
			if ( $found !== '' ) {
				return $found;
			}
		}
	}

	return '';
}

function desiitse_unipv_site_option( string $base_key ): string {
	$keys = array_map(
		fn( $prefix ) => $prefix . $base_key,
		desiitse_unipv_option_prefixes()
	);

	foreach ( $keys as $key ) {
		$value = desiitse_clean_text( get_option( $key, '' ) );
		if ( $value !== '' ) {
			return $value;
		}

		$value = desiitse_clean_text( get_theme_mod( $key, '' ) );
		if ( $value !== '' ) {
			return $value;
		}
	}

	if ( function_exists( 'cmb2_get_option' ) ) {
		foreach ( desiitse_unipv_option_containers() as $container ) {
			foreach ( $keys as $key ) {
				$value = desiitse_clean_text( cmb2_get_option( $container, $key, '' ) );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}
	}

	foreach ( desiitse_unipv_option_containers() as $container ) {
		$value = desiitse_unipv_find_array_value( maybe_unserialize( get_option( $container, [] ) ), $keys );
		if ( $value !== '' ) {
			return $value;
		}
	}

	return desiitse_unipv_site_option_from_db( $keys );
}

function desiitse_unipv_site_url_option( string $base_key ): string {
	return esc_url_raw( desiitse_unipv_site_option( $base_key ) );
}

function desiitse_unipv_site_option_from_db( array $keys ): string {
	global $wpdb;

	if ( ! ( $wpdb instanceof wpdb ) ) {
		return '';
	}

	foreach ( $keys as $key ) {
		$like = '%' . $wpdb->esc_like( $key ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s LIMIT 20",
				$like,
				$like
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			if ( $row['option_name'] === $key ) {
				$value = desiitse_clean_text( maybe_unserialize( $row['option_value'] ) );
				if ( $value !== '' ) {
					return $value;
				}
			}

			$value = desiitse_unipv_find_array_value( maybe_unserialize( $row['option_value'] ), $keys );
			if ( $value !== '' ) {
				return $value;
			}
		}
	}

	return '';
}

function desiitse_unipv_site_config(): array {
	$type       = desiitse_unipv_site_option( 'tipologia_sito' );
	$department = desiitse_unipv_site_option( 'dipartimento' );
	$structure  = desiitse_unipv_site_option( 'nome_struttura_sito' );
	$site_name  = desiitse_unipv_site_option( 'nome_sito' );
	$tagline    = desiitse_unipv_site_option( 'tagline_sito' );
	$desc       = desiitse_unipv_site_option( 'descrizione_presentazione' );

	return apply_filters( 'desiitse_unipv_site_config', [
		'type'        => $type,
		'department'  => $department,
		'structure'   => $structure,
		'site_name'   => $site_name !== '' ? $site_name : desiitse_clean_text( get_bloginfo( 'name' ) ),
		'tagline'     => $tagline !== '' ? $tagline : desiitse_clean_text( get_bloginfo( 'description' ) ),
		'description' => $desc,
		'address'     => desiitse_unipv_site_option( 'indirizzo_sito' ),
		'email'       => desiitse_unipv_site_option( 'email_sito' ),
		'telephone'   => desiitse_unipv_site_option( 'telefono_sito' ),
		'logo'        => desiitse_unipv_site_url_option( 'logo_sito' ),
		'socials'     => array_values( array_filter( [
			desiitse_unipv_site_url_option( 'facebook' ),
			desiitse_unipv_site_url_option( 'youtube' ),
			desiitse_unipv_site_url_option( 'instagram' ),
			desiitse_unipv_site_url_option( 'twitter' ),
			desiitse_unipv_site_url_option( 'linkedin' ),
		] ) ),
	] );
}

function desiitse_unipv_department_label( string $department ): string {
	$options = desiitse_unipv_department_options();
	return desiitse_clean_text( $options[ $department ] ?? str_replace( '_', ' ', $department ) );
}

function desiitse_unipv_context_ref(): array {
	return [ '@id' => desiitse_unipv_context_id() ];
}

function desiitse_unipv_context_id(): string {
	return home_url( '/#site' );
}

function desiitse_unipv_department_id( string $department ): string {
	return home_url( '/#' . sanitize_title( $department ) );
}

function desiitse_unipv_structure_id( string $structure ): string {
	return home_url( '/#' . sanitize_title( $structure ) );
}

function desiitse_unipv_site_hierarchy_nodes( array $content_refs = [] ): array {
	$config       = desiitse_unipv_site_config();
	$type         = $config['type'];
	$department   = $config['department'];
	$site_name    = $config['site_name'] !== '' ? $config['site_name'] : 'Sito UNIPV';
	$nodes        = [];
	$content_refs = array_values( array_filter( $content_refs ) );
	$site_ref     = desiitse_unipv_context_ref();

	if ( in_array( $type, [ 'evento', 'laboratorio_di_ricerca', 'progetto_di_ricerca' ], true ) && $department !== '' ) {
		$department_label = desiitse_unipv_department_label( $department );
		$department_node  = [
			'@type'                 => 'cov:Organization',
			'@id'                   => desiitse_unipv_department_id( $department ),
			'dct:title'             => $department_label,
			'cov:legalName'         => $department_label,
			'dct:hasPart'           => $site_ref,
		];
		$nodes[] = $department_node;
	}

	$site_node = [
		'@type'     => 'foaf:Document',
		'@id'       => desiitse_unipv_context_id(),
		'dct:title' => $site_name,
		'sm:URL'    => home_url( '/' ),
	];

	if ( ! empty( $config['tagline'] ) ) {
		$site_node['dct:alternative'] = $config['tagline'];
	}

	desiitse_add_descriptions( $site_node, [ $config['description'] ?? '' ] );

	if ( ! empty( $config['address'] ) ) {
		$site_node['clv:hasAddress'] = [
			'@id'             => desiitse_unipv_context_id() . '-address',
			'@type'           => 'clv:Address',
			'clv:fullAddress' => $config['address'],
		];
	}

	if ( ! empty( $config['email'] ) ) {
		$site_node['sm:email'] = $config['email'];
	}

	if ( ! empty( $config['telephone'] ) ) {
		$site_node['sm:telephone'] = $config['telephone'];
	}

	if ( ! empty( $config['logo'] ) ) {
		$site_node['sm:hasImage'] = [ '@id' => $config['logo'] ];
	}

	if ( ! empty( $config['socials'] ) ) {
		$social_refs = array_map(
			fn( $url ) => [ '@id' => $url ],
			$config['socials']
		);
		$site_node['owl:sameAs'] = count( $social_refs ) === 1 ? $social_refs[0] : $social_refs;
	}

	if ( ! empty( $content_refs ) ) {
		$site_node['dct:hasPart'] = count( $content_refs ) === 1 ? $content_refs[0] : $content_refs;
	}

	$nodes[] = $site_node;

	return $nodes;
}

function desiitse_unipv_root_node( array $hierarchy_nodes ): array {
	$node = desiitse_university_data();

	if ( empty( $hierarchy_nodes[0]['@id'] ) ) {
		return $node;
	}

	$child = [ '@id' => $hierarchy_nodes[0]['@id'] ];
	if ( ( $hierarchy_nodes[0]['@type'] ?? '' ) === 'cov:Organization' ) {
		$node['cov:hasOrganization'] = $child;
	} else {
		$node['dct:hasPart'] = $child;
	}

	return $node;
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
	$ids = array_filter( array_map( 'intval', $ids ), 'desiitse_is_publicly_reachable_post' );
	return array_values( array_map( fn( $id ) => [ '@id' => desiitse_node_id( $id ) ], $ids ) );
}

function desiitse_build_unipv_nodes(): array {
	$content_nodes = desiitse_unique_nodes( array_merge(
		desiitse_build_unipv_persona_nodes(),
		desiitse_build_unipv_struttura_nodes(),
		desiitse_build_unipv_evento_nodes(),
		desiitse_build_unipv_progetto_nodes(),
		desiitse_build_unipv_indirizzo_nodes(),
		desiitse_build_unipv_pubblicazione_nodes()
	) );
	$content_refs  = array_map(
		fn( $node ) => is_array( $node ) && ! empty( $node['@id'] ) ? [ '@id' => $node['@id'] ] : null,
		$content_nodes
	);
	$hierarchy_nodes = desiitse_unipv_site_hierarchy_nodes( $content_refs );

	return desiitse_unique_nodes( array_merge(
		[
			desiitse_unipv_root_node( $hierarchy_nodes ),
		],
		$hierarchy_nodes,
		$content_nodes
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
			'@type'         => 'cov:Organization',
			'@id'           => desiitse_node_id( $post ),
			'dct:title'     => $title,
			'cov:legalName' => $title,
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
			'@type'     => 'her:PublicResearchProject',
			'@id'       => desiitse_node_id( $post ),
			'dct:title' => $title,
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
			desiitse_add_ref_property( $node, 'dct:contributor', desiitse_unipv_ref_list( $leader_ids ) );
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
			'@type'     => 'foaf:Document',
			'@id'       => desiitse_node_id( $post ),
			'dct:title' => $title,
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
			if ( ! empty( $author_refs ) && isset( $node['dct:creator'] ) ) {
				$node['dct:creator'] = array_merge( [ $node['dct:creator'] ], $author_refs );
			} elseif ( ! empty( $author_refs ) ) {
				$node['dct:creator'] = $author_refs;
			}
			$nodes = array_merge( $nodes, desiitse_minimal_related_nodes( $internal_authors ) );
		}

		$nodes[] = $node;
	}

	return desiitse_unique_nodes( $nodes );
}

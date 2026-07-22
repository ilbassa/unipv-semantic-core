<?php
/**
 * Pagina di amministrazione del plugin.
 *
 * @package Unipv_Semantic_Core
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'UNIPV Semantic Core', 'unipv-semantic-core' ),
		__( 'Semantic Core', 'unipv-semantic-core' ),
		'manage_options',
		'unipv-semantic-core',
		'desiitse_admin_page_render'
	);
} );

add_action( 'network_admin_menu', function () {
	if ( ! is_multisite() ) {
		return;
	}

	add_menu_page(
		__( 'UNIPV Semantic Core', 'unipv-semantic-core' ),
		__( 'Semantic Core', 'unipv-semantic-core' ),
		'manage_network_options',
		'unipv-semantic-core-network',
		'desiitse_network_admin_page_render',
		'dashicons-networking',
		80
	);
} );

add_filter( 'plugin_action_links_' . plugin_basename( DESIITSE_PLUGIN_FILE ), function ( $links ) {
	$url  = admin_url( 'options-general.php?page=unipv-semantic-core' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Informazioni', 'unipv-semantic-core' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
} );

add_action( 'admin_init', function () {
	if ( ! isset( $_POST['desiitse_action'] ) ) {
		return;
	}

	check_admin_referer( 'desiitse_cache_action' );
	$action = sanitize_key( $_POST['desiitse_action'] );

	if ( $action === 'save_network_rate_limit' && is_multisite() && current_user_can( 'manage_network_options' ) ) {
		foreach ( [ 'max_requests', 'window_seconds', 'global_rps' ] as $key ) {
			$field = 'desiitse_rl_' . $key;
			$value = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0;
			if ( $value > 0 ) {
				update_site_option( desiitse_rl_option_name( $key ), $value );
			} else {
				delete_site_option( desiitse_rl_option_name( $key ) );
			}
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core-network', 'desiitse_msg' => 'rl_saved' ], network_admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( $action === 'save_network_sites' && is_multisite() && current_user_can( 'manage_network_options' ) ) {
		$requested_ids = isset( $_POST['desiitse_network_site_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['desiitse_network_site_ids'] ) )
			: [];
		$allowed_ids = array_map(
			fn( WP_Site $site ) => (int) $site->blog_id,
			desiitse_unipv_network_candidate_sites()
		);
		$published_ids = desiitse_unipv_normalize_site_ids( array_intersect( $requested_ids, $allowed_ids ) );
		$excluded_ids  = desiitse_unipv_normalize_site_ids( array_diff( $allowed_ids, $published_ids ) );

		update_site_option( DESIITSE_UNIPV_NETWORK_EXCLUDED_SITES_OPTION, $excluded_ids );
		delete_site_option( DESIITSE_UNIPV_NETWORK_SITES_OPTION );
		desiitse_unipv_invalidate_network_graph_index();

		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core-network', 'desiitse_msg' => 'sites_saved' ], network_admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( $action === 'flush_all' ) {
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core', 'desiitse_msg' => 'flushed' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'rebuild_all' ) {
		desiitse_schedule_rebuild_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core', 'desiitse_msg' => 'scheduled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'toggle_api' ) {
		update_option( DESIITSE_ENABLED_OPTION, isset( $_POST['desiitse_api_enabled'] ) ? '1' : '0' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core', 'desiitse_msg' => 'api_toggled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'save_ipa_code' ) {
		$new_code = isset( $_POST['desiitse_ipa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['desiitse_ipa_code'] ) ) : '';
		desiitse_save_ipa_code( $new_code );
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'unipv-semantic-core', 'desiitse_msg' => 'ipa_saved' ], admin_url( 'options-general.php' ) ) );
		exit;
	}
} );

function desiitse_admin_page_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$base      = rest_url();
	$msg       = isset( $_GET['desiitse_msg'] ) ? sanitize_key( wp_unslash( $_GET['desiitse_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cron_ts   = wp_next_scheduled( DESIITSE_CRON_HOOK );
	$dirty     = (array) get_option( DESIITSE_DIRTY_OPTION, [] );
	$ipa_code  = desiitse_get_ipa_code() ?? '';
	$enabled   = desiitse_api_is_enabled();
	$maint     = desiitse_wp_is_maintenance();

	$cache_status = [];
	foreach ( desiitse_active_slugs() as $slug ) {
		$cache_status[ $slug ] = ( desiitse_transient_get( $slug ) !== null || desiitse_option_get( $slug ) !== null );
	}

	$api_rows = [
		[ 'GET', $base . 'unipv/v1/graph', 'Grafo completo UNIPV', 'COV, CPV, CPEV, HER, dct' ],
		[ 'GET', $base . 'unipv/v1/graph/persone', 'Persone', 'cpv:Person' ],
		[ 'GET', $base . 'unipv/v1/graph/strutture', 'Strutture', 'cov:Organization' ],
		[ 'GET', $base . 'unipv/v1/graph/eventi', 'Eventi', 'cpev:PublicEvent' ],
		[ 'GET', $base . 'unipv/v1/graph/progetti', 'Progetti di ricerca', 'her:PublicResearchProject' ],
		[ 'GET', $base . 'unipv/v1/graph/indirizzi-di-ricerca', 'Indirizzi di ricerca', 'skos:Concept + dct' ],
		[ 'GET', $base . 'unipv/v1/graph/pubblicazioni', 'Pubblicazioni', 'foaf:Document + dct' ],
	];
	?>
	<div class="wrap">
		<h1>UNIPV Semantic Core</h1>
		<p>Esporta i custom post type UNIPV in JSON-LD tramite endpoint pubblici <code>/wp-json/unipv/v1/*</code>, con cache precomputata e rebuild via WP-Cron.</p>

		<?php if ( $msg === 'flushed' ) : ?>
			<div class="notice notice-success"><p>Cache svuotata.</p></div>
		<?php elseif ( $msg === 'scheduled' ) : ?>
			<div class="notice notice-info"><p>Rebuild completo schedulato.</p></div>
		<?php elseif ( $msg === 'api_toggled' ) : ?>
			<div class="notice notice-info"><p>Disponibilita API aggiornata.</p></div>
		<?php elseif ( $msg === 'ipa_saved' ) : ?>
			<div class="notice notice-success"><p>Codice IPA salvato e cache svuotata.</p></div>
		<?php endif; ?>

		<h2>Disponibilita API</h2>
		<p>
			Stato:
			<strong>
				<?php
				if ( $maint ) {
					echo esc_html__( 'sito in manutenzione, API bloccate con HTTP 503', 'unipv-semantic-core' );
				} elseif ( $enabled ) {
					echo esc_html__( 'attive', 'unipv-semantic-core' );
				} else {
					echo esc_html__( 'disabilitate da opzione admin', 'unipv-semantic-core' );
				}
				?>
			</strong>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
			<label>
				<input type="checkbox" name="desiitse_api_enabled" value="1" <?php checked( $enabled ); ?> onchange="this.form.submit()">
				API abilitate
			</label>
			<input type="hidden" name="desiitse_action" value="toggle_api">
		</form>

		<h2>Identificativo organizzazione</h2>
		<p>Il codice IPA, se presente, viene usato per l'<code>@id</code> del nodo <code>cov:PublicOrganization</code> dell'ateneo. In assenza di codice viene usato l'URL del sito.</p>
		<form method="post">
			<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
			<input type="text" name="desiitse_ipa_code" value="<?php echo esc_attr( $ipa_code ); ?>" class="regular-text" placeholder="Codice IPA">
			<button type="submit" name="desiitse_action" value="save_ipa_code" class="button button-primary">Salva</button>
		</form>

		<h2>Endpoint REST</h2>
		<table class="widefat striped">
			<thead><tr><th>Metodo</th><th>Endpoint</th><th>Descrizione</th><th>Ontologie</th></tr></thead>
			<tbody>
				<?php foreach ( $api_rows as [ $method, $url, $desc, $onto ] ) : ?>
					<tr>
						<td><code><?php echo esc_html( $method ); ?></code></td>
						<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( str_replace( $base, '', $url ) ); ?></a></td>
						<td><?php echo esc_html( $desc ); ?></td>
						<td><?php echo esc_html( $onto ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Cache</h2>
		<?php if ( $cron_ts ) : ?>
			<div class="notice notice-info inline"><p>Rebuild schedulato tra circa <?php echo esc_html( max( 0, (int) ( $cron_ts - time() ) ) ); ?> secondi. Slug dirty: <?php echo esc_html( implode( ', ', array_keys( $dirty ) ) ); ?></p></div>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr><th>Slug</th><th>Stato</th></tr></thead>
			<tbody>
				<?php foreach ( $cache_status as $slug => $hit ) : ?>
					<tr><td><code><?php echo esc_html( $slug ); ?></code></td><td><?php echo esc_html( $hit ? 'In cache' : 'Non pronta' ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" style="margin-top:1em">
			<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
			<button type="submit" name="desiitse_action" value="rebuild_all" class="button button-primary">Schedula rebuild completo</button>
			<button type="submit" name="desiitse_action" value="flush_all" class="button" onclick="return confirm('Svuotare tutta la cache semantica?');">Svuota cache</button>
		</form>

		<?php desiitse_admin_rate_limit_section(); ?>
	</div>
	<?php
}

function desiitse_admin_rate_limit_section(): void {
	$namespaces = implode( ', ', array_map(
		fn( $namespace ) => '/' . trim( $namespace, '/' ) . '/*',
		desiitse_rl_protected_namespaces()
	) );
	$network_values = [];
	if ( is_multisite() ) {
		foreach ( [ 'max_requests', 'window_seconds', 'global_rps' ] as $key ) {
			$network_values[ $key ] = get_site_option( desiitse_rl_option_name( $key ), '' );
		}
	}
	?>
	<h2>Protezione contro richieste massive</h2>
	<p>
		Il plugin include un sistema di rate limiting integrato sulle route REST protette
		<code><?php echo esc_html( $namespaces ); ?></code>. In caso di superamento delle soglie risponde con
		<code>HTTP 429 Too Many Requests</code> e header informativi per i client.
	</p>
	<?php if ( is_network_admin() && is_multisite() && current_user_can( 'manage_network_options' ) ) : ?>
		<form method="post" style="margin: 1em 0;">
			<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
			<input type="hidden" name="desiitse_action" value="save_network_rate_limit">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="desiitse_rl_max_requests">Richieste per IP</label></th>
						<td>
							<input type="number" min="1" step="1" id="desiitse_rl_max_requests" name="desiitse_rl_max_requests" value="<?php echo esc_attr( (string) $network_values['max_requests'] ); ?>" placeholder="<?php echo esc_attr( (string) DESIITSE_RL_MAX_REQUESTS ); ?>">
							<p class="description">Se valorizzato a livello Network, sovrascrive il valore del singolo sito.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="desiitse_rl_window_seconds">Finestra in secondi</label></th>
						<td>
							<input type="number" min="1" step="1" id="desiitse_rl_window_seconds" name="desiitse_rl_window_seconds" value="<?php echo esc_attr( (string) $network_values['window_seconds'] ); ?>" placeholder="<?php echo esc_attr( (string) DESIITSE_RL_WINDOW_SECONDS ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="desiitse_rl_global_rps">Throttle globale req/s</label></th>
						<td>
							<input type="number" min="1" step="1" id="desiitse_rl_global_rps" name="desiitse_rl_global_rps" value="<?php echo esc_attr( (string) $network_values['global_rps'] ); ?>" placeholder="<?php echo esc_attr( (string) DESIITSE_RL_GLOBAL_RPS ); ?>">
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<button type="submit" class="button button-primary">Salva configurazione Network</button>
			</p>
		</form>
	<?php elseif ( is_multisite() ) : ?>
		<p>
			In multisite le opzioni Network, quando valorizzate, hanno precedenza sui valori del singolo sito.
		</p>
	<?php endif; ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th>Meccanismo</th>
				<th>Valore attuale</th>
				<th>Filtro</th>
				<th>Descrizione</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>Rate limit per IP</td>
				<td><code><?php echo esc_html( (string) DESIITSE_RL_MAX_REQUESTS ); ?> richieste / <?php echo esc_html( (string) DESIITSE_RL_WINDOW_SECONDS ); ?>s</code></td>
				<td><code>desiitse_rl_max_requests</code><br><code>desiitse_rl_window_seconds</code></td>
				<td>Ogni IP puo chiamare gli endpoint al massimo N volte nella finestra configurata.</td>
			</tr>
			<tr>
				<td>Global throttle</td>
				<td><code><?php echo esc_html( (string) DESIITSE_RL_GLOBAL_RPS ); ?> richieste/s</code></td>
				<td><code>desiitse_rl_global_rps</code></td>
				<td>Limite globale al numero di richieste al secondo verso le route del plugin.</td>
			</tr>
			<tr>
				<td>Whitelist IP</td>
				<td><code><?php echo esc_html( implode( ', ', desiitse_rl_whitelist() ) ); ?></code></td>
				<td><code>desiitse_rl_whitelist_ips</code></td>
				<td>IP esenti dai controlli, ad esempio monitoraggio interno o crawler autorizzati.</td>
			</tr>
			<tr>
				<td>Proxy e CDN</td>
				<td>Disabilitato di default</td>
				<td><code>desiitse_rl_trust_proxy_headers</code></td>
				<td>Da abilitare solo dietro proxy fidati per leggere l'IP reale dagli header.</td>
			</tr>
			<tr>
				<td>Header risposta</td>
				<td><code>X-RateLimit-*</code></td>
				<td>-</td>
				<td>Le risposte includono limite, richieste residue e reset; sui 429 anche <code>Retry-After</code>.</td>
			</tr>
		</tbody>
	</table>
	<p>
		Esempio: <code>add_filter( 'desiitse_rl_max_requests', fn() => 30 );</code>
	</p>
	<?php
}

function desiitse_network_admin_page_render(): void {
	if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	$index_url       = rest_url( DESIITSE_UNIPV_REST_NAMESPACE . '/network/graphs' );
	$candidate_rows  = [];
	$candidate_sites = desiitse_unipv_network_candidate_sites();
	$excluded_ids   = desiitse_unipv_network_excluded_site_ids();
	$msg            = isset( $_GET['desiitse_msg'] ) ? sanitize_key( wp_unslash( $_GET['desiitse_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	foreach ( $candidate_sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		try {
			$type = desiitse_unipv_site_option( 'tipologia_sito' );
			$candidate_rows[] = [
				'blog_id'       => (int) $site->blog_id,
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
	?>
	<div class="wrap">
		<h1>UNIPV Semantic Core - Network</h1>
		<p>Indice multisite dei grafi JSON-LD UNIPV esposti dai siti pubblici del network.</p>

		<?php if ( $msg === 'rl_saved' ) : ?>
			<div class="notice notice-success"><p>Configurazione Network del rate limiting salvata.</p></div>
		<?php elseif ( $msg === 'sites_saved' ) : ?>
			<div class="notice notice-success"><p>Siti pubblicati nell'indice semantico aggiornati e cache invalidata.</p></div>
		<?php endif; ?>

		<h2>Endpoint network</h2>
		<p>
			<code>GET</code>
			<a href="<?php echo esc_url( $index_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $index_url ); ?></a>
		</p>

		<h2>Grafi dei siti</h2>
		<p>
			Le esclusioni sono salvate a livello Network e non vengono copiate clonando un sito.
			I nuovi siti pubblici sono inclusi automaticamente; deselezionali qui quando non devono comparire nell'indice.
		</p>
		<form method="post" style="margin: 1em 0 2em;">
			<?php wp_nonce_field( 'desiitse_cache_action' ); ?>
			<input type="hidden" name="desiitse_action" value="save_network_sites">
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width: 7em;">Pubblica</th>
						<th>Sito</th>
						<th>Tipologia</th>
						<th>Home</th>
						<th>Endpoint grafo</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $candidate_rows ) ) : ?>
						<tr><td colspan="5">Nessun sito pubblico trovato nel network.</td></tr>
					<?php else : ?>
						<?php foreach ( $candidate_rows as $row ) : ?>
							<tr>
								<td>
									<input type="checkbox" name="desiitse_network_site_ids[]" value="<?php echo esc_attr( (string) $row['blog_id'] ); ?>" <?php checked( ! in_array( $row['blog_id'], $excluded_ids, true ) ); ?>>
								</td>
								<td><?php echo esc_html( $row['name'] ); ?> <code>#<?php echo esc_html( (string) $row['blog_id'] ); ?></code></td>
								<td><?php echo esc_html( $row['tipologiaName'] ?: $row['tipologia'] ); ?></td>
								<td><a href="<?php echo esc_url( $row['home_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['home_url'] ); ?></a></td>
								<td><a href="<?php echo esc_url( $row['rest_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['rest_url'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<p><button type="submit" class="button button-primary">Salva siti pubblicati</button></p>
		</form>

		<?php desiitse_admin_rate_limit_section(); ?>
	</div>
	<?php
}

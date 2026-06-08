<?php
/**
 * Pagina di amministrazione del plugin.
 *
 * @package Semantic_Unipv
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Semantic University UNIPV', 'semantic-unipv' ),
		__( 'Semantic UNIPV', 'semantic-unipv' ),
		'manage_options',
		'semantic-unipv',
		'desiitse_admin_page_render'
	);
} );

add_filter( 'plugin_action_links_' . plugin_basename( DESIITSE_PLUGIN_FILE ), function ( $links ) {
	$url  = admin_url( 'options-general.php?page=semantic-unipv' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Informazioni', 'semantic-unipv' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
} );

add_action( 'admin_init', function () {
	if ( ! isset( $_POST['desiitse_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	check_admin_referer( 'desiitse_cache_action' );
	$action = sanitize_key( $_POST['desiitse_action'] );

	if ( $action === 'flush_all' ) {
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-unipv', 'desiitse_msg' => 'flushed' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'rebuild_all' ) {
		desiitse_schedule_rebuild_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-unipv', 'desiitse_msg' => 'scheduled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'toggle_api' ) {
		update_option( DESIITSE_ENABLED_OPTION, isset( $_POST['desiitse_api_enabled'] ) ? '1' : '0' );
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-unipv', 'desiitse_msg' => 'api_toggled' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	if ( $action === 'save_ipa_code' ) {
		$new_code = isset( $_POST['desiitse_ipa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['desiitse_ipa_code'] ) ) : '';
		desiitse_save_ipa_code( $new_code );
		desiitse_invalidate_all();
		wp_safe_redirect( add_query_arg( [ 'page' => 'semantic-unipv', 'desiitse_msg' => 'ipa_saved' ], admin_url( 'options-general.php' ) ) );
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
		<h1>Semantic University UNIPV</h1>
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
					echo esc_html__( 'sito in manutenzione, API bloccate con HTTP 503', 'semantic-unipv' );
				} elseif ( $enabled ) {
					echo esc_html__( 'attive', 'semantic-unipv' );
				} else {
					echo esc_html__( 'disabilitate da opzione admin', 'semantic-unipv' );
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
	</div>
	<?php
}

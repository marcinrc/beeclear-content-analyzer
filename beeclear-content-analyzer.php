<?php
/**
 * Plugin Name: BeeClear Content Analyzer
 * Plugin URI: https://beeclear.pl
 * Description: Advanced content topicality analysis for WordPress posts and pages — server-side relevance and browser-side semantic vectors, with cached reports per page/topic.
 * Version: 1.3.0
 * Author: <a href="https://beeclear.pl">BeeClear</a>
 * License: GPL v2 or later
 * Text Domain: beeclear-content-analyzer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CA_VERSION', '1.3.0' );

/**
 * ============================================================
 * i18n
 * ============================================================
 */
add_action( 'plugins_loaded', function() {
	load_plugin_textdomain( 'beeclear-content-analyzer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * ============================================================
 * DB: Cache table (upgrade-safe)
 * ============================================================
 */
function ca_db_create_or_update_table() {
	global $wpdb;

	$table = $wpdb->prefix . 'ca_analysis_cache';

	// dbDelta can add columns if they are declared here.
	$sql = "CREATE TABLE $table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id bigint(20) UNSIGNED NOT NULL,
		analysis_key varchar(64) NOT NULL,
		analysis_type varchar(24) NOT NULL,
		mode varchar(16) NOT NULL,
		focus_phrase varchar(500) DEFAULT '',
		analysis_data longtext NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY post_id (post_id),
		KEY analysis_key (analysis_key),
		KEY analysis_type (analysis_type),
		KEY mode (mode)
	) {$wpdb->get_charset_collate()};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

register_activation_hook( __FILE__, function() {
	ca_db_create_or_update_table();

	// Init default settings.
	if ( ! get_option( 'ca_settings', false ) ) {
		ca_update_settings( ca_get_settings_defaults() );
	}
} );

// Upgrade on load (safe; dbDelta no-ops when up to date).
add_action( 'admin_init', function() {
	if ( current_user_can( 'manage_options' ) ) {
		ca_db_create_or_update_table();
	}
} );

/**
 * ============================================================
 * SETTINGS (Global)
 * ============================================================
 */
function ca_get_settings_defaults() {
	return array(
		'mode' => 'server', // server | browser
		'delete_data_on_deactivate' => 0,
	);
}
function ca_get_settings() {
	$defaults = ca_get_settings_defaults();
	$opts = get_option( 'ca_settings', array() );
	if ( ! is_array( $opts ) ) { $opts = array(); }
	return wp_parse_args( $opts, $defaults );
}
function ca_update_settings( $new_opts ) {
	$defaults = ca_get_settings_defaults();
	$clean = array();
	$clean['mode'] = ( isset( $new_opts['mode'] ) && in_array( $new_opts['mode'], array( 'server', 'browser' ), true ) ) ? $new_opts['mode'] : $defaults['mode'];
	$clean['delete_data_on_deactivate'] = empty( $new_opts['delete_data_on_deactivate'] ) ? 0 : 1;
	update_option( 'ca_settings', $clean, false );
	return $clean;
}

/**
 * ============================================================
 * CLEANUP
 * ============================================================
 */
function ca_clear_all_plugin_data() {
	global $wpdb;

	delete_option( 'ca_settings' );

	// Remove focus phrase post meta.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		'_ca_focus_phrase'
	) );

	// Drop cache table.
	$table = $wpdb->prefix . 'ca_analysis_cache';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

register_deactivation_hook( __FILE__, function() {
	$opts = ca_get_settings();
	if ( ! empty( $opts['delete_data_on_deactivate'] ) ) {
		ca_clear_all_plugin_data();
	}
} );

register_uninstall_hook( __FILE__, 'ca_clear_all_plugin_data' );

/**
 * ============================================================
 * META
 * ============================================================
 */
add_action( 'init', function() {
	register_post_meta( '', '_ca_focus_phrase', array(
		'show_in_rest'  => true,
		'single'        => true,
		'type'          => 'string',
		'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
	) );
} );

add_action( 'add_meta_boxes', function() {
	foreach ( array( 'post', 'page' ) as $pt ) {
		add_meta_box(
			'ca_focus_phrase',
			esc_html__( 'Content Analyzer — Focus topic', 'beeclear-content-analyzer' ),
			'ca_render_meta_box',
			$pt,
			'side'
		);
	}
} );

function ca_render_meta_box( $post ) {
	$ph = get_post_meta( $post->ID, '_ca_focus_phrase', true );
	wp_nonce_field( 'ca_save_meta', 'ca_meta_nonce' );

	echo '<p><label for="ca_focus_phrase">' . esc_html__( 'Focus topic:', 'beeclear-content-analyzer' ) . '</label>';
	echo '<input type="text" id="ca_focus_phrase" name="ca_focus_phrase" value="' . esc_attr( $ph ) . '" style="width:100%" placeholder="' . esc_attr__( 'e.g. content marketing', 'beeclear-content-analyzer' ) . '"></p>';

	echo '<p class="description">' . esc_html__( 'Used as the default topic for embedding/chunk analysis (you can override on the report page).', 'beeclear-content-analyzer' ) . '</p>';
	echo '<p class="description">' . esc_html__( 'If empty, the plugin will try to use the first H1 as the topic.', 'beeclear-content-analyzer' ) . '</p>';
}

add_action( 'save_post', function( $pid ) {
	if ( ! isset( $_POST['ca_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ca_meta_nonce'], 'ca_save_meta' ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $pid ) ) { return; }

	if ( isset( $_POST['ca_focus_phrase'] ) ) {
		update_post_meta( $pid, '_ca_focus_phrase', sanitize_text_field( $_POST['ca_focus_phrase'] ) );
	}
} );

/**
 * ============================================================
 * MENU
 * ============================================================
 * Menu name must be without "BeeClear": "Content Analyzer" already matches old UX.
 */
add_action( 'admin_menu', function() {

	add_menu_page(
		__( 'Content Analyzer', 'beeclear-content-analyzer' ),
		__( 'Content Analyzer', 'beeclear-content-analyzer' ),
		'edit_posts',
		'content-analyzer',
		'ca_render_list_page',
		'dashicons-chart-bar',
		30
	);

	// Global settings (first).
	add_submenu_page(
		'content-analyzer',
		__( 'Global settings', 'beeclear-content-analyzer' ),
		__( 'Global settings', 'beeclear-content-analyzer' ),
		'manage_options',
		'ca-global-settings',
		'ca_render_global_settings_page'
	);

	// List page (kept as existing slug).
	add_submenu_page(
		'content-analyzer',
		__( 'Analyzer', 'beeclear-content-analyzer' ),
		__( 'Analyzer', 'beeclear-content-analyzer' ),
		'edit_posts',
		'content-analyzer',
		'ca_render_list_page'
	);

	// Report page (hidden from menu; opened from list).
	add_submenu_page(
		null,
		__( 'Report', 'beeclear-content-analyzer' ),
		__( 'Report', 'beeclear-content-analyzer' ),
		'edit_posts',
		'content-analyzer-report',
		'ca_render_report_page'
	);

	// Import / Export.
	add_submenu_page(
		'content-analyzer',
		__( 'Import/Export', 'beeclear-content-analyzer' ),
		__( 'Import/Export', 'beeclear-content-analyzer' ),
		'manage_options',
		'ca-import-export',
		'ca_render_import_export_page'
	);
} );

// Settings link in plugins list (first)
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=ca-global-settings' ) ) . '">' . esc_html__( 'Settings', 'beeclear-content-analyzer' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );

/**
 * ============================================================
 * ADMIN ASSETS
 * ============================================================
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	$allowed = array(
		'toplevel_page_content-analyzer',
		'content-analyzer_page_ca-global-settings',
		'content-analyzer_page_ca-import-export',
		'admin_page_content-analyzer-report',
	);
	if ( ! in_array( $hook, $allowed, true ) ) { return; }

	wp_enqueue_script( 'jquery' );
	wp_enqueue_style( 'dashicons' );

	$opts = ca_get_settings();
	$data = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'ca_nonce' ),
		'mode'    => $opts['mode'],
	);
	wp_add_inline_script( 'jquery', 'var caData=' . wp_json_encode( $data ) . ';', 'before' );
} );

/**
 * ============================================================
 * TEXT HELPERS (Stopwords / TF / Cosine / Extractors)
 * ============================================================
 */
function ca_get_stop_words() {
	return array(
		'i','w','na','z','do','nie','się','to','jest','że','o','jak','ale','za','co','od','po','tak','jej','jego','te','ten','ta','tym','tego','tej','tych',
		'był','była','było','były','być','może','ich','go','mu','mi','ci','nam','was','im','ją','je','nas','ze','są','by','już','tylko','też','ma','czy',
		'więc','dla','gdy','przed','przez','przy','bez','pod','nad','między','ku','lub','albo','oraz','a','u','we','tu','tam','raz','no','ani','bo','pan','pani',
		'jako','sobie','który','która','które','których','którym','którą','czym','gdzie','kiedy','bardzo','będzie','można','mnie','mają','każdy','inne','innych',
		'jednak','jeszcze','teraz','tutaj','wtedy','zawsze','nigdy','często','czasem','potem','ponieważ','więcej','mniej','dużo','mało','każda','każde','tę','tą',

		'the','a','an','and','or','but','in','on','at','to','for','of','with','by','from','is','are','was','were','be','been','being','have','has','had','do','does',
		'did','will','would','shall','should','may','might','can','could','this','that','these','those','it','its','he','she','they','we','you','me','him','her','us','them',
		'my','your','his','our','their','not','no','so','if','then','than','too','very','just','about','up','out','all','also'
	);
}

function ca_build_tf_vector( $text ) {
	$text = mb_strtolower( (string) $text );
	$words = preg_split( '/[^\p{L}\p{N}\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

	$stop = ca_get_stop_words();
	$tf = array();
	$total = 0;

	foreach ( $words as $w ) {
		$w = trim( $w, '-' );
		if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
		if ( ! isset( $tf[ $w ] ) ) { $tf[ $w ] = 0; }
		$tf[ $w ]++;
		$total++;
	}
	if ( $total > 0 ) {
		foreach ( $tf as $t => $c ) { $tf[ $t ] = $c / $total; }
	}
	return $tf;
}

function ca_cosine_similarity( $va, $vb ) {
	$terms = array_unique( array_merge( array_keys( $va ), array_keys( $vb ) ) );
	$dot = 0.0; $ma = 0.0; $mb = 0.0;

	foreach ( $terms as $t ) {
		$a = $va[ $t ] ?? 0.0;
		$b = $vb[ $t ] ?? 0.0;
		$dot += $a * $b;
		$ma  += $a * $a;
		$mb  += $b * $b;
	}
	$ma = sqrt( $ma ); $mb = sqrt( $mb );
	return ( $ma == 0 || $mb == 0 ) ? 0.0 : round( $dot / ( $ma * $mb ), 4 );
}

function ca_extract_entities( $text ) {
	$text = mb_strtolower( (string) $text );
	$text = preg_replace( '/\b\d+\b/', '', $text );

	$words = preg_split( '/[^\p{L}\p{N}\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$stop = ca_get_stop_words();
	$freq = array();
	$valid = 0;

	foreach ( $words as $w ) {
		$w = trim( $w, '-' );
		if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
		if ( ! isset( $freq[ $w ] ) ) { $freq[ $w ] = 0; }
		$freq[ $w ]++;
		$valid++;
	}
	arsort( $freq );
	$ent = array();
	foreach ( $freq as $term => $count ) {
		$ent[] = array(
			'term'      => $term,
			'count'     => $count,
			'frequency' => $valid > 0 ? round( $count / $valid * 100, 2 ) : 0,
		);
	}
	return array_slice( $ent, 0, 150 );
}

function ca_extract_headings( $content ) {
	$h = array( 'h1'=>array(),'h2'=>array(),'h3'=>array(),'h4'=>array(),'h5'=>array(),'h6'=>array(),'total'=>0 );
	for ( $i = 1; $i <= 6; $i++ ) {
		preg_match_all( '/<h'.$i.'[^>]*>(.*?)<\/h'.$i.'>/si', (string) $content, $m );
		if ( ! empty( $m[1] ) ) {
			foreach ( $m[1] as $x ) { $h[ 'h'.$i ][] = wp_strip_all_tags( $x ); }
			$h['total'] += count( $m[1] );
		}
	}
	return $h;
}

function ca_get_first_h1_text( $content ) {
	preg_match( '/<h1[^>]*>(.*?)<\/h1>/si', (string) $content, $m );
	if ( ! empty( $m[1] ) ) {
		return trim( wp_strip_all_tags( $m[1] ) );
	}
	return '';
}

function ca_count_paragraphs( $content ) {
	$content = wpautop( (string) $content );
	preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $content, $m );
	$c = 0;
	if ( ! empty( $m[1] ) ) {
		foreach ( $m[1] as $p ) {
			if ( trim( wp_strip_all_tags( $p ) ) !== '' ) { $c++; }
		}
	}
	return $c;
}

function ca_extract_chunks( $content ) {
	$content = wpautop( (string) $content );
	preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $content, $m );

	$chunks = array();
	$idx = 0;
	if ( ! empty( $m[1] ) ) {
		foreach ( $m[1] as $p ) {
			$t = trim( wp_strip_all_tags( $p ) );
			$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
			if ( ! empty( $t ) && mb_strlen( $t ) > 15 ) {
				$w = preg_split( '/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY );
				$chunks[] = array(
					'index'      => $idx,
					'text'       => $t,
					'word_count' => is_array( $w ) ? count( $w ) : 0,
					'char_count' => mb_strlen( $t ),
				);
				$idx++;
			}
		}
	}
	return $chunks;
}

function ca_get_plain_text( $post ) {
	$t = wp_strip_all_tags( (string) ( $post->post_content ?? '' ) );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	return preg_replace( '/\s+/', ' ', trim( $t ) );
}

function ca_build_context_vector( $text_lower, $target, $all_words ) {
	$stop = ca_get_stop_words();
	$window = 5;
	$ctx = array();
	$total = 0;
	$pos = array();

	foreach ( $all_words as $i => $w ) {
		$w = trim( (string) $w, '-' );
		if ( $w === $target ) { $pos[] = $i; }
	}
	foreach ( $pos as $p ) {
		for ( $j = max( 0, $p - $window ); $j <= min( count( $all_words ) - 1, $p + $window ); $j++ ) {
			if ( $j === $p ) { continue; }
			$w = trim( (string) $all_words[ $j ], '-' );
			if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
			if ( ! isset( $ctx[ $w ] ) ) { $ctx[ $w ] = 0; }
			$ctx[ $w ]++;
			$total++;
		}
	}
	if ( $total > 0 ) {
		foreach ( $ctx as $t => $c ) { $ctx[ $t ] = $c / $total; }
	}
	return $ctx;
}

function ca_get_effective_topic_for_post( $post, $override_phrase = '' ) {
	$override_phrase = trim( (string) $override_phrase );
	if ( $override_phrase !== '' ) { return $override_phrase; }

	$meta = trim( (string) get_post_meta( $post->ID, '_ca_focus_phrase', true ) );
	if ( $meta !== '' ) { return $meta; }

	$h1 = trim( (string) ca_get_first_h1_text( $post->post_content ) );
	if ( $h1 !== '' ) { return $h1; }

	// Final fallback: title.
	return trim( (string) $post->post_title );
}

/**
 * ============================================================
 * CACHE HELPERS
 * ============================================================
 */
function ca_make_analysis_key( $post_id, $mode, $type, $phrase ) {
	return sha1( (string) $post_id . '|' . (string) $mode . '|' . (string) $type . '|' . trim( (string) $phrase ) );
}

function ca_cache_get( $post_id, $mode, $type, $phrase ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';
	$key = ca_make_analysis_key( $post_id, $mode, $type, $phrase );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d AND analysis_key = %s AND analysis_type = %s AND mode = %s ORDER BY id DESC LIMIT 1",
			$post_id, $key, $type, $mode
		),
		ARRAY_A
	);

	if ( ! $row ) { return null; }

	$data = json_decode( (string) $row['analysis_data'], true );
	if ( ! is_array( $data ) ) { $data = null; }

	return array(
		'created_at' => $row['created_at'],
		'focus_phrase' => (string) $row['focus_phrase'],
		'data' => $data,
	);
}

function ca_cache_set( $post_id, $mode, $type, $phrase, $analysis_data ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';
	$key = ca_make_analysis_key( $post_id, $mode, $type, $phrase );

	$wpdb->insert(
		$table,
		array(
			'post_id'       => (int) $post_id,
			'analysis_key'  => $key,
			'analysis_type' => (string) $type,
			'mode'          => (string) $mode,
			'focus_phrase'  => (string) $phrase,
			'analysis_data' => wp_json_encode( $analysis_data, JSON_UNESCAPED_UNICODE ),
			'created_at'    => current_time( 'mysql' ),
		),
		array( '%d','%s','%s','%s','%s','%s','%s' )
	);
}

function ca_cache_delete_for_post( $post_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", (int) $post_id ) );
}

/**
 * ============================================================
 * GLOBAL SETTINGS PAGE
 * ============================================================
 */
function ca_render_global_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	$opts = ca_get_settings();

	if ( isset( $_POST['ca_settings_submit'] ) ) {
		check_admin_referer( 'ca_save_settings', 'ca_settings_nonce' );

		$new_opts = array(
			'mode' => sanitize_text_field( $_POST['ca_mode'] ?? '' ),
			'delete_data_on_deactivate' => ! empty( $_POST['ca_delete_data_on_deactivate'] ) ? 1 : 0,
		);
		$opts = ca_update_settings( $new_opts );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'beeclear-content-analyzer' ) . '</p></div>';
	}

	if ( isset( $_POST['ca_clear_data_submit'] ) ) {
		check_admin_referer( 'ca_clear_data', 'ca_clear_data_nonce' );
		ca_clear_all_plugin_data();
		ca_update_settings( ca_get_settings_defaults() );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Plugin data cleared.', 'beeclear-content-analyzer' ) . '</p></div>';
		$opts = ca_get_settings();
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Global settings', 'beeclear-content-analyzer' ); ?></h1>

		<form method="post" style="max-width: 1100px;">
			<?php wp_nonce_field( 'ca_save_settings', 'ca_settings_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ca_mode"><?php echo esc_html__( 'Analysis mode', 'beeclear-content-analyzer' ); ?></label></th>
					<td>
						<select id="ca_mode" name="ca_mode">
							<option value="server" <?php selected( $opts['mode'], 'server' ); ?>><?php echo esc_html__( 'Server (PHP)', 'beeclear-content-analyzer' ); ?></option>
							<option value="browser" <?php selected( $opts['mode'], 'browser' ); ?>><?php echo esc_html__( 'Browser (client-side)', 'beeclear-content-analyzer' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Server mode runs analysis in PHP. Browser mode computes vectors in your browser (no external services).', 'beeclear-content-analyzer' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Data handling', 'beeclear-content-analyzer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ca_delete_data_on_deactivate" value="1" <?php checked( ! empty( $opts['delete_data_on_deactivate'] ) ); ?> />
							<?php echo esc_html__( 'Delete plugin data on deactivation', 'beeclear-content-analyzer' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'If enabled, plugin settings, focus phrase meta and cache table will be removed when you deactivate the plugin.', 'beeclear-content-analyzer' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save settings', 'beeclear-content-analyzer' ), 'primary', 'ca_settings_submit' ); ?>
		</form>

		<hr />

		<form method="post" style="max-width: 1100px;">
			<?php wp_nonce_field( 'ca_clear_data', 'ca_clear_data_nonce' ); ?>
			<h2><?php echo esc_html__( 'Clear data', 'beeclear-content-analyzer' ); ?></h2>
			<p><?php echo esc_html__( 'This will remove plugin settings, focus phrase meta, and the cache table. Use with caution.', 'beeclear-content-analyzer' ); ?></p>
			<?php submit_button( esc_html__( 'Clear plugin data', 'beeclear-content-analyzer' ), 'delete', 'ca_clear_data_submit' ); ?>
		</form>
	</div>
	<?php
}

/**
 * ============================================================
 * IMPORT / EXPORT PAGE
 * ============================================================
 */
function ca_render_import_export_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	// Export
	if ( isset( $_POST['ca_export_submit'] ) ) {
		check_admin_referer( 'ca_export_settings', 'ca_export_nonce' );
		$payload = array(
			'exported_at' => gmdate( 'c' ),
			'plugin'      => 'beeclear-content-analyzer',
			'version'     => CA_VERSION,
			'settings'    => ca_get_settings(),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=beeclear-content-analyzer-export.json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// Import
	if ( isset( $_POST['ca_import_submit'] ) ) {
		check_admin_referer( 'ca_import_settings', 'ca_import_nonce' );
		if ( empty( $_FILES['ca_import_file']['tmp_name'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please choose a JSON file to import.', 'beeclear-content-analyzer' ) . '</p></div>';
		} else {
			$raw = file_get_contents( $_FILES['ca_import_file']['tmp_name'] );
			$json = json_decode( (string) $raw, true );
			if ( ! is_array( $json ) || empty( $json['settings'] ) || ! is_array( $json['settings'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid import file.', 'beeclear-content-analyzer' ) . '</p></div>';
			} else {
				ca_update_settings( $json['settings'] );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import completed.', 'beeclear-content-analyzer' ) . '</p></div>';
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Import/Export', 'beeclear-content-analyzer' ); ?></h1>

		<div style="max-width: 1100px;">
			<h2><?php echo esc_html__( 'Export', 'beeclear-content-analyzer' ); ?></h2>
			<p><?php echo esc_html__( 'Download a JSON file with plugin configuration.', 'beeclear-content-analyzer' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'ca_export_settings', 'ca_export_nonce' ); ?>
				<?php submit_button( esc_html__( 'Export settings', 'beeclear-content-analyzer' ), 'secondary', 'ca_export_submit' ); ?>
			</form>

			<hr />

			<h2><?php echo esc_html__( 'Import', 'beeclear-content-analyzer' ); ?></h2>
			<p><?php echo esc_html__( 'Import plugin configuration from a JSON export file.', 'beeclear-content-analyzer' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'ca_import_settings', 'ca_import_nonce' ); ?>
				<input type="file" name="ca_import_file" accept="application/json" />
				<?php submit_button( esc_html__( 'Import settings', 'beeclear-content-analyzer' ), 'primary', 'ca_import_submit' ); ?>
			</form>
		</div>
	</div>
	<?php
}

/**
 * ============================================================
 * AJAX: LIST DATA (includes heading counts)
 * ============================================================
 */
add_action( 'wp_ajax_ca_get_posts_list', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$r = array();
	foreach ( $posts as $p ) {
		$text  = ca_get_plain_text( $p );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$hdg   = ca_extract_headings( $p->post_content );

		$cats = array();
		if ( $p->post_type === 'post' ) {
			$c = get_the_category( $p->ID );
			if ( $c ) { foreach ( $c as $cat ) { $cats[] = $cat->name; } }
		}

		$focus = trim( (string) get_post_meta( $p->ID, '_ca_focus_phrase', true ) );
		$h1    = ca_get_first_h1_text( $p->post_content );
		$default_topic = $focus !== '' ? $focus : ( $h1 !== '' ? $h1 : $p->post_title );

		$r[] = array(
			'post_id'         => $p->ID,
			'title'           => $p->post_title,
			'post_type'       => $p->post_type,
			'post_status'     => $p->post_status,
			'slug'            => $p->post_name,
			'url'             => get_permalink( $p->ID ),
			'edit_url'        => get_edit_post_link( $p->ID, 'raw' ),
			'report_url'      => admin_url( 'admin.php?page=content-analyzer-report&post_id=' . (int) $p->ID ),
			'date_published'  => $p->post_date,
			'date_modified'   => $p->post_modified,
			'categories'      => $cats,
			'char_count'      => mb_strlen( $text ),
			'word_count'      => is_array( $words ) ? count( $words ) : 0,
			'paragraph_count' => ca_count_paragraphs( $p->post_content ),
			'headings'        => array(
				'h1' => count( $hdg['h1'] ?? array() ),
				'h2' => count( $hdg['h2'] ?? array() ),
				'h3' => count( $hdg['h3'] ?? array() ),
				'h4' => count( $hdg['h4'] ?? array() ),
				'h5' => count( $hdg['h5'] ?? array() ),
				'h6' => count( $hdg['h6'] ?? array() ),
				'total' => (int) ( $hdg['total'] ?? 0 ),
			),
			'default_topic' => $default_topic,
			'has_focus_meta' => $focus !== '',
		);
	}

	wp_send_json_success( $r );
} );

/**
 * AJAX: categories
 */
add_action( 'wp_ajax_ca_get_categories', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }
	$cats = get_categories( array( 'hide_empty' => false ) );
	$l = array();
	foreach ( $cats as $c ) { $l[] = array( 'id' => $c->term_id, 'name' => $c->name ); }
	wp_send_json_success( $l );
} );

/**
 * AJAX: post detail (for report header)
 */
add_action( 'wp_ajax_ca_get_post_detail', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$pid = absint( $_POST['post_id'] ?? 0 );
	$post = get_post( $pid );
	if ( ! $post ) { wp_send_json_error( 'Not found' ); }

	$text  = ca_get_plain_text( $post );
	$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
	$sents = preg_split( '/[.!?]+(?=\s|$)/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$sc = count( array_filter( (array) $sents, function( $s ) { return mb_strlen( trim( (string) $s ) ) > 2; } ) );

	$cats = array();
	if ( $post->post_type === 'post' ) {
		$c = get_the_category( $pid );
		if ( $c ) { foreach ( $c as $cat ) { $cats[] = $cat->name; } }
	}

	$headings = ca_extract_headings( $post->post_content );
	$focus = trim( (string) get_post_meta( $pid, '_ca_focus_phrase', true ) );
	$h1 = ca_get_first_h1_text( $post->post_content );

	wp_send_json_success( array(
		'post_id'         => $pid,
		'title'           => $post->post_title,
		'post_type'       => $post->post_type,
		'url'             => get_permalink( $pid ),
		'edit_url'        => get_edit_post_link( $pid, 'raw' ),
		'date_published'  => $post->post_date,
		'date_modified'   => $post->post_modified,
		'categories'      => $cats,
		'char_count'      => mb_strlen( $text ),
		'char_count_no_spaces' => mb_strlen( preg_replace( '/\s/', '', $text ) ),
		'word_count'      => is_array( $words ) ? count( $words ) : 0,
		'sentence_count'  => $sc,
		'paragraph_count' => ca_count_paragraphs( $post->post_content ),
		'reading_time'    => max( 1, round( ( is_array( $words ) ? count( $words ) : 0 ) / 200 ) ),
		'headings'        => $headings,
		'chunks'          => ca_extract_chunks( $post->post_content ),
		'entities'        => ca_extract_entities( $text ),
		'focus_phrase_meta' => $focus,
		'h1'              => $h1,
		'default_topic'   => ca_get_effective_topic_for_post( $post, '' ),
	) );
} );

/**
 * ============================================================
 * AJAX: cache get / save / clear
 * ============================================================
 */
add_action( 'wp_ajax_ca_get_cached_analysis', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$pid    = absint( $_POST['post_id'] ?? 0 );
	$type   = sanitize_text_field( $_POST['analysis_type'] ?? '' ); // embedding | chunks
	$phrase = sanitize_text_field( $_POST['phrase'] ?? '' );
	$mode   = sanitize_text_field( $_POST['mode'] ?? '' );

	if ( ! $pid || ! in_array( $type, array( 'embedding', 'chunks' ), true ) ) {
		wp_send_json_error( 'Missing data' );
	}
	if ( ! in_array( $mode, array( 'server', 'browser' ), true ) ) {
		$mode = ca_get_settings()['mode'];
	}

	$cached = ca_cache_get( $pid, $mode, $type, $phrase );
	if ( ! $cached ) {
		wp_send_json_success( array( 'found' => false ) );
	}

	wp_send_json_success( array(
		'found'      => true,
		'created_at' => $cached['created_at'],
		'phrase'     => $cached['focus_phrase'],
		'data'       => $cached['data'],
	) );
} );

add_action( 'wp_ajax_ca_save_cached_analysis', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$pid    = absint( $_POST['post_id'] ?? 0 );
	$type   = sanitize_text_field( $_POST['analysis_type'] ?? '' ); // embedding | chunks
	$phrase = sanitize_text_field( $_POST['phrase'] ?? '' );
	$mode   = sanitize_text_field( $_POST['mode'] ?? '' );
	$data_raw = wp_unslash( $_POST['analysis_data'] ?? '' );

	if ( ! $pid || $phrase === '' || ! in_array( $type, array( 'embedding', 'chunks' ), true ) ) {
		wp_send_json_error( 'Missing data' );
	}
	if ( ! in_array( $mode, array( 'server', 'browser' ), true ) ) {
		$mode = ca_get_settings()['mode'];
	}

	$decoded = json_decode( (string) $data_raw, true );
	if ( ! is_array( $decoded ) ) {
		wp_send_json_error( 'Invalid data' );
	}

	// Minimal hardening: limit stored payload size.
	$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE );
	if ( $encoded && strlen( $encoded ) > 800000 ) { // ~800KB
		wp_send_json_error( 'Payload too large' );
	}

	ca_cache_set( $pid, $mode, $type, $phrase, $decoded );
	wp_send_json_success( array( 'saved' => true, 'created_at' => current_time( 'mysql' ) ) );
} );

add_action( 'wp_ajax_ca_clear_post_cache', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }
	$pid = absint( $_POST['post_id'] ?? 0 );
	if ( ! $pid ) { wp_send_json_error( 'Missing data' ); }
	ca_cache_delete_for_post( $pid );
	wp_send_json_success( array( 'cleared' => true ) );
} );

/**
 * ============================================================
 * AJAX: server analyses (returns result; caller can save to cache)
 * ============================================================
 */
add_action( 'wp_ajax_ca_run_embedding_server', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$pid = absint( $_POST['post_id'] ?? 0 );
	$phrase = sanitize_text_field( $_POST['phrase'] ?? '' );
	if ( ! $pid || $phrase === '' ) { wp_send_json_error( 'Missing data' ); }

	$post = get_post( $pid );
	if ( ! $post ) { wp_send_json_error( 'Not found' ); }

	$text = ca_get_plain_text( $post );

	$topic_vec = ca_build_tf_vector( $phrase );

	// Lightweight bigrams from the phrase.
	$phrase_lower = mb_strtolower( $phrase );
	$phrase_words = preg_split( '/[^\p{L}\p{N}\-]+/u', $phrase_lower, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $phrase_words ) && count( $phrase_words ) >= 2 ) {
		for ( $i = 0; $i < count( $phrase_words ) - 1; $i++ ) {
			$bg = trim( $phrase_words[ $i ], '-' ) . ' ' . trim( $phrase_words[ $i + 1 ], '-' );
			if ( mb_strlen( $bg ) >= 5 ) { $topic_vec[ $bg ] = ( $topic_vec[ $bg ] ?? 0 ) + 0.35; }
		}
	}
	$topic_terms = array_keys( $topic_vec );

	$text_lower = mb_strtolower( $text );
	$all_words  = preg_split( '/[^\p{L}\p{N}\-]+/u', $text_lower, -1, PREG_SPLIT_NO_EMPTY );
	$stop = ca_get_stop_words();

	// Frequency map (limit unique terms for performance)
	$wf = array();
	foreach ( (array) $all_words as $w ) {
		$w = trim( (string) $w, '-' );
		if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) { continue; }
		if ( ! isset( $wf[ $w ] ) ) { $wf[ $w ] = 0; }
		$wf[ $w ]++;
	}
	arsort( $wf );

	$max_terms = 600;
	$wf = array_slice( $wf, 0, $max_terms, true );

	$ws = array();
	foreach ( $wf as $word => $count ) {
		$dm = in_array( $word, $topic_terms, true ) ? 1.0 : 0.0;
		$cv = ca_build_context_vector( $text_lower, $word, (array) $all_words );
		$cs = ca_cosine_similarity( $topic_vec, $cv );

		$score = round( min( 1.0, ( $dm * 0.55 ) + ( $cs * 0.45 ) ), 4 );

		$ws[] = array(
			'word'            => $word,
			'count'           => $count,
			'direct_match'    => $dm > 0,
			'context_score'   => round( $cs * 100, 1 ),
			'relevance_score' => round( $score * 100, 1 ),
		);
	}

	usort( $ws, function( $a, $b ) { return $b['relevance_score'] <=> $a['relevance_score']; } );

	$tv = ca_build_tf_vector( $text );
	$os = ca_cosine_similarity( $topic_vec, $tv );

	$tw = count( $ws );
	$hi = count( array_filter( $ws, function( $w ) { return ( $w['relevance_score'] ?? 0 ) >= 40; } ) );
	$md = count( array_filter( $ws, function( $w ) { $r = ( $w['relevance_score'] ?? 0 ); return $r >= 15 && $r < 40; } ) );
	$lo = count( array_filter( $ws, function( $w ) { return ( $w['relevance_score'] ?? 0 ) < 15; } ) );
	$av = $tw > 0 ? round( array_sum( array_column( $ws, 'relevance_score' ) ) / $tw, 1 ) : 0;

	wp_send_json_success( array(
		'mode'               => 'server',
		'phrase'             => $phrase,
		'overall_similarity' => round( $os * 100, 1 ),
		'total_unique_words' => $tw,
		'high_relevance'     => $hi,
		'medium_relevance'   => $md,
		'low_relevance'      => $lo,
		'average_relevance'  => $av,
		'words'              => array_slice( $ws, 0, 200 ),
	) );
} );

add_action( 'wp_ajax_ca_run_chunks_server', function() {
	check_ajax_referer( 'ca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json_error( 'Unauthorized' ); }

	$pid = absint( $_POST['post_id'] ?? 0 );
	$phrase = sanitize_text_field( $_POST['phrase'] ?? '' );
	if ( ! $pid || $phrase === '' ) { wp_send_json_error( 'Missing data' ); }

	$post = get_post( $pid );
	if ( ! $post ) { wp_send_json_error( 'Not found' ); }

	$chunks = ca_extract_chunks( $post->post_content );
	$tv = ca_build_tf_vector( $phrase );

	$phrase_lower = mb_strtolower( $phrase );
	$phrase_words = preg_split( '/[^\p{L}\p{N}\-]+/u', $phrase_lower, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $phrase_words ) && count( $phrase_words ) >= 2 ) {
		for ( $i = 0; $i < count( $phrase_words ) - 1; $i++ ) {
			$bg = trim( $phrase_words[ $i ], '-' ) . ' ' . trim( $phrase_words[ $i + 1 ], '-' );
			if ( mb_strlen( $bg ) >= 5 ) { $tv[ $bg ] = ( $tv[ $bg ] ?? 0 ) + 0.35; }
		}
	}

	$results = array();
	foreach ( $chunks as $ch ) {
		$cv  = ca_build_tf_vector( $ch['text'] );
		$sim = ca_cosine_similarity( $tv, $cv );

		$cl = mb_strtolower( (string) $ch['text'] );
		$tf = array();
		foreach ( array_keys( $tv ) as $term ) {
			if ( strpos( $term, ' ' ) !== false ) { continue; } // highlight only single words
			$cnt = mb_substr_count( $cl, $term );
			if ( $cnt > 0 ) { $tf[] = array( 'term' => $term, 'count' => $cnt ); }
		}

		$results[] = array(
			'index'             => $ch['index'],
			'text'              => $ch['text'],
			'word_count'        => $ch['word_count'],
			'similarity'        => $sim,
			'similarity_percent'=> round( $sim * 100, 1 ),
			'topic_terms_found' => $tf,
		);
	}

	usort( $results, function( $a, $b ) { return $b['similarity'] <=> $a['similarity']; } );
	$avg = count( $results ) > 0 ? array_sum( array_column( $results, 'similarity' ) ) / count( $results ) : 0;
	$mx  = count( $results ) > 0 ? max( array_column( $results, 'similarity_percent' ) ) : 0;
	$mn  = count( $results ) > 0 ? min( array_column( $results, 'similarity_percent' ) ) : 0;

	wp_send_json_success( array(
		'mode'               => 'server',
		'phrase'             => $phrase,
		'chunks'             => $results,
		'chunk_count'        => count( $results ),
		'average_similarity' => round( $avg, 4 ),
		'average_percent'    => round( $avg * 100, 1 ),
		'max_percent'        => $mx,
		'min_percent'        => $mn,
	) );
} );

/**
 * ============================================================
 * ADMIN: LIST PAGE
 * - Improved list view (headings summary visible immediately)
 * - Click row/title -> Report page for that post
 * ============================================================
 */
function ca_render_list_page() {
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:8px;">
			<span class="dashicons dashicons-chart-bar" style="font-size:28px;width:28px;height:28px;color:#2271b1"></span>
			<?php echo esc_html__( 'Content Analyzer', 'beeclear-content-analyzer' ); ?>
		</h1>

		<div class="notice notice-info">
			<p style="margin:0;">
				<?php
				$opts = ca_get_settings();
				echo esc_html__( 'Mode:', 'beeclear-content-analyzer' ) . ' ' . '<strong>' . esc_html( $opts['mode'] ) . '</strong> — ';
				echo esc_html__( 'Click a row to open the report. Analyses are cached per topic and mode.', 'beeclear-content-analyzer' );
				?>
			</p>
		</div>

		<div id="ca-loading" style="display:none; padding:14px 0;">
			<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
			<?php echo esc_html__( 'Loading…', 'beeclear-content-analyzer' ); ?>
		</div>

		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px;margin:12px 0;max-width:1200px;">
			<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
				<div>
					<label style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;"><?php echo esc_html__( 'Search', 'beeclear-content-analyzer' ); ?></label><br>
					<input type="text" id="ca-filter-search" class="regular-text" placeholder="<?php echo esc_attr__( 'title, slug…', 'beeclear-content-analyzer' ); ?>">
				</div>
				<div>
					<label style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;"><?php echo esc_html__( 'Type', 'beeclear-content-analyzer' ); ?></label><br>
					<select id="ca-filter-type">
						<option value=""><?php echo esc_html__( 'All', 'beeclear-content-analyzer' ); ?></option>
						<option value="post"><?php echo esc_html__( 'Post', 'beeclear-content-analyzer' ); ?></option>
						<option value="page"><?php echo esc_html__( 'Page', 'beeclear-content-analyzer' ); ?></option>
					</select>
				</div>
				<div>
					<label style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;"><?php echo esc_html__( 'Status', 'beeclear-content-analyzer' ); ?></label><br>
					<select id="ca-filter-status">
						<option value=""><?php echo esc_html__( 'All', 'beeclear-content-analyzer' ); ?></option>
						<option value="publish"><?php echo esc_html__( 'Publish', 'beeclear-content-analyzer' ); ?></option>
						<option value="draft"><?php echo esc_html__( 'Draft', 'beeclear-content-analyzer' ); ?></option>
						<option value="private"><?php echo esc_html__( 'Private', 'beeclear-content-analyzer' ); ?></option>
					</select>
				</div>
				<div>
					<label style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;"><?php echo esc_html__( 'Category', 'beeclear-content-analyzer' ); ?></label><br>
					<select id="ca-filter-category"><option value=""><?php echo esc_html__( 'All', 'beeclear-content-analyzer' ); ?></option></select>
				</div>
				<div style="display:flex;gap:8px;">
					<button class="button button-primary" id="ca-apply-filters"><?php echo esc_html__( 'Apply filters', 'beeclear-content-analyzer' ); ?></button>
					<button class="button" id="ca-reset-filters"><?php echo esc_html__( 'Reset', 'beeclear-content-analyzer' ); ?></button>
				</div>
				<div style="margin-left:auto;color:#50575e;">
					<?php echo esc_html__( 'Results:', 'beeclear-content-analyzer' ); ?> <strong id="ca-count">0</strong>
				</div>
			</div>
		</div>

		<div style="overflow:auto; max-width: 100%;">
			<table class="widefat striped" id="ca-table" style="max-width: 1400px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Title', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'H1', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'H2', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'H3', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'H4', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'Words', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'Updated', 'beeclear-content-analyzer' ); ?></th>
						<th><?php echo esc_html__( 'Topic source', 'beeclear-content-analyzer' ); ?></th>
					</tr>
				</thead>
				<tbody id="ca-tbody">
					<tr><td colspan="10"><em><?php echo esc_html__( 'No data.', 'beeclear-content-analyzer' ); ?></em></td></tr>
				</tbody>
			</table>
		</div>

	</div>

	<script>
	(function($){
	'use strict';
	function E(t){return String(t||'').replace(/[&<>"']/g,function(m){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]})}

	var allPosts=[], filteredPosts=[];

	$(document).ready(function(){
		loadCats();
		loadPosts();
		bindEvents();
	});

	function loadCats(){
		$.post(caData.ajaxUrl,{action:'ca_get_categories',nonce:caData.nonce},function(r){
			if(r && r.success && r.data){
				var s=$('#ca-filter-category');
				r.data.forEach(function(c){
					s.append('<option value="'+E(c.name)+'">'+E(c.name)+'</option>');
				});
			}
		});
	}

	function loadPosts(){
		$('#ca-loading').show();
		$.post(caData.ajaxUrl,{action:'ca_get_posts_list',nonce:caData.nonce},function(r){
			$('#ca-loading').hide();
			if(r && r.success && r.data){
				allPosts=r.data; filteredPosts=r.data;
				$('#ca-count').text(filteredPosts.length);
				renderTable();
			}else{
				$('#ca-tbody').html('<tr><td colspan="10"><em><?php echo esc_js( __( 'No data.', 'beeclear-content-analyzer' ) ); ?></em></td></tr>');
			}
		});
	}

	function bindEvents(){
		$('#ca-apply-filters').on('click',function(e){e.preventDefault();applyFilters();});
		$('#ca-reset-filters').on('click',function(e){e.preventDefault();resetFilters();});
		$('#ca-tbody').on('click','tr[data-report]',function(){
			var url=$(this).data('report');
			if(url){ window.location.href=url; }
		});
	}

	function applyFilters(){
		var s=$('#ca-filter-search').val().toLowerCase().trim(),
			t=$('#ca-filter-type').val(),
			st=$('#ca-filter-status').val(),
			c=$('#ca-filter-category').val();

		filteredPosts = allPosts.filter(function(p){
			if(s && !(String(p.title||'').toLowerCase().includes(s)||String(p.slug||'').toLowerCase().includes(s))) return false;
			if(t && p.post_type!==t) return false;
			if(st && p.post_status!==st) return false;
			if(c && !(p.categories||[]).includes(c)) return false;
			return true;
		});
		$('#ca-count').text(filteredPosts.length);
		renderTable();
	}

	function resetFilters(){
		$('#ca-filter-search').val('');
		$('#ca-filter-type').val('');
		$('#ca-filter-status').val('');
		$('#ca-filter-category').val('');
		filteredPosts=allPosts;
		$('#ca-count').text(filteredPosts.length);
		renderTable();
	}

	function badge(num){
		var n=parseInt(num||0,10);
		var bg = n>0 ? '#e7f5ff' : '#f6f7f7';
		var bd = n>0 ? '#72aee6' : '#e0e0e0';
		var cl = n>0 ? '#135e96' : '#50575e';
		return '<span style="display:inline-block;min-width:26px;text-align:center;padding:2px 8px;border:1px solid '+bd+';background:'+bg+';color:'+cl+';border-radius:999px;font-weight:700;font-size:12px;">'+E(n)+'</span>';
	}

	function renderTable(){
		var tb=$('#ca-tbody'); tb.empty();
		if(!filteredPosts.length){
			tb.html('<tr><td colspan="10"><em><?php echo esc_js( __( 'No data.', 'beeclear-content-analyzer' ) ); ?></em></td></tr>');
			return;
		}

		filteredPosts.forEach(function(p){
			var topicSource = p.has_focus_meta ? 'Meta box' : (p.headings && p.headings.h1>0 ? 'H1' : 'Title');
			var row = '<tr style="cursor:pointer" data-report="'+E(p.report_url)+'">'+
				'<td><strong>'+E(p.title)+'</strong><div style="color:#646970;font-size:12px;">'+E(p.slug)+'</div></td>'+
				'<td>'+E(p.post_type)+'</td>'+
				'<td>'+E(p.post_status)+'</td>'+
				'<td>'+badge(p.headings.h1)+'</td>'+
				'<td>'+badge(p.headings.h2)+'</td>'+
				'<td>'+badge(p.headings.h3)+'</td>'+
				'<td>'+badge(p.headings.h4)+'</td>'+
				'<td>'+E(p.word_count)+'</td>'+
				'<td>'+E(String(p.date_modified||'').slice(0,10))+'</td>'+
				'<td><span style="color:#50575e;">'+E(topicSource)+'</span></td>'+
			'</tr>';
			tb.append(row);
		});
	}
	})(jQuery);
	</script>
	<?php
}

/**
 * ============================================================
 * ADMIN: REPORT PAGE
 * - Shows cached analysis dates
 * - Runs analysis only when needed
 * - Topic priority: override input > meta box > first H1 > title
 * ============================================================
 */
function ca_render_report_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	$post_id = absint( $_GET['post_id'] ?? 0 );
	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Report', 'beeclear-content-analyzer' ) . '</h1><p>' . esc_html__( 'Post not found.', 'beeclear-content-analyzer' ) . '</p></div>';
		return;
	}

	$opts = ca_get_settings();
	$mode = $opts['mode'];

	?>
	<div class="wrap" style="max-width: 1300px;">
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=content-analyzer' ) ); ?>">&larr; <?php echo esc_html__( 'Back to list', 'beeclear-content-analyzer' ); ?></a>
		</p>

		<h1 style="display:flex;align-items:center;gap:10px;">
			<span class="dashicons dashicons-media-document" style="font-size:28px;width:28px;height:28px;color:#2271b1"></span>
			<?php echo esc_html( $post->post_title ); ?>
			<span style="color:#646970;font-size:13px;">#<?php echo (int) $post_id; ?></span>
		</h1>

		<div style="display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 12px 0;">
			<a class="button" href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php echo esc_html__( 'Edit', 'beeclear-content-analyzer' ); ?></a>
			<a class="button" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'View', 'beeclear-content-analyzer' ); ?></a>
			<button class="button" id="ca-clear-cache"><?php echo esc_html__( 'Clear cached analyses', 'beeclear-content-analyzer' ); ?></button>
			<span style="margin-left:auto;color:#50575e;padding-top:6px;">
				<?php echo esc_html__( 'Mode:', 'beeclear-content-analyzer' ); ?> <strong id="ca-mode"><?php echo esc_html( $mode ); ?></strong>
			</span>
		</div>

		<div id="ca-report-loading" style="display:none; padding:14px 0;">
			<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
			<?php echo esc_html__( 'Loading…', 'beeclear-content-analyzer' ); ?>
		</div>

		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:16px;margin:12px 0;">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Topic & cache', 'beeclear-content-analyzer' ); ?></h2>

			<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
				<div style="min-width: 360px;">
					<label style="font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;"><?php echo esc_html__( 'Topic for analysis', 'beeclear-content-analyzer' ); ?></label><br>
					<input type="text" id="ca-topic" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr__( 'Topic…', 'beeclear-content-analyzer' ); ?>">
					<div style="color:#646970;font-size:12px;margin-top:4px;" id="ca-topic-hint"></div>
				</div>

				<div>
					<button class="button button-primary" id="ca-run-embedding"><?php echo esc_html__( 'Run embedding analysis', 'beeclear-content-analyzer' ); ?></button>
					<div style="color:#646970;font-size:12px;margin-top:6px;">
						<?php echo esc_html__( 'Cached:', 'beeclear-content-analyzer' ); ?> <span id="ca-embed-cached">—</span>
					</div>
				</div>

				<div>
					<button class="button button-primary" id="ca-run-chunks"><?php echo esc_html__( 'Run chunk analysis', 'beeclear-content-analyzer' ); ?></button>
					<div style="color:#646970;font-size:12px;margin-top:6px;">
						<?php echo esc_html__( 'Cached:', 'beeclear-content-analyzer' ); ?> <span id="ca-chunk-cached">—</span>
					</div>
				</div>

				<div style="margin-left:auto;">
					<button class="button" id="ca-use-meta"><?php echo esc_html__( 'Use meta box topic', 'beeclear-content-analyzer' ); ?></button>
					<button class="button" id="ca-use-h1"><?php echo esc_html__( 'Use H1', 'beeclear-content-analyzer' ); ?></button>
				</div>
			</div>
		</div>

		<div style="display:grid;grid-template-columns:repeat(12,1fr);gap:16px;">
			<div style="grid-column:span 12;background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:16px;">
				<h2 style="margin-top:0;"><?php echo esc_html__( 'Embedding analysis (topic vs terms)', 'beeclear-content-analyzer' ); ?></h2>
				<div id="ca-embedding-results"><em><?php echo esc_html__( 'Run analysis to see results.', 'beeclear-content-analyzer' ); ?></em></div>
			</div>

			<div style="grid-column:span 12;background:#fff;border:1px solid #c3c4c7;border-radius:10px;padding:16px;">
				<h2 style="margin-top:0;"><?php echo esc_html__( 'Chunk analysis (topic vs paragraphs)', 'beeclear-content-analyzer' ); ?></h2>
				<div id="ca-chunk-results"><em><?php echo esc_html__( 'Run analysis to see results.', 'beeclear-content-analyzer' ); ?></em></div>
			</div>
		</div>

	</div>

	<script>
	(function($){
	'use strict';
	function E(t){return String(t||'').replace(/[&<>"']/g,function(m){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]})}

	var postId = <?php echo (int) $post_id; ?>;
	var mode = (caData && caData.mode) ? caData.mode : 'server';

	var postDetail = null;

	// ============================================================
	// Browser mode vectors (no external libs)
	// ============================================================
	function caTok(s){
		s=(s||'').toLowerCase();
		return s.split(/[^0-9a-ząćęłńóśżź\-]+/i).map(function(x){return x.replace(/^\-+|\-+$/g,'');}).filter(function(x){return x.length>=3;});
	}
	function caHash32(str){
		var h=0x811c9dc5;
		for(var i=0;i<str.length;i++){
			h ^= str.charCodeAt(i);
			h = (h + ((h<<1)+(h<<4)+(h<<7)+(h<<8)+(h<<24))) >>> 0;
		}
		return h>>>0;
	}
	function caVectorize(text, dim){
		dim=dim||1024;
		var v=new Array(dim);
		for(var i=0;i<dim;i++) v[i]=0;
		var t=(text||'').toLowerCase();

		var toks=caTok(t);
		for(var i=0;i<toks.length;i++){
			var w=toks[i];
			v[caHash32('w:'+w)%dim]+=1;
			if(i<toks.length-1){
				var bg=w+' '+toks[i+1];
				v[caHash32('b:'+bg)%dim]+=0.35;
			}
		}
		var s=t.replace(/\s+/g,' ');
		for(var j=0;j<s.length-3;j++){
			var g=s.substr(j,4);
			if(g.trim().length<4) continue;
			v[caHash32('c:'+g)%dim]+=0.08;
		}
		return v;
	}
	function caCos(a,b){
		var dot=0,ma=0,mb=0;
		var n=Math.min(a.length,b.length);
		for(var i=0;i<n;i++){var x=a[i]||0,y=b[i]||0;dot+=x*y;ma+=x*x;mb+=y*y;}
		ma=Math.sqrt(ma);mb=Math.sqrt(mb);
		if(!ma||!mb) return 0;
		return dot/(ma*mb);
	}

	function caBrowserWordAnalysis(text, phrase){
		var dim=1024;
		var topicVec = caVectorize(phrase, dim);
		var fullVec = caVectorize(text, dim);
		var overall = caCos(topicVec, fullVec);

		var words = caTok(text);
		var freq = {};
		for(var i=0;i<words.length;i++){ var w=words[i]; freq[w]=(freq[w]||0)+1; }
		var entries = Object.keys(freq).map(function(k){return {w:k,c:freq[k]};});
		entries.sort(function(a,b){return b.c-a.c;});
		entries = entries.slice(0,600);

		var pset={}; caTok(phrase).forEach(function(w){pset[w]=1;});

		var out=[];
		var win=5;
		for(var ei=0;ei<entries.length;ei++){
			var term=entries[ei].w, count=entries[ei].c;
			var dm=pset[term]?1:0;

			var ctx=[];
			for(var i=0;i<words.length;i++){
				if(words[i]!==term) continue;
				for(var j=Math.max(0,i-win); j<=Math.min(words.length-1,i+win); j++){
					if(j===i) continue;
					ctx.push(words[j]);
				}
				if(ctx.length>200) break;
			}

			var ctxVec = caVectorize(ctx.join(' '), dim);
			var cs = caCos(topicVec, ctxVec);
			var score = Math.min(1,(dm*0.55)+(cs*0.45));

			out.push({
				word: term,
				count: count,
				direct_match: !!dm,
				context_score: Math.round(cs*1000)/10,
				relevance_score: Math.round(score*1000)/10
			});
		}

		out.sort(function(a,b){return b.relevance_score-a.relevance_score;});
		var hi=0,md=0,lo=0,sum=0;
		for(var i=0;i<out.length;i++){
			var rs=out[i].relevance_score; sum+=rs;
			if(rs>=40)hi++; else if(rs>=15)md++; else lo++;
		}
		var avg = out.length ? Math.round((sum/out.length)*10)/10 : 0;

		return {
			mode: 'browser',
			phrase: phrase,
			overall_similarity: Math.round(overall*1000)/10,
			total_unique_words: out.length,
			high_relevance: hi,
			medium_relevance: md,
			low_relevance: lo,
			average_relevance: avg,
			words: out.slice(0,200)
		};
	}

	function caBrowserChunkAnalysis(chunks, phrase){
		var dim=1024;
		var topicVec=caVectorize(phrase, dim);

		var res=[];
		for(var i=0;i<chunks.length;i++){
			var ch=chunks[i];
			var vec=caVectorize(ch.text||'', dim);
			var sim=caCos(topicVec, vec);
			res.push({
				index: ch.index,
				text: ch.text,
				word_count: ch.word_count,
				similarity: sim,
				similarity_percent: Math.round(sim*1000)/10,
				topic_terms_found: []
			});
		}
		res.sort(function(a,b){return b.similarity-a.similarity;});

		var sum=0,mx=0,mn=100;
		for(var i=0;i<res.length;i++){
			sum+=res[i].similarity;
			mx=Math.max(mx,res[i].similarity_percent);
			mn=Math.min(mn,res[i].similarity_percent);
		}
		var avg = res.length ? (sum/res.length) : 0;

		return {
			mode: 'browser',
			phrase: phrase,
			chunks: res,
			chunk_count: res.length,
			average_similarity: Math.round(avg*10000)/10000,
			average_percent: Math.round(avg*1000)/10,
			max_percent: mx,
			min_percent: (mn===100?0:mn)
		};
	}

	// ============================================================
	// UI renderers
	// ============================================================
	function scoreClass(pct){
		if(pct<15) return 'background:#d63638';
		if(pct<30) return 'background:#dba617';
		if(pct<50) return 'background:#2ea2cc';
		return 'background:#1d9b4d';
	}
	function renderEmbed(d, createdAt){
		var wrap=$('#ca-embedding-results');
		if(!d){ wrap.html('<em><?php echo esc_js( __( 'No data.', 'beeclear-content-analyzer' ) ); ?></em>'); return; }

		var pct = parseFloat(d.overall_similarity||0);
		var badge = '<span style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;color:#fff;font-weight:800;'+scoreClass(pct)+'">'+E(pct)+'%</span>';
		var meta = '<div style="color:#646970;font-size:12px;margin-top:4px;">'+
			'<?php echo esc_js( __( 'Topic:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.phrase||'')+'</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Mode:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.mode||'')+'</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Generated:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(createdAt||'—')+'</strong>'+
		'</div>';

		var summary = '<div style="display:flex;gap:14px;align-items:center;margin:8px 0 12px 0;">'+badge+
			'<div><div><strong><?php echo esc_js( __( 'Overall similarity', 'beeclear-content-analyzer' ) ); ?></strong></div>'+
			'<div style="color:#50575e;font-size:12px;margin-top:2px;">'+
			'<?php echo esc_js( __( 'Unique words:', 'beeclear-content-analyzer' ) ); ?> '+E(d.total_unique_words)+' &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'High:', 'beeclear-content-analyzer' ) ); ?> '+E(d.high_relevance)+' &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Medium:', 'beeclear-content-analyzer' ) ); ?> '+E(d.medium_relevance)+' &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Low:', 'beeclear-content-analyzer' ) ); ?> '+E(d.low_relevance)+' &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Avg:', 'beeclear-content-analyzer' ) ); ?> '+E(d.average_relevance)+'%'+
			'</div>'+meta+'</div></div>';

		var rows = (d.words||[]).map(function(w){
			return '<tr>'+
				'<td><strong>'+E(w.word)+'</strong></td>'+
				'<td>'+E(w.count)+'</td>'+
				'<td>'+(w.direct_match?'<span style="display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #72aee6;background:#e7f5ff;color:#135e96;font-weight:700;font-size:12px;">yes</span>':'—')+'</td>'+
				'<td>'+E(w.context_score)+'%</td>'+
				'<td><strong>'+E(w.relevance_score)+'%</strong></td>'+
			'</tr>';
		}).join('');

		var table = '<table class="widefat striped" style="max-width:1000px;"><thead><tr>'+
			'<th><?php echo esc_js( __( 'Term', 'beeclear-content-analyzer' ) ); ?></th>'+
			'<th><?php echo esc_js( __( 'Count', 'beeclear-content-analyzer' ) ); ?></th>'+
			'<th><?php echo esc_js( __( 'Direct', 'beeclear-content-analyzer' ) ); ?></th>'+
			'<th><?php echo esc_js( __( 'Context', 'beeclear-content-analyzer' ) ); ?></th>'+
			'<th><?php echo esc_js( __( 'Relevance', 'beeclear-content-analyzer' ) ); ?></th>'+
		'</tr></thead><tbody>'+rows+'</tbody></table>';

		wrap.html(summary + table);
	}

	function renderChunks(d, createdAt){
		var wrap=$('#ca-chunk-results');
		if(!d){ wrap.html('<em><?php echo esc_js( __( 'No data.', 'beeclear-content-analyzer' ) ); ?></em>'); return; }

		var meta = '<div style="color:#646970;font-size:12px;margin:4px 0 10px 0;">'+
			'<?php echo esc_js( __( 'Topic:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.phrase||'')+'</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Mode:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.mode||'')+'</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Generated:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(createdAt||'—')+'</strong>'+
		'</div>';

		var summary = '<div style="color:#50575e;margin:6px 0 10px 0;">'+
			'<?php echo esc_js( __( 'Chunks:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.chunk_count)+'</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Average:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.average_percent)+'%</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Max:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.max_percent)+'%</strong> &nbsp;|&nbsp; '+
			'<?php echo esc_js( __( 'Min:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(d.min_percent)+'%</strong>'+
		'</div>';

		var rows=(d.chunks||[]).slice(0,120).map(function(ch){
			var pct = parseFloat(ch.similarity_percent||0);
			var pill = '<span style="display:inline-block;min-width:62px;text-align:center;padding:2px 8px;border-radius:999px;color:#fff;font-weight:800;'+scoreClass(pct)+'">'+E(pct)+'%</span>';
			return '<tr>'+
				'<td>'+E(ch.index)+'</td>'+
				'<td>'+pill+'</td>'+
				'<td>'+E(ch.word_count)+'</td>'+
				'<td>'+E(String(ch.text||'').slice(0,380))+(String(ch.text||'').length>380?'…':'')+'</td>'+
			'</tr>';
		}).join('');

		var table = '<table class="widefat striped" style="max-width:1100px;"><thead><tr>'+
			'<th>#</th><th><?php echo esc_js( __( 'Similarity', 'beeclear-content-analyzer' ) ); ?></th><th><?php echo esc_js( __( 'Words', 'beeclear-content-analyzer' ) ); ?></th><th><?php echo esc_js( __( 'Text', 'beeclear-content-analyzer' ) ); ?></th>'+
		'</tr></thead><tbody>'+rows+'</tbody></table>';

		wrap.html(meta + summary + table);
	}

	function setCachedLabel($el, createdAt){
		$el.text(createdAt ? createdAt : '—');
	}

	function loadPostDetail(){
		$('#ca-report-loading').show();
		$.post(caData.ajaxUrl,{action:'ca_get_post_detail',nonce:caData.nonce,post_id:postId},function(r){
			$('#ca-report-loading').hide();
			if(!r || !r.success || !r.data){ return; }
			postDetail=r.data;

			// Set default topic
			$('#ca-topic').val(postDetail.default_topic||'');
			updateTopicHint();

			// Show cache status for default topic
			refreshCacheLabels();
			loadCachedIfAny(); // show cached results immediately if available
		});
	}

	function updateTopicHint(){
		if(!postDetail){ return; }
		var meta = (postDetail.focus_phrase_meta||'').trim();
		var h1   = (postDetail.h1||'').trim();
		var chosen = ($('#ca-topic').val()||'').trim();

		var src = 'override';
		if(chosen === meta && meta) src='meta box';
		else if(chosen === h1 && h1) src='H1';
		else if(chosen === (postDetail.title||'').trim()) src='title';

		var hint = '<?php echo esc_js( __( 'Current topic source:', 'beeclear-content-analyzer' ) ); ?> <strong>'+E(src)+'</strong>';
		if(meta){ hint += ' &nbsp;|&nbsp; <?php echo esc_js( __( 'Meta:', 'beeclear-content-analyzer' ) ); ?> '+E(meta); }
		if(h1){ hint += ' &nbsp;|&nbsp; <?php echo esc_js( __( 'H1:', 'beeclear-content-analyzer' ) ); ?> '+E(h1); }
		$('#ca-topic-hint').html(hint);
	}

	function refreshCacheLabels(){
		var phrase = ($('#ca-topic').val()||'').trim();
		if(!phrase){ return; }

		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'embedding',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){ setCachedLabel($('#ca-embed-cached'), r.data.created_at); }
			else { setCachedLabel($('#ca-embed-cached'), null); }
		});
		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'chunks',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){ setCachedLabel($('#ca-chunk-cached'), r.data.created_at); }
			else { setCachedLabel($('#ca-chunk-cached'), null); }
		});
	}

	function loadCachedIfAny(){
		var phrase = ($('#ca-topic').val()||'').trim();
		if(!phrase){ return; }

		// Embedding cached
		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'embedding',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){
				renderEmbed(r.data.data, r.data.created_at);
			}
		});
		// Chunks cached
		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'chunks',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){
				renderChunks(r.data.data, r.data.created_at);
			}
		});
	}

	function saveCache(type, phrase, data, cb){
		$.post(caData.ajaxUrl,{
			action:'ca_save_cached_analysis',
			nonce:caData.nonce,
			post_id:postId,
			analysis_type:type,
			phrase:phrase,
			mode:mode,
			analysis_data: JSON.stringify(data)
		},function(r){
			if(cb) cb(r && r.success);
		});
	}

	function runEmbedding(){
		var phrase = ($('#ca-topic').val()||'').trim();
		if(!phrase){ alert('<?php echo esc_js( __( 'Please enter a topic.', 'beeclear-content-analyzer' ) ); ?>'); return; }

		// Check cache first
		$('#ca-embedding-results').html('<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span><?php echo esc_js( __( 'Running analysis…', 'beeclear-content-analyzer' ) ); ?></p>');
		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'embedding',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){
				renderEmbed(r.data.data, r.data.created_at);
				setCachedLabel($('#ca-embed-cached'), r.data.created_at);
				return;
			}

			// Not cached: compute depending on mode
			if(mode==='browser'){
				try{
					var text = caPlainTextFromDetail();
					var computed = caBrowserWordAnalysis(text, phrase);
					renderEmbed(computed, '<?php echo esc_js( current_time('mysql') ); ?>');
					saveCache('embedding', phrase, computed, function(ok){ refreshCacheLabels(); });
				}catch(e){
					if(window.console && console.error) console.error(e);
					$('#ca-embedding-results').html('<em><?php echo esc_js( __( 'Browser analysis failed.', 'beeclear-content-analyzer' ) ); ?></em>');
				}
			}else{
				$.post(caData.ajaxUrl,{action:'ca_run_embedding_server',nonce:caData.nonce,post_id:postId,phrase:phrase},function(res){
					if(res && res.success && res.data){
						renderEmbed(res.data, '<?php echo esc_js( current_time('mysql') ); ?>');
						saveCache('embedding', phrase, res.data, function(ok){ refreshCacheLabels(); });
					}else{
						$('#ca-embedding-results').html('<em><?php echo esc_js( __( 'Error.', 'beeclear-content-analyzer' ) ); ?></em>');
					}
				});
			}
		});
	}

	function runChunks(){
		var phrase = ($('#ca-topic').val()||'').trim();
		if(!phrase){ alert('<?php echo esc_js( __( 'Please enter a topic.', 'beeclear-content-analyzer' ) ); ?>'); return; }

		$('#ca-chunk-results').html('<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span><?php echo esc_js( __( 'Running analysis…', 'beeclear-content-analyzer' ) ); ?></p>');

		$.post(caData.ajaxUrl,{action:'ca_get_cached_analysis',nonce:caData.nonce,post_id:postId,analysis_type:'chunks',phrase:phrase,mode:mode},function(r){
			if(r && r.success && r.data && r.data.found){
				renderChunks(r.data.data, r.data.created_at);
				setCachedLabel($('#ca-chunk-cached'), r.data.created_at);
				return;
			}

			if(mode==='browser'){
				try{
					var computed = caBrowserChunkAnalysis((postDetail && postDetail.chunks)?postDetail.chunks:[], phrase);
					renderChunks(computed, '<?php echo esc_js( current_time('mysql') ); ?>');
					saveCache('chunks', phrase, computed, function(ok){ refreshCacheLabels(); });
				}catch(e){
					if(window.console && console.error) console.error(e);
					$('#ca-chunk-results').html('<em><?php echo esc_js( __( 'Browser analysis failed.', 'beeclear-content-analyzer' ) ); ?></em>');
				}
			}else{
				$.post(caData.ajaxUrl,{action:'ca_run_chunks_server',nonce:caData.nonce,post_id:postId,phrase:phrase},function(res){
					if(res && res.success && res.data){
						renderChunks(res.data, '<?php echo esc_js( current_time('mysql') ); ?>');
						saveCache('chunks', phrase, res.data, function(ok){ refreshCacheLabels(); });
					}else{
						$('#ca-chunk-results').html('<em><?php echo esc_js( __( 'Error.', 'beeclear-content-analyzer' ) ); ?></em>');
					}
				});
			}
		});
	}

	function caPlainTextFromDetail(){
		// Prefer assembling from chunks (keeps it consistent with page content)
		if(postDetail && postDetail.chunks && postDetail.chunks.length){
			return postDetail.chunks.map(function(ch){return ch.text||'';}).join(' ');
		}
		// Fallback: title
		return (postDetail && postDetail.title) ? postDetail.title : '';
	}

	function clearCache(){
		if(!confirm('<?php echo esc_js( __( 'Clear all cached analyses for this post?', 'beeclear-content-analyzer' ) ); ?>')) return;
		$.post(caData.ajaxUrl,{action:'ca_clear_post_cache',nonce:caData.nonce,post_id:postId},function(r){
			refreshCacheLabels();
			alert('<?php echo esc_js( __( 'Cache cleared.', 'beeclear-content-analyzer' ) ); ?>');
		});
	}

	function useMeta(){
		if(!postDetail) return;
		if((postDetail.focus_phrase_meta||'').trim()===''){
			alert('<?php echo esc_js( __( 'Meta box topic is empty.', 'beeclear-content-analyzer' ) ); ?>');
			return;
		}
		$('#ca-topic').val((postDetail.focus_phrase_meta||'').trim());
		updateTopicHint();
		refreshCacheLabels();
		loadCachedIfAny();
	}

	function useH1(){
		if(!postDetail) return;
		if((postDetail.h1||'').trim()===''){
			alert('<?php echo esc_js( __( 'H1 not found.', 'beeclear-content-analyzer' ) ); ?>');
			return;
		}
		$('#ca-topic').val((postDetail.h1||'').trim());
		updateTopicHint();
		refreshCacheLabels();
		loadCachedIfAny();
	}

	$(document).ready(function(){
		loadPostDetail();

		$('#ca-topic').on('change keyup', function(){
			updateTopicHint();
			// do not spam cache calls on each keystroke; simple debounce
			clearTimeout(window.__caTopicTimer);
			window.__caTopicTimer = setTimeout(function(){
				refreshCacheLabels();
			}, 400);
		});

		$('#ca-run-embedding').on('click', function(e){ e.preventDefault(); runEmbedding(); });
		$('#ca-run-chunks').on('click', function(e){ e.preventDefault(); runChunks(); });
		$('#ca-clear-cache').on('click', function(e){ e.preventDefault(); clearCache(); });
		$('#ca-use-meta').on('click', function(e){ e.preventDefault(); useMeta(); });
		$('#ca-use-h1').on('click', function(e){ e.preventDefault(); useH1(); });
	});

	})(jQuery);
	</script>
	<?php
}

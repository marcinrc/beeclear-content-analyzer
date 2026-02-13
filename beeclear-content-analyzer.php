<?php
/**
 * Plugin Name: BeeClear Content Analyzer
 * Plugin URI: https://beeclear.pl
 * Description: Content topicality analysis for WordPress posts and pages — server-side relevance + browser-side semantic vectors, with caching and reports.
 * Version: 1.3.1
 * Author: <a href="https://beeclear.pl">BeeClear</a>
 * License: GPL v2 or later
 * Text Domain: beeclear-content-analyzer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCCA_VERSION', '1.3.1' );

/* ============================================================
   i18n
   ============================================================ */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'beeclear-content-analyzer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* ============================================================
   Activation: cache table
   ============================================================ */
register_activation_hook( __FILE__, function () {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';

	$sql = "CREATE TABLE $table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id bigint(20) UNSIGNED NOT NULL,
		mode varchar(20) NOT NULL DEFAULT 'server',
		analysis_type varchar(30) NOT NULL DEFAULT 'word',
		phrase_hash varchar(64) NOT NULL DEFAULT '',
		phrase_text varchar(500) NOT NULL DEFAULT '',
		analysis_data longtext NOT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY post_id (post_id),
		KEY mode_type (mode, analysis_type),
		KEY phrase_hash (phrase_hash)
	) {$wpdb->get_charset_collate()};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
} );

/* ============================================================
   Settings helpers
   ============================================================ */
function bcca_defaults() {
	return array(
		'mode'                      => 'server', // server|browser
		'delete_data_on_deactivate' => 0,
	);
}

function bcca_get_settings() {
	$opts = get_option( 'bcca_settings', array() );
	if ( ! is_array( $opts ) ) {
		$opts = array();
	}
	return wp_parse_args( $opts, bcca_defaults() );
}

function bcca_update_settings( $new ) {
	$clean = bcca_defaults();
	$clean['mode'] = ( isset( $new['mode'] ) && in_array( $new['mode'], array( 'server', 'browser' ), true ) ) ? $new['mode'] : 'server';
	$clean['delete_data_on_deactivate'] = empty( $new['delete_data_on_deactivate'] ) ? 0 : 1;
	update_option( 'bcca_settings', $clean, false );
	return $clean;
}

/* ============================================================
   Cleanup
   ============================================================ */
function bcca_clear_all_data() {
	global $wpdb;

	delete_option( 'bcca_settings' );

	// Delete focus topic meta
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_ca_focus_phrase'
		)
	);

	// Drop cache table
	$table = $wpdb->prefix . 'ca_analysis_cache';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

register_deactivation_hook( __FILE__, function () {
	$opts = bcca_get_settings();
	if ( ! empty( $opts['delete_data_on_deactivate'] ) ) {
		bcca_clear_all_data();
	}
} );

register_uninstall_hook( __FILE__, 'bcca_clear_all_data' );

/* ============================================================
   Post meta: Focus topic (metabox)
   ============================================================ */
add_action( 'init', function () {
	register_post_meta( '', '_ca_focus_phrase', array(
		'show_in_rest'  => true,
		'single'        => true,
		'type'          => 'string',
		'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	) );
} );

add_action( 'add_meta_boxes', function () {
	foreach ( array( 'post', 'page' ) as $pt ) {
		add_meta_box(
			'bcca_focus_phrase',
			esc_html__( 'Content Analyzer — Focus topic', 'beeclear-content-analyzer' ),
			'bcca_render_metabox',
			$pt,
			'side'
		);
	}
} );

function bcca_render_metabox( $post ) {
	$ph = get_post_meta( $post->ID, '_ca_focus_phrase', true );
	wp_nonce_field( 'bcca_save_meta', 'bcca_meta_nonce' );

	echo '<p><label for="bcca_focus_phrase">' . esc_html__( 'Focus phrase / topic:', 'beeclear-content-analyzer' ) . '</label></p>';
	echo '<input type="text" id="bcca_focus_phrase" name="bcca_focus_phrase" value="' . esc_attr( $ph ) . '" style="width:100%" placeholder="e.g. content marketing" />';
	echo '<p class="description">' . esc_html__( 'Used as the default topic for word + chunk analysis.', 'beeclear-content-analyzer' ) . '</p>';
}

add_action( 'save_post', function ( $pid ) {
	if ( ! isset( $_POST['bcca_meta_nonce'] ) || ! wp_verify_nonce( $_POST['bcca_meta_nonce'], 'bcca_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $pid ) ) {
		return;
	}
	if ( isset( $_POST['bcca_focus_phrase'] ) ) {
		update_post_meta( $pid, '_ca_focus_phrase', sanitize_text_field( $_POST['bcca_focus_phrase'] ) );
	}
} );

/* ============================================================
   Menus (Global settings first) + Import/Export + Report
   ============================================================ */
add_action( 'admin_menu', function () {
	add_menu_page(
		__( 'Content Analyzer', 'beeclear-content-analyzer' ),
		__( 'Content Analyzer', 'beeclear-content-analyzer' ),
		'edit_posts',
		'bcca-content-analyzer',
		'bcca_render_list_page',
		'dashicons-chart-bar',
		30
	);

	// Global settings FIRST
	add_submenu_page(
		'bcca-content-analyzer',
		__( 'Global settings', 'beeclear-content-analyzer' ),
		__( 'Global settings', 'beeclear-content-analyzer' ),
		'manage_options',
		'bcca-global-settings',
		'bcca_render_global_settings_page'
	);

	// Analyzer list
	add_submenu_page(
		'bcca-content-analyzer',
		__( 'Analyzer', 'beeclear-content-analyzer' ),
		__( 'Analyzer', 'beeclear-content-analyzer' ),
		'edit_posts',
		'bcca-content-analyzer',
		'bcca_render_list_page'
	);

	// Import/Export
	add_submenu_page(
		'bcca-content-analyzer',
		__( 'Import/Export', 'beeclear-content-analyzer' ),
		__( 'Import/Export', 'beeclear-content-analyzer' ),
		'manage_options',
		'bcca-import-export',
		'bcca_render_import_export_page'
	);

	// Hidden report page
	add_submenu_page(
		null,
		__( 'Content report', 'beeclear-content-analyzer' ),
		__( 'Content report', 'beeclear-content-analyzer' ),
		'edit_posts',
		'bcca-report',
		'bcca_render_report_page'
	);
} );

// Settings link on Plugins list (FIRST)
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=bcca-global-settings' ) ) . '">' . esc_html__( 'Settings', 'beeclear-content-analyzer' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );

/* ============================================================
   Admin assets
   ============================================================ */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$allowed = array(
		'toplevel_page_bcca-content-analyzer',
		'bcca-content-analyzer_page_bcca-global-settings',
		'bcca-content-analyzer_page_bcca-import-export',
	);

	// Report page
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'bcca-report' ) {
		$allowed[] = $hook;
	}

	if ( ! in_array( $hook, $allowed, true ) && ( empty( $_GET['page'] ) || $_GET['page'] !== 'bcca-report' ) ) {
		return;
	}

	wp_enqueue_script( 'jquery' );

	$opts = bcca_get_settings();
	$data = array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'bcca_nonce' ),
		'mode'    => $opts['mode'],
	);
	wp_add_inline_script( 'jquery', 'window.bccaData=' . wp_json_encode( $data ) . ';', 'before' );
} );

/* ============================================================
   Global Settings page
   ============================================================ */
function bcca_render_global_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	$opts = bcca_get_settings();

	if ( isset( $_POST['bcca_save_settings'] ) ) {
		check_admin_referer( 'bcca_save_settings', 'bcca_settings_nonce' );
		$opts = bcca_update_settings( array(
			'mode'                      => sanitize_text_field( $_POST['bcca_mode'] ?? '' ),
			'delete_data_on_deactivate' => ! empty( $_POST['bcca_delete_data_on_deactivate'] ) ? 1 : 0,
		) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'beeclear-content-analyzer' ) . '</p></div>';
	}

	if ( isset( $_POST['bcca_clear_data'] ) ) {
		check_admin_referer( 'bcca_clear_data', 'bcca_clear_nonce' );
		bcca_clear_all_data();
		bcca_update_settings( bcca_defaults() );
		$opts = bcca_get_settings();
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Plugin data cleared.', 'beeclear-content-analyzer' ) . '</p></div>';
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Global settings', 'beeclear-content-analyzer' ); ?></h1>

		<form method="post" style="max-width: 1100px;">
			<?php wp_nonce_field( 'bcca_save_settings', 'bcca_settings_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bcca_mode"><?php echo esc_html__( 'Analysis mode', 'beeclear-content-analyzer' ); ?></label></th>
					<td>
						<select id="bcca_mode" name="bcca_mode">
							<option value="server" <?php selected( $opts['mode'], 'server' ); ?>>
								<?php echo esc_html__( 'Server (PHP)', 'beeclear-content-analyzer' ); ?>
							</option>
							<option value="browser" <?php selected( $opts['mode'], 'browser' ); ?>>
								<?php echo esc_html__( 'Browser (client-side)', 'beeclear-content-analyzer' ); ?>
							</option>
						</select>
						<p class="description">
							<?php echo esc_html__( 'Server mode runs in PHP. Browser mode computes semantic vectors in your browser (no external services).', 'beeclear-content-analyzer' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Data handling', 'beeclear-content-analyzer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bcca_delete_data_on_deactivate" value="1" <?php checked( ! empty( $opts['delete_data_on_deactivate'] ) ); ?> />
							<?php echo esc_html__( 'Delete plugin data on deactivation', 'beeclear-content-analyzer' ); ?>
						</label>
						<p class="description">
							<?php echo esc_html__( 'Removes settings, focus topic meta, and cache table when you deactivate the plugin.', 'beeclear-content-analyzer' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( esc_html__( 'Save settings', 'beeclear-content-analyzer' ), 'primary', 'bcca_save_settings' ); ?>
		</form>

		<hr />

		<form method="post" style="max-width: 1100px;">
			<?php wp_nonce_field( 'bcca_clear_data', 'bcca_clear_nonce' ); ?>
			<h2><?php echo esc_html__( 'Clear data', 'beeclear-content-analyzer' ); ?></h2>
			<p><?php echo esc_html__( 'This removes plugin settings, focus topic meta, and the cache table. Use with caution.', 'beeclear-content-analyzer' ); ?></p>
			<?php submit_button( esc_html__( 'Clear plugin data', 'beeclear-content-analyzer' ), 'delete', 'bcca_clear_data' ); ?>
		</form>
	</div>
	<?php
}

/* ============================================================
   Import/Export
   ============================================================ */
function bcca_render_import_export_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	// Export
	if ( isset( $_POST['bcca_export'] ) ) {
		check_admin_referer( 'bcca_export', 'bcca_export_nonce' );

		$payload = array(
			'exported_at' => gmdate( 'c' ),
			'plugin'      => 'beeclear-content-analyzer',
			'version'     => BCCA_VERSION,
			'settings'    => bcca_get_settings(),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=beeclear-content-analyzer-export.json' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// Import
	if ( isset( $_POST['bcca_import'] ) ) {
		check_admin_referer( 'bcca_import', 'bcca_import_nonce' );

		if ( empty( $_FILES['bcca_import_file']['tmp_name'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please choose a JSON file.', 'beeclear-content-analyzer' ) . '</p></div>';
		} else {
			$raw  = file_get_contents( $_FILES['bcca_import_file']['tmp_name'] );
			$json = json_decode( $raw, true );

			if ( ! is_array( $json ) || empty( $json['settings'] ) || ! is_array( $json['settings'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid import file.', 'beeclear-content-analyzer' ) . '</p></div>';
			} else {
				bcca_update_settings( $json['settings'] );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Import completed.', 'beeclear-content-analyzer' ) . '</p></div>';
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Import/Export', 'beeclear-content-analyzer' ); ?></h1>

		<div style="max-width:1100px;">
			<h2><?php echo esc_html__( 'Export', 'beeclear-content-analyzer' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'bcca_export', 'bcca_export_nonce' ); ?>
				<?php submit_button( esc_html__( 'Export settings', 'beeclear-content-analyzer' ), 'secondary', 'bcca_export' ); ?>
			</form>

			<hr />

			<h2><?php echo esc_html__( 'Import', 'beeclear-content-analyzer' ); ?></h2>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'bcca_import', 'bcca_import_nonce' ); ?>
				<input type="file" name="bcca_import_file" accept="application/json" />
				<?php submit_button( esc_html__( 'Import settings', 'beeclear-content-analyzer' ), 'primary', 'bcca_import' ); ?>
			</form>
		</div>
	</div>
	<?php
}

/* ============================================================
   Text extraction helpers
   ============================================================ */
function bcca_plain_text_from_post( $post ) {
	$text = wp_strip_all_tags( $post->post_content );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
	return $text;
}

function bcca_extract_headings( $html ) {
	$out = array(
		'h1' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'h5' => array(), 'h6' => array(),
		'total' => 0,
	);

	for ( $i = 1; $i <= 6; $i++ ) {
		preg_match_all( '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si', (string) $html, $m );
		if ( ! empty( $m[1] ) ) {
			foreach ( $m[1] as $h ) {
				$out[ 'h' . $i ][] = trim( wp_strip_all_tags( $h ) );
			}
			$out['total'] += count( $m[1] );
		}
	}
	return $out;
}

function bcca_count_paragraphs( $html ) {
	$html = wpautop( (string) $html );
	preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $html, $m );
	$c = 0;
	if ( ! empty( $m[1] ) ) {
		foreach ( $m[1] as $p ) {
			if ( trim( wp_strip_all_tags( $p ) ) !== '' ) {
				$c++;
			}
		}
	}
	return $c;
}

function bcca_extract_chunks( $html ) {
	$html = wpautop( (string) $html );
	preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $html, $m );
	$chunks = array();
	$idx    = 0;

	if ( ! empty( $m[1] ) ) {
		foreach ( $m[1] as $p ) {
			$t = trim( wp_strip_all_tags( $p ) );
			$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
			if ( $t !== '' && mb_strlen( $t ) > 15 ) {
				$words = preg_split( '/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY );
				$chunks[] = array(
					'index'      => $idx,
					'text'       => $t,
					'word_count' => is_array( $words ) ? count( $words ) : 0,
					'char_count' => mb_strlen( $t ),
				);
				$idx++;
			}
		}
	}

	return $chunks;
}

/* ============================================================
   Topic selection rules
   ============================================================ */
function bcca_default_topic_for_post( $post ) {
	$meta = trim( (string) get_post_meta( $post->ID, '_ca_focus_phrase', true ) );
	if ( $meta !== '' ) {
		return $meta;
	}

	$headings = bcca_extract_headings( $post->post_content );
	if ( ! empty( $headings['h1'][0] ) ) {
		return trim( (string) $headings['h1'][0] );
	}

	return trim( (string) $post->post_title );
}

/* ============================================================
   Cache helpers
   ============================================================ */
function bcca_phrase_hash( $phrase ) {
	$phrase = trim( (string) $phrase );
	$phrase = mb_strtolower( $phrase );
	return hash( 'sha256', $phrase );
}

function bcca_cache_get( $post_id, $mode, $analysis_type, $phrase ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';

	$hash = bcca_phrase_hash( $phrase );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE post_id=%d AND mode=%s AND analysis_type=%s AND phrase_hash=%s
			 ORDER BY created_at DESC LIMIT 1",
			$post_id, $mode, $analysis_type, $hash
		),
		ARRAY_A
	);

	if ( ! $row ) {
		return null;
	}

	$data = json_decode( (string) $row['analysis_data'], true );
	if ( ! is_array( $data ) ) {
		return null;
	}

	return array(
		'created_at'  => $row['created_at'],
		'phrase_text' => $row['phrase_text'],
		'data'        => $data,
	);
}

function bcca_cache_save( $post_id, $mode, $analysis_type, $phrase, $data ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ca_analysis_cache';

	$hash  = bcca_phrase_hash( $phrase );
	$ptxt  = mb_substr( trim( (string) $phrase ), 0, 500 );
	$json  = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

	if ( ! $json ) {
		return false;
	}

	$ins = $wpdb->insert(
		$table,
		array(
			'post_id'       => (int) $post_id,
			'mode'          => (string) $mode,
			'analysis_type' => (string) $analysis_type,
			'phrase_hash'   => (string) $hash,
			'phrase_text'   => (string) $ptxt,
			'analysis_data' => (string) $json,
			'created_at'    => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return (bool) $ins;
}

/* ============================================================
   Server-side analysis helpers
   ============================================================ */
function bcca_stop_words() {
	return array(
		'i','w','na','z','do','nie','się','to','jest','że','o','jak','ale','za','co','od','po','tak','jej','jego',
		'ten','ta','tym','tego','tej','tych','był','była','było','były','być','może','ich','go','mu','mi','ci','nam',
		'was','im','ją','je','nas','ze','są','by','już','tylko','też','ma','czy','więc','dla','gdy','przed','przez',
		'przy','bez','pod','nad','między','ku','lub','albo','oraz','a','u','we','tu','tam','raz','no','ani','bo',
		'jako','sobie','który','która','które','których','którym','którą','czym','gdzie','kiedy','bardzo','będzie',
		'można','mnie','mają','każdy','inne','innych','jednak','jeszcze','teraz','zawsze','nigdy','często','czasem',
		'ponieważ','więcej','mniej','dużo','mało',
		'the','a','an','and','or','but','in','on','at','to','for','of','with','by','from','is','are','was','were','be',
		'been','being','have','has','had','do','does','did','will','would','shall','should','may','might','can','could',
		'this','that','these','those','it','its','he','she','they','we','you','me','him','her','us','them','my','your',
		'his','our','their','not','no','so','if','then','than','too','very','just','about','up','out','all','also'
	);
}

function bcca_tf_vector( $text ) {
	$text  = mb_strtolower( (string) $text );
	$words = preg_split( '/[^\p{L}\p{N}\-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$stop  = bcca_stop_words();

	$tf    = array();
	$total = 0;

	if ( is_array( $words ) ) {
		foreach ( $words as $w ) {
			$w = trim( $w, '-' );
			if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			if ( ! isset( $tf[ $w ] ) ) {
				$tf[ $w ] = 0;
			}
			$tf[ $w ]++;
			$total++;
		}
	}

	if ( $total > 0 ) {
		foreach ( $tf as $t => $c ) {
			$tf[ $t ] = $c / $total;
		}
	}

	return $tf;
}

function bcca_cosine( $a, $b ) {
	$terms = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
	$dot = 0.0; $ma = 0.0; $mb = 0.0;

	foreach ( $terms as $t ) {
		$x = $a[ $t ] ?? 0.0;
		$y = $b[ $t ] ?? 0.0;
		$dot += $x * $y;
		$ma  += $x * $x;
		$mb  += $y * $y;
	}

	$ma = sqrt( $ma );
	$mb = sqrt( $mb );

	if ( $ma == 0.0 || $mb == 0.0 ) {
		return 0.0;
	}

	return $dot / ( $ma * $mb );
}

function bcca_context_vector( $all_words, $target, $window = 5 ) {
	$stop = bcca_stop_words();
	$ctx  = array();
	$total = 0;

	$positions = array();
	foreach ( $all_words as $i => $w ) {
		$w = trim( $w, '-' );
		if ( $w === $target ) {
			$positions[] = $i;
		}
	}

	foreach ( $positions as $p ) {
		$start = max( 0, $p - $window );
		$end   = min( count( $all_words ) - 1, $p + $window );
		for ( $j = $start; $j <= $end; $j++ ) {
			if ( $j === $p ) {
				continue;
			}
			$w = trim( $all_words[ $j ], '-' );
			if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			if ( ! isset( $ctx[ $w ] ) ) {
				$ctx[ $w ] = 0;
			}
			$ctx[ $w ]++;
			$total++;
		}
		if ( $total > 1500 ) {
			break;
		}
	}

	if ( $total > 0 ) {
		foreach ( $ctx as $t => $c ) {
			$ctx[ $t ] = $c / $total;
		}
	}

	return $ctx;
}

/* ============================================================
   AJAX: List data
   ============================================================ */
add_action( 'wp_ajax_bcca_get_posts_list', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$out = array();

	foreach ( $posts as $p ) {
		$text    = bcca_plain_text_from_post( $p );
		$words   = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$wcount  = is_array( $words ) ? count( $words ) : 0;
		$ccount  = mb_strlen( $text );
		$head    = bcca_extract_headings( $p->post_content );

		$cats = array();
		if ( $p->post_type === 'post' ) {
			$c = get_the_category( $p->ID );
			if ( $c ) {
				foreach ( $c as $cat ) {
					$cats[] = $cat->name;
				}
			}
		}

		$out[] = array(
			'post_id'        => $p->ID,
			'title'          => $p->post_title,
			'post_type'      => $p->post_type,
			'post_status'    => $p->post_status,
			'url'            => get_permalink( $p->ID ),
			'edit_url'       => get_edit_post_link( $p->ID, 'raw' ),
			'date_published' => $p->post_date,
			'date_modified'  => $p->post_modified,
			'categories'     => $cats,
			'word_count'     => $wcount,
			'char_count'     => $ccount,
			'paragraph_count'=> bcca_count_paragraphs( $p->post_content ),
			'headings'       => array(
				'h1' => count( $head['h1'] ?? array() ),
				'h2' => count( $head['h2'] ?? array() ),
				'h3' => count( $head['h3'] ?? array() ),
				'h4' => count( $head['h4'] ?? array() ),
				'h5' => count( $head['h5'] ?? array() ),
				'h6' => count( $head['h6'] ?? array() ),
				'total' => (int) ( $head['total'] ?? 0 ),
			),
		);
	}

	wp_send_json_success( $out );
} );

/* ============================================================
   AJAX: Report base data
   ============================================================ */
add_action( 'wp_ajax_bcca_get_post_report', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$post    = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( 'Not found' );
	}

	$text   = bcca_plain_text_from_post( $post );
	$words  = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$wcount = is_array( $words ) ? count( $words ) : 0;

	$head = bcca_extract_headings( $post->post_content );

	$cats = array();
	if ( $post->post_type === 'post' ) {
		$c = get_the_category( $post_id );
		if ( $c ) {
			foreach ( $c as $cat ) {
				$cats[] = $cat->name;
			}
		}
	}

	$default_topic = bcca_default_topic_for_post( $post );

	wp_send_json_success( array(
		'post_id'        => $post_id,
		'title'          => $post->post_title,
		'post_type'      => $post->post_type,
		'post_status'    => $post->post_status,
		'url'            => get_permalink( $post_id ),
		'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
		'date_published' => $post->post_date,
		'date_modified'  => $post->post_modified,
		'categories'     => $cats,
		'word_count'     => $wcount,
		'char_count'     => mb_strlen( $text ),
		'paragraph_count'=> bcca_count_paragraphs( $post->post_content ),
		'headings'       => $head,
		'default_topic'  => $default_topic,
		'meta_topic'     => (string) get_post_meta( $post_id, '_ca_focus_phrase', true ),
	) );
} );

/* ============================================================
   AJAX: Cache fetch (word/chunk)
   ============================================================ */
add_action( 'wp_ajax_bcca_get_cached_analysis', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$mode    = sanitize_text_field( $_POST['mode'] ?? 'server' );
	$type    = sanitize_text_field( $_POST['analysis_type'] ?? 'word' );
	$phrase  = sanitize_text_field( $_POST['phrase'] ?? '' );

	if ( ! $post_id || ! in_array( $mode, array( 'server', 'browser' ), true ) || ! in_array( $type, array( 'word', 'chunk' ), true ) || $phrase === '' ) {
		wp_send_json_error( 'Missing data' );
	}

	$cached = bcca_cache_get( $post_id, $mode, $type, $phrase );
	if ( ! $cached ) {
		wp_send_json_success( array( 'found' => false ) );
	}

	wp_send_json_success( array(
		'found'      => true,
		'created_at' => $cached['created_at'],
		'phrase'     => $cached['phrase_text'],
		'data'       => $cached['data'],
	) );
} );

/* ============================================================
   AJAX: Cache save (browser results)
   ============================================================ */
add_action( 'wp_ajax_bcca_save_cached_analysis', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$mode    = sanitize_text_field( $_POST['mode'] ?? 'browser' );
	$type    = sanitize_text_field( $_POST['analysis_type'] ?? 'word' );
	$phrase  = sanitize_text_field( $_POST['phrase'] ?? '' );
	$data    = isset( $_POST['data'] ) ? json_decode( wp_unslash( (string) $_POST['data'] ), true ) : null;

	if ( ! $post_id || ! in_array( $mode, array( 'server', 'browser' ), true ) || ! in_array( $type, array( 'word', 'chunk' ), true ) || $phrase === '' || ! is_array( $data ) ) {
		wp_send_json_error( 'Missing data' );
	}

	$ok = bcca_cache_save( $post_id, $mode, $type, $phrase, $data );
	if ( ! $ok ) {
		wp_send_json_error( 'Save failed' );
	}

	wp_send_json_success( array( 'saved' => true ) );
} );

/* ============================================================
   AJAX: Run server word analysis (cached unless forced)
   ============================================================ */
add_action( 'wp_ajax_bcca_run_word_analysis', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$phrase  = sanitize_text_field( $_POST['phrase'] ?? '' );
	$force   = ! empty( $_POST['force'] );

	if ( ! $post_id || $phrase === '' ) {
		wp_send_json_error( 'Missing data' );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( 'Not found' );
	}

	$opts = bcca_get_settings();
	$mode = ( $opts['mode'] === 'browser' ) ? 'browser' : 'server';

	// Browser mode -> return text payload
	if ( $mode === 'browser' ) {
		$text = bcca_plain_text_from_post( $post );
		wp_send_json_success( array(
			'mode'   => 'browser',
			'phrase' => $phrase,
			'text'   => $text,
		) );
	}

	// Server cache
	if ( ! $force ) {
		$cached = bcca_cache_get( $post_id, 'server', 'word', $phrase );
		if ( $cached ) {
			wp_send_json_success( array(
				'mode'       => 'server',
				'cached'     => true,
				'created_at' => $cached['created_at'],
				'phrase'     => $cached['phrase_text'],
				'data'       => $cached['data'],
			) );
		}
	}

	$text = bcca_plain_text_from_post( $post );
	$topic_vec = bcca_tf_vector( $phrase );

	// lightweight bigram boost from phrase
	$pl = mb_strtolower( $phrase );
	$pw = preg_split( '/[^\p{L}\p{N}\-]+/u', $pl, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $pw ) && count( $pw ) >= 2 ) {
		for ( $i = 0; $i < count( $pw ) - 1; $i++ ) {
			$bg = trim( $pw[ $i ], '-' ) . ' ' . trim( $pw[ $i + 1 ], '-' );
			if ( mb_strlen( $bg ) >= 5 ) {
				$topic_vec[ $bg ] = ( $topic_vec[ $bg ] ?? 0 ) + 0.35;
			}
		}
	}
	$topic_terms = array_keys( $topic_vec );

	$lower = mb_strtolower( $text );
	$all_words = preg_split( '/[^\p{L}\p{N}\-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $all_words ) ) {
		$all_words = array();
	}

	$stop = bcca_stop_words();

	$freq = array();
	foreach ( $all_words as $w ) {
		$w = trim( $w, '-' );
		if ( mb_strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
			continue;
		}
		$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
	}
	arsort( $freq );
	$freq = array_slice( $freq, 0, 600, true );

	$words_scored = array();
	foreach ( $freq as $word => $count ) {
		$direct = in_array( $word, $topic_terms, true ) ? 1.0 : 0.0;
		$ctx    = bcca_context_vector( $all_words, $word, 5 );
		$cs     = bcca_cosine( $topic_vec, $ctx );

		$score  = min( 1.0, ( $direct * 0.55 ) + ( $cs * 0.45 ) );

		$words_scored[] = array(
			'word'            => $word,
			'count'           => $count,
			'direct_match'    => ( $direct > 0 ),
			'context_score'   => round( $cs * 100, 1 ),
			'relevance_score' => round( $score * 100, 1 ),
		);
	}

	usort( $words_scored, function ( $a, $b ) {
		return ( $b['relevance_score'] <=> $a['relevance_score'] );
	} );

	$overall = bcca_cosine( $topic_vec, bcca_tf_vector( $text ) );

	$tw = count( $words_scored );
	$hi = count( array_filter( $words_scored, function ( $w ) { return $w['relevance_score'] >= 40; } ) );
	$md = count( array_filter( $words_scored, function ( $w ) { return $w['relevance_score'] >= 15 && $w['relevance_score'] < 40; } ) );
	$lo = count( array_filter( $words_scored, function ( $w ) { return $w['relevance_score'] < 15; } ) );
	$av = $tw ? round( array_sum( array_column( $words_scored, 'relevance_score' ) ) / $tw, 1 ) : 0;

	$data = array(
		'phrase'             => $phrase,
		'overall_similarity' => round( $overall * 100, 1 ),
		'total_unique_words' => $tw,
		'high_relevance'     => $hi,
		'medium_relevance'   => $md,
		'low_relevance'      => $lo,
		'average_relevance'  => $av,
		'words'              => array_slice( $words_scored, 0, 200 ),
	);

	bcca_cache_save( $post_id, 'server', 'word', $phrase, $data );

	wp_send_json_success( array(
		'mode'       => 'server',
		'cached'     => false,
		'created_at' => current_time( 'mysql' ),
		'phrase'     => $phrase,
		'data'       => $data,
	) );
} );

/* ============================================================
   AJAX: Run server chunk analysis (cached unless forced)
   ============================================================ */
add_action( 'wp_ajax_bcca_run_chunk_analysis', function () {
	check_ajax_referer( 'bcca_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$phrase  = sanitize_text_field( $_POST['phrase'] ?? '' );
	$force   = ! empty( $_POST['force'] );

	if ( ! $post_id || $phrase === '' ) {
		wp_send_json_error( 'Missing data' );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( 'Not found' );
	}

	$opts = bcca_get_settings();
	$mode = ( $opts['mode'] === 'browser' ) ? 'browser' : 'server';

	$chunks = bcca_extract_chunks( $post->post_content );

	// Browser mode: return chunks payload
	if ( $mode === 'browser' ) {
		wp_send_json_success( array(
			'mode'   => 'browser',
			'phrase' => $phrase,
			'chunks' => $chunks,
		) );
	}

	// Server cache
	if ( ! $force ) {
		$cached = bcca_cache_get( $post_id, 'server', 'chunk', $phrase );
		if ( $cached ) {
			wp_send_json_success( array(
				'mode'       => 'server',
				'cached'     => true,
				'created_at' => $cached['created_at'],
				'phrase'     => $cached['phrase_text'],
				'data'       => $cached['data'],
			) );
		}
	}

	$topic = bcca_tf_vector( $phrase );

	$pl = mb_strtolower( $phrase );
	$pw = preg_split( '/[^\p{L}\p{N}\-]+/u', $pl, -1, PREG_SPLIT_NO_EMPTY );
	if ( is_array( $pw ) && count( $pw ) >= 2 ) {
		for ( $i = 0; $i < count( $pw ) - 1; $i++ ) {
			$bg = trim( $pw[ $i ], '-' ) . ' ' . trim( $pw[ $i + 1 ], '-' );
			if ( mb_strlen( $bg ) >= 5 ) {
				$topic[ $bg ] = ( $topic[ $bg ] ?? 0 ) + 0.35;
			}
		}
	}

	$res = array();
	foreach ( $chunks as $ch ) {
		$vec = bcca_tf_vector( $ch['text'] ?? '' );
		$sim = bcca_cosine( $topic, $vec );

		$res[] = array(
			'index'              => $ch['index'],
			'text'               => $ch['text'],
			'word_count'         => $ch['word_count'],
			'similarity'         => round( $sim, 4 ),
			'similarity_percent' => round( $sim * 100, 1 ),
		);
	}

	usort( $res, function ( $a, $b ) {
		return ( $b['similarity'] <=> $a['similarity'] );
	} );

	$avg = count( $res ) ? array_sum( array_column( $res, 'similarity' ) ) / count( $res ) : 0;
	$mx  = count( $res ) ? max( array_column( $res, 'similarity_percent' ) ) : 0;
	$mn  = count( $res ) ? min( array_column( $res, 'similarity_percent' ) ) : 0;

	$data = array(
		'phrase'             => $phrase,
		'chunks'             => array_slice( $res, 0, 120 ),
		'chunk_count'        => count( $res ),
		'average_similarity' => round( $avg, 4 ),
		'average_percent'    => round( $avg * 100, 1 ),
		'max_percent'        => $mx,
		'min_percent'        => $mn,
	);

	bcca_cache_save( $post_id, 'server', 'chunk', $phrase, $data );

	wp_send_json_success( array(
		'mode'       => 'server',
		'cached'     => false,
		'created_at' => current_time( 'mysql' ),
		'phrase'     => $phrase,
		'data'       => $data,
	) );
} );

/* ============================================================
   LIST PAGE (sortable, URL+Chars+headings summary)
   ============================================================ */
function bcca_render_list_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	$opts = bcca_get_settings();
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:10px;">
			<span class="dashicons dashicons-chart-bar" style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
			<?php echo esc_html__( 'Content Analyzer', 'beeclear-content-analyzer' ); ?>
		</h1>

		<p class="description">
			<?php echo esc_html__( 'Click a row to open the report for that page/post. Sorting is available on most columns.', 'beeclear-content-analyzer' ); ?>
			<br/>
			<?php echo esc_html__( 'Current mode:', 'beeclear-content-analyzer' ); ?>
			<strong><?php echo esc_html( $opts['mode'] ); ?></strong>
		</p>

		<style>
			.bcca-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:14px 16px;margin:14px 0;}
			.bcca-table-wrap{overflow:auto}
			.bcca-table{width:100%;border-collapse:collapse}
			.bcca-table thead th{background:#f0f0f1;border-bottom:2px solid #c3c4c7;padding:10px;white-space:nowrap;font-size:11px;text-transform:uppercase;letter-spacing:.35px;color:#50575e}
			.bcca-table tbody td{border-bottom:1px solid #e0e0e0;padding:10px;vertical-align:top}
			.bcca-table tbody tr:hover{background:#f6f7f7}
			.bcca-title{font-weight:700;color:#2271b1;text-decoration:none}
			.bcca-title:hover{text-decoration:underline}
			.bcca-url{font-size:12px;color:#50575e;word-break:break-all}
			.bcca-badge{display:inline-flex;align-items:center;gap:6px;border:1px solid #e0e0e0;background:#f6f7f7;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 4px 2px 0}
			.bcca-actions a{margin-right:10px;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
			.bcca-actions a:hover{text-decoration:underline}
			.bcca-sort{cursor:pointer;user-select:none}
			.bcca-sort .ico{opacity:.45;font-size:10px;margin-left:4px}
			.bcca-sort.asc .ico{opacity:1}
			.bcca-sort.desc .ico{opacity:1}
			.bcca-muted{color:#646970;font-size:12px}
			.bcca-loading{display:flex;align-items:center;gap:8px;color:#50575e}
		</style>

		<div class="bcca-card">
			<div class="bcca-loading" id="bcca-loading" style="display:none;">
				<span class="spinner is-active" style="float:none;margin:0;"></span>
				<?php echo esc_html__( 'Loading...', 'beeclear-content-analyzer' ); ?>
			</div>

			<div class="bcca-table-wrap">
				<table class="bcca-table" id="bcca-table">
					<thead>
						<tr>
							<th class="bcca-sort" data-key="title"><?php echo esc_html__( 'Title / URL', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="post_type"><?php echo esc_html__( 'Type', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="post_status"><?php echo esc_html__( 'Status', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th><?php echo esc_html__( 'Categories', 'beeclear-content-analyzer' ); ?></th>
							<th class="bcca-sort" data-key="date_published"><?php echo esc_html__( 'Published', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="date_modified"><?php echo esc_html__( 'Updated', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="word_count"><?php echo esc_html__( 'Words', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="char_count"><?php echo esc_html__( 'Chars', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="headings_total"><?php echo esc_html__( 'Headings', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th class="bcca-sort" data-key="paragraph_count"><?php echo esc_html__( 'Paragraphs', 'beeclear-content-analyzer' ); ?> <span class="ico">↕</span></th>
							<th><?php echo esc_html__( 'Actions', 'beeclear-content-analyzer' ); ?></th>
						</tr>
					</thead>
					<tbody id="bcca-tbody">
						<tr><td colspan="11" class="bcca-muted"><?php echo esc_html__( 'No data yet.', 'beeclear-content-analyzer' ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		(function($){
			'use strict';
			function E(s){return String(s||'').replace(/[&<>"']/g,function(m){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]})}
			var rows=[], sort={key:'date_published', dir:'desc'};

			function getNum(v){v=parseFloat(v); return isNaN(v)?0:v;}
			function valOf(r,key){
				if(key==='headings_total'){ return getNum((r.headings && r.headings.total) ? r.headings.total : 0); }
				return r[key];
			}
			function sortRows(){
				var k=sort.key, d=sort.dir;
				rows.sort(function(a,b){
					var x=valOf(a,k), y=valOf(b,k);
					if(typeof x==='string'){ x=x.toLowerCase(); }
					if(typeof y==='string'){ y=y.toLowerCase(); }
					if(k==='word_count'||k==='char_count'||k==='paragraph_count'||k==='headings_total'){
						x=getNum(x); y=getNum(y);
					}
					if(x<y) return d==='asc'?-1:1;
					if(x>y) return d==='asc'?1:-1;
					return 0;
				});
			}

			function reportUrl(id){
				return '<?php echo esc_js( admin_url( 'admin.php?page=bcca-report&post_id=' ) ); ?>'+id;
			}

			function render(){
				sortRows();
				var $tb=$('#bcca-tbody'); $tb.empty();
				if(!rows.length){
					$tb.html('<tr><td colspan="11" class="bcca-muted"><?php echo esc_js( __( 'No items found.', 'beeclear-content-analyzer' ) ); ?></td></tr>');
					return;
				}
				rows.forEach(function(r){
					var cats=(r.categories||[]).map(function(c){return '<span class="bcca-badge">'+E(c)+'</span>'}).join(' ');
					if(!cats) cats='—';

					var h=r.headings||{h1:0,h2:0,h3:0,h4:0,h5:0,h6:0,total:0};
					var hs =
						'<span class="bcca-badge">H1: <strong>'+E(h.h1||0)+'</strong></span>'+
						'<span class="bcca-badge">H2: <strong>'+E(h.h2||0)+'</strong></span>'+
						'<span class="bcca-badge">H3: <strong>'+E(h.h3||0)+'</strong></span>'+
						'<span class="bcca-badge">H4: <strong>'+E(h.h4||0)+'</strong></span>'+
						'<span class="bcca-badge">H5: <strong>'+E(h.h5||0)+'</strong></span>'+
						'<span class="bcca-badge">H6: <strong>'+E(h.h6||0)+'</strong></span>';

					var url = r.url || '';
					var titleCell =
						'<a class="bcca-title" href="'+E(reportUrl(r.post_id))+'">'+E(r.title)+'</a>'+
						'<div class="bcca-url"><a href="'+E(url)+'" target="_blank" rel="noopener">'+E(url)+'</a></div>';

					var actions =
						'<div class="bcca-actions">'+
							'<a href="'+E(reportUrl(r.post_id))+'"><span class="dashicons dashicons-analytics"></span><?php echo esc_js( __( 'Report', 'beeclear-content-analyzer' ) ); ?></a>'+
							'<a href="'+E(r.edit_url||'#')+'"><span class="dashicons dashicons-edit"></span><?php echo esc_js( __( 'Edit', 'beeclear-content-analyzer' ) ); ?></a>'+
							'<a href="'+E(url)+'" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span><?php echo esc_js( __( 'View', 'beeclear-content-analyzer' ) ); ?></a>'+
						'</div>';

					var tr = '<tr data-id="'+E(r.post_id)+'" style="cursor:pointer;">'+
						'<td>'+titleCell+'</td>'+
						'<td>'+E(r.post_type)+'</td>'+
						'<td>'+E(r.post_status)+'</td>'+
						'<td>'+cats+'</td>'+
						'<td>'+E(String(r.date_published||'').slice(0,10))+'</td>'+
						'<td>'+E(String(r.date_modified||'').slice(0,10))+'</td>'+
						'<td><strong>'+E(r.word_count)+'</strong></td>'+
						'<td><strong>'+E(r.char_count)+'</strong></td>'+
						'<td>'+hs+'<div class="bcca-muted">Total: <strong>'+E(h.total||0)+'</strong></div></td>'+
						'<td>'+E(r.paragraph_count)+'</td>'+
						'<td>'+actions+'</td>'+
					'</tr>';

					$tb.append(tr);
				});
			}

			function load(){
				$('#bcca-loading').show();
				$.post(window.bccaData.ajaxUrl, {action:'bcca_get_posts_list', nonce:window.bccaData.nonce}, function(resp){
					$('#bcca-loading').hide();
					if(resp && resp.success && resp.data){
						rows = resp.data.map(function(r){
							r.headings_total = (r.headings && r.headings.total) ? r.headings.total : 0;
							return r;
						});
						render();
					}else{
						$('#bcca-tbody').html('<tr><td colspan="11" class="bcca-muted"><?php echo esc_js( __( 'Failed to load data.', 'beeclear-content-analyzer' ) ); ?></td></tr>');
					}
				});
			}

			$(document).on('click','#bcca-table tbody tr', function(e){
				if($(e.target).is('a') || $(e.target).closest('a').length){ return; }
				var id = $(this).data('id');
				if(id){ window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=bcca-report&post_id=' ) ); ?>'+id; }
			});

			$(document).on('click','.bcca-sort', function(){
				var key=$(this).data('key');
				if(!key) return;
				if(sort.key===key){ sort.dir = (sort.dir==='asc')?'desc':'asc'; }
				else { sort.key=key; sort.dir='asc'; }

				$('.bcca-sort').removeClass('asc desc');
				$(this).addClass(sort.dir);
				render();
			});

			$(function(){ load(); });
		})(jQuery);
		</script>
	</div>
	<?php
}

/* ============================================================
   REPORT PAGE (FULL WIDTH STACKED PANELS)
   ============================================================ */
function bcca_render_report_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
	}

	$post_id = absint( $_GET['post_id'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Content report', 'beeclear-content-analyzer' ) . '</h1><p>' . esc_html__( 'Post not found.', 'beeclear-content-analyzer' ) . '</p></div>';
		return;
	}

	$opts = bcca_get_settings();
	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:10px;">
			<span class="dashicons dashicons-analytics" style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
			<?php echo esc_html__( 'Content report', 'beeclear-content-analyzer' ); ?>
		</h1>

		<p class="description">
			<?php echo esc_html__( 'Panels are full-width. Analyses are cached per topic + mode. Change the topic to generate a new cache entry.', 'beeclear-content-analyzer' ); ?>
			<br/>
			<?php echo esc_html__( 'Current mode:', 'beeclear-content-analyzer' ); ?>
			<strong id="bcca-mode"><?php echo esc_html( $opts['mode'] ); ?></strong>
		</p>

		<style>
			.bcca-panel{width:100%;background:#fff;border:1px solid #c3c4c7;border-radius:10px;box-shadow:0 1px 1px rgba(0,0,0,.04);padding:14px 16px;margin:14px 0}
			.bcca-kpis{display:flex;flex-wrap:wrap;gap:8px}
			.bcca-pill{background:#f6f7f7;border:1px solid #e0e0e0;border-radius:999px;padding:6px 10px;font-size:12px}
			.bcca-badge{display:inline-flex;align-items:center;gap:6px;border:1px solid #e0e0e0;background:#f6f7f7;border-radius:999px;padding:2px 8px;font-size:12px;margin:2px 4px 2px 0}
			.bcca-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
			.bcca-row input[type="text"]{min-width:320px;height:36px;padding:4px 8px;border:1px solid #8c8f94;border-radius:6px}
			.bcca-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 12px;border-radius:6px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}
			.bcca-btn.secondary{background:#fff;color:#2271b1;border-color:#c3c4c7}
			.bcca-btn:disabled{opacity:.6;cursor:not-allowed}
			.bcca-muted{color:#646970;font-size:12px}
			.bcca-table{width:100%;border-collapse:collapse}
			.bcca-table th,.bcca-table td{border-bottom:1px solid #e0e0e0;padding:8px 10px;vertical-align:top}
			.bcca-table th{background:#f6f7f7;font-size:11px;text-transform:uppercase;letter-spacing:.35px;color:#50575e;white-space:nowrap}
			.bcca-score{display:flex;align-items:center;gap:12px;margin:10px 0}
			.bcca-circle{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff}
			.low{background:#d63638}.med{background:#dba617}.good{background:#2ea2cc}.great{background:#1d9b4d}
			.bcca-loading{display:flex;align-items:center;gap:8px;color:#50575e}
			.bcca-hr{border-top:1px solid #e0e0e0;margin:12px 0}
		</style>

		<div class="bcca-panel">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Page details', 'beeclear-content-analyzer' ); ?></h2>
			<div class="bcca-kpis" id="bcca-kpis">
				<span class="bcca-pill"><?php echo esc_html__( 'Loading...', 'beeclear-content-analyzer' ); ?></span>
			</div>
			<div class="bcca-hr"></div>
			<div>
				<div class="bcca-muted"><?php echo esc_html__( 'URL', 'beeclear-content-analyzer' ); ?></div>
				<div><a id="bcca-url" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $post_id ) ); ?></a></div>
			</div>
			<div class="bcca-hr"></div>
			<div class="bcca-muted"><?php echo esc_html__( 'Headings summary', 'beeclear-content-analyzer' ); ?></div>
			<div id="bcca-headings-summary"></div>
		</div>

		<div class="bcca-panel">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Topic & actions', 'beeclear-content-analyzer' ); ?></h2>

			<div class="bcca-row">
				<div>
					<div class="bcca-muted"><?php echo esc_html__( 'Topic (from metabox / H1 / title)', 'beeclear-content-analyzer' ); ?></div>
					<input type="text" id="bcca-topic" value="" />
					<div class="bcca-muted" id="bcca-topic-note"></div>
				</div>
				<button class="bcca-btn secondary" id="bcca-reset-topic"><span class="dashicons dashicons-update"></span><?php echo esc_html__( 'Reset to default', 'beeclear-content-analyzer' ); ?></button>
			</div>

			<div class="bcca-hr"></div>

			<div class="bcca-row">
				<button class="bcca-btn" id="bcca-run-word"><span class="dashicons dashicons-search"></span><?php echo esc_html__( 'Run word analysis', 'beeclear-content-analyzer' ); ?></button>
				<button class="bcca-btn secondary" id="bcca-run-word-force"><span class="dashicons dashicons-controls-repeat"></span><?php echo esc_html__( 'Re-run (ignore cache)', 'beeclear-content-analyzer' ); ?></button>
			</div>
			<div class="bcca-muted" id="bcca-word-cacheinfo"></div>

			<div class="bcca-hr"></div>

			<div class="bcca-row">
				<button class="bcca-btn" id="bcca-run-chunk"><span class="dashicons dashicons-search"></span><?php echo esc_html__( 'Run chunk analysis', 'beeclear-content-analyzer' ); ?></button>
				<button class="bcca-btn secondary" id="bcca-run-chunk-force"><span class="dashicons dashicons-controls-repeat"></span><?php echo esc_html__( 'Re-run (ignore cache)', 'beeclear-content-analyzer' ); ?></button>
			</div>
			<div class="bcca-muted" id="bcca-chunk-cacheinfo"></div>
		</div>

		<div class="bcca-panel">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Headings structure', 'beeclear-content-analyzer' ); ?></h2>
			<div id="bcca-headings-structure" class="bcca-muted"><?php echo esc_html__( 'Loading...', 'beeclear-content-analyzer' ); ?></div>
		</div>

		<div class="bcca-panel">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Word analysis results', 'beeclear-content-analyzer' ); ?></h2>
			<div id="bcca-word-results" class="bcca-muted"><?php echo esc_html__( 'Run analysis to see results.', 'beeclear-content-analyzer' ); ?></div>
		</div>

		<div class="bcca-panel">
			<h2 style="margin-top:0;"><?php echo esc_html__( 'Chunk analysis results', 'beeclear-content-analyzer' ); ?></h2>
			<div id="bcca-chunk-results" class="bcca-muted"><?php echo esc_html__( 'Run analysis to see results.', 'beeclear-content-analyzer' ); ?></div>
		</div>

		<script>
		(function($){
			'use strict';
			function E(s){return String(s||'').replace(/[&<>"']/g,function(m){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]})}

			var POST_ID = <?php echo (int) $post_id; ?>;
			var MODE = (window.bccaData && window.bccaData.mode) ? window.bccaData.mode : 'server';

			var report = null;
			var defaultTopic = '';
			var topicSourceNote = '';

			// Browser mode semantic vectors
			function tok(s){
				s=(s||'').toLowerCase();
				return s.split(/[^0-9a-ząćęłńóśżź\-]+/i).map(function(x){return x.replace(/^\-+|\-+$/g,'');}).filter(function(x){return x.length>=3;});
			}
			function hash32(str){
				var h=0x811c9dc5;
				for(var i=0;i<str.length;i++){
					h ^= str.charCodeAt(i);
					h = (h + ((h<<1)+(h<<4)+(h<<7)+(h<<8)+(h<<24))) >>> 0;
				}
				return h>>>0;
			}
			function vectorize(text, dim){
				dim = dim || 1024;
				var v = new Array(dim);
				for(var i=0;i<dim;i++) v[i]=0;
				var t=(text||'').toLowerCase();
				var w = tok(t);
				for(var i=0;i<w.length;i++){
					var term=w[i];
					v[hash32('w:'+term)%dim] += 1;
					if(i<w.length-1){
						var bg = term+' '+w[i+1];
						v[hash32('b:'+bg)%dim] += 0.35;
					}
				}
				var s=t.replace(/\s+/g,' ');
				for(var j=0;j<s.length-3;j++){
					var g=s.substr(j,4);
					if(g.trim().length<4) continue;
					v[hash32('c:'+g)%dim] += 0.08;
				}
				return v;
			}
			function cos(a,b){
				var dot=0,ma=0,mb=0,n=Math.min(a.length,b.length);
				for(var i=0;i<n;i++){
					var x=a[i]||0, y=b[i]||0;
					dot+=x*y; ma+=x*x; mb+=y*y;
				}
				ma=Math.sqrt(ma); mb=Math.sqrt(mb);
				if(!ma||!mb) return 0;
				return dot/(ma*mb);
			}
			function browserWordAnalysis(text, phrase){
				var dim=1024;
				var topicVec = vectorize(phrase, dim);
				var fullVec  = vectorize(text, dim);
				var overall  = cos(topicVec, fullVec);

				var words = tok(text);
				var freq = {};
				for(var i=0;i<words.length;i++){ freq[words[i]]=(freq[words[i]]||0)+1; }
				var entries = Object.keys(freq).map(function(k){return {w:k,c:freq[k]};});
				entries.sort(function(a,b){return b.c-a.c;});
				entries = entries.slice(0,600);

				var pset = {};
				tok(phrase).forEach(function(w){pset[w]=1;});

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
					var ctxVec=vectorize(ctx.join(' '), dim);
					var cs=cos(topicVec, ctxVec);
					var score=Math.min(1,(dm*0.55)+(cs*0.45));

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
					if(rs>=40) hi++; else if(rs>=15) md++; else lo++;
				}
				var avg = out.length ? Math.round((sum/out.length)*10)/10 : 0;

				return {
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
			function browserChunkAnalysis(chunks, phrase){
				var dim=1024;
				var topicVec=vectorize(phrase, dim);
				var res=[];
				for(var i=0;i<chunks.length;i++){
					var ch=chunks[i];
					var v=vectorize(ch.text||'', dim);
					var s=cos(topicVec, v);
					res.push({
						index: ch.index,
						text: ch.text,
						word_count: ch.word_count,
						similarity: s,
						similarity_percent: Math.round(s*1000)/10
					});
				}
				res.sort(function(a,b){return b.similarity-a.similarity;});
				var sum=0, mx=0, mn=100;
				for(var i=0;i<res.length;i++){
					sum += res[i].similarity;
					mx = Math.max(mx, res[i].similarity_percent);
					mn = Math.min(mn, res[i].similarity_percent);
				}
				var avg = res.length ? (sum/res.length) : 0;
				return {
					phrase: phrase,
					chunks: res.slice(0,120),
					chunk_count: res.length,
					average_similarity: Math.round(avg*10000)/10000,
					average_percent: Math.round(avg*1000)/10,
					max_percent: mx,
					min_percent: (mn===100?0:mn)
				};
			}

			function scoreClass(p){
				if(p<15) return 'low';
				if(p<30) return 'med';
				if(p<50) return 'good';
				return 'great';
			}

			function setLoading($el, msg){
				$el.html('<div class="bcca-loading"><span class="spinner is-active" style="float:none;margin:0;"></span>'+E(msg)+'</div>');
			}

			function renderHeadingsSummary(h){
				h = h||{h1:[],h2:[],h3:[],h4:[],h5:[],h6:[],total:0};
				var counts = {
					h1:(h.h1||[]).length, h2:(h.h2||[]).length, h3:(h.h3||[]).length,
					h4:(h.h4||[]).length, h5:(h.h5||[]).length, h6:(h.h6||[]).length,
					total: (h.total||0)
				};
				var html =
					'<span class="bcca-badge">H1: <strong>'+counts.h1+'</strong></span>'+
					'<span class="bcca-badge">H2: <strong>'+counts.h2+'</strong></span>'+
					'<span class="bcca-badge">H3: <strong>'+counts.h3+'</strong></span>'+
					'<span class="bcca-badge">H4: <strong>'+counts.h4+'</strong></span>'+
					'<span class="bcca-badge">H5: <strong>'+counts.h5+'</strong></span>'+
					'<span class="bcca-badge">H6: <strong>'+counts.h6+'</strong></span>'+
					'<div class="bcca-muted">Total: <strong>'+counts.total+'</strong></div>';
				$('#bcca-headings-summary').html(html);
			}

			function renderHeadingsStructure(h){
				h = h||{h1:[],h2:[],h3:[],h4:[],h5:[],h6:[]};
				var html='';
				['h1','h2','h3','h4','h5','h6'].forEach(function(k){
					var arr=h[k]||[];
					if(arr.length){
						html += '<div style="margin:6px 0;"><strong>'+k.toUpperCase()+'</strong>: '+arr.map(E).join(' | ')+'</div>';
					}
				});
				if(!html) html='<span class="bcca-muted"><?php echo esc_js( __( 'No headings found.', 'beeclear-content-analyzer' ) ); ?></span>';
				$('#bcca-headings-structure').html(html);
			}

			function renderKpis(r){
				var $k=$('#bcca-kpis'); $k.empty();
				$k.append('<span class="bcca-pill"><?php echo esc_js( __( 'Words', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(r.word_count)+'</strong></span>');
				$k.append('<span class="bcca-pill"><?php echo esc_js( __( 'Chars', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(r.char_count)+'</strong></span>');
				$k.append('<span class="bcca-pill"><?php echo esc_js( __( 'Paragraphs', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(r.paragraph_count)+'</strong></span>');
				$k.append('<span class="bcca-pill"><?php echo esc_js( __( 'Published', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(String(r.date_published||'').slice(0,10))+'</strong></span>');
				$k.append('<span class="bcca-pill"><?php echo esc_js( __( 'Updated', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(String(r.date_modified||'').slice(0,10))+'</strong></span>');
			}

			function fetchReport(){
				setLoading($('#bcca-kpis'), '<?php echo esc_js( __( 'Loading report...', 'beeclear-content-analyzer' ) ); ?>');
				$.post(window.bccaData.ajaxUrl, {action:'bcca_get_post_report', nonce:window.bccaData.nonce, post_id:POST_ID}, function(resp){
					if(resp && resp.success && resp.data){
						report = resp.data;
						$('#bcca-url').attr('href', report.url).text(report.url);
						renderKpis(report);
						renderHeadingsSummary(report.headings);
						renderHeadingsStructure(report.headings);

						defaultTopic = report.default_topic || '';
						var metaTopic = (report.meta_topic||'').trim();

						if(metaTopic){
							topicSourceNote = '<?php echo esc_js( __( 'Source: metabox (manual)', 'beeclear-content-analyzer' ) ); ?>';
						} else if(report.headings && report.headings.h1 && report.headings.h1.length){
							topicSourceNote = '<?php echo esc_js( __( 'Source: first H1', 'beeclear-content-analyzer' ) ); ?>';
						} else {
							topicSourceNote = '<?php echo esc_js( __( 'Source: title', 'beeclear-content-analyzer' ) ); ?>';
						}

						$('#bcca-topic').val(defaultTopic);
						$('#bcca-topic-note').text(topicSourceNote);

						refreshCacheInfo();
					} else {
						$('#bcca-kpis').html('<span class="bcca-muted"><?php echo esc_js( __( 'Failed to load report.', 'beeclear-content-analyzer' ) ); ?></span>');
					}
				});
			}

			function getTopic(){ return ($('#bcca-topic').val()||'').trim(); }

			function refreshCacheInfo(){
				var phrase=getTopic();
				if(!phrase){ $('#bcca-word-cacheinfo').text(''); $('#bcca-chunk-cacheinfo').text(''); return; }

				$.post(window.bccaData.ajaxUrl, {action:'bcca_get_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:MODE, analysis_type:'word', phrase:phrase}, function(r){
					if(r && r.success && r.data && r.data.found){
						$('#bcca-word-cacheinfo').text('<?php echo esc_js( __( 'Cached at:', 'beeclear-content-analyzer' ) ); ?> '+r.data.created_at+' — '+(r.data.phrase||phrase));
					} else {
						$('#bcca-word-cacheinfo').text('<?php echo esc_js( __( 'No cache for this topic yet.', 'beeclear-content-analyzer' ) ); ?>');
					}
				});
				$.post(window.bccaData.ajaxUrl, {action:'bcca_get_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:MODE, analysis_type:'chunk', phrase:phrase}, function(r){
					if(r && r.success && r.data && r.data.found){
						$('#bcca-chunk-cacheinfo').text('<?php echo esc_js( __( 'Cached at:', 'beeclear-content-analyzer' ) ); ?> '+r.data.created_at+' — '+(r.data.phrase||phrase));
					} else {
						$('#bcca-chunk-cacheinfo').text('<?php echo esc_js( __( 'No cache for this topic yet.', 'beeclear-content-analyzer' ) ); ?>');
					}
				});
			}

			function renderWordResult(payload, createdAt, cached){
				var d = payload.data || payload;
				var pct = d.overall_similarity || 0;
				var cls = scoreClass(pct);
				var html = '';
				html += '<div class="bcca-score"><div class="bcca-circle '+cls+'">'+E(pct)+'%</div><div>';
				html += '<div><strong><?php echo esc_js( __( 'Overall similarity', 'beeclear-content-analyzer' ) ); ?></strong></div>';
				html += '<div class="bcca-muted"><?php echo esc_js( __( 'Unique words analyzed', 'beeclear-content-analyzer' ) ); ?>: '+E(d.total_unique_words||0)+
					' | <?php echo esc_js( __( 'High', 'beeclear-content-analyzer' ) ); ?>: '+E(d.high_relevance||0)+
					' | <?php echo esc_js( __( 'Medium', 'beeclear-content-analyzer' ) ); ?>: '+E(d.medium_relevance||0)+
					' | <?php echo esc_js( __( 'Low', 'beeclear-content-analyzer' ) ); ?>: '+E(d.low_relevance||0)+
					' | <?php echo esc_js( __( 'Avg', 'beeclear-content-analyzer' ) ); ?>: '+E(d.average_relevance||0)+'%</div>';
				if(createdAt){
					html += '<div class="bcca-muted">'+(cached?'<?php echo esc_js( __( 'Loaded from cache:', 'beeclear-content-analyzer' ) ); ?>':'<?php echo esc_js( __( 'Generated at:', 'beeclear-content-analyzer' ) ); ?>')+' '+E(createdAt)+'</div>';
				}
				html += '</div></div>';

				var words = d.words || [];
				if(!words.length){
					html += '<div class="bcca-muted"><?php echo esc_js( __( 'No words to show.', 'beeclear-content-analyzer' ) ); ?></div>';
					$('#bcca-word-results').html(html);
					return;
				}
				html += '<table class="bcca-table"><thead><tr>'+
					'<th><?php echo esc_js( __( 'Word', 'beeclear-content-analyzer' ) ); ?></th>'+
					'<th><?php echo esc_js( __( 'Count', 'beeclear-content-analyzer' ) ); ?></th>'+
					'<th><?php echo esc_js( __( 'Direct', 'beeclear-content-analyzer' ) ); ?></th>'+
					'<th><?php echo esc_js( __( 'Context', 'beeclear-content-analyzer' ) ); ?></th>'+
					'<th><?php echo esc_js( __( 'Relevance', 'beeclear-content-analyzer' ) ); ?></th>'+
				'</tr></thead><tbody>';
				words.forEach(function(w){
					html += '<tr>'+
						'<td>'+E(w.word)+'</td>'+
						'<td>'+E(w.count)+'</td>'+
						'<td>'+(w.direct_match?'<span class="bcca-badge"><?php echo esc_js( __( 'yes', 'beeclear-content-analyzer' ) ); ?></span>':'—')+'</td>'+
						'<td>'+E(w.context_score)+'%</td>'+
						'<td><strong>'+E(w.relevance_score)+'%</strong></td>'+
					'</tr>';
				});
				html += '</tbody></table>';
				$('#bcca-word-results').html(html);
			}

			function renderChunkResult(payload, createdAt, cached){
				var d = payload.data || payload;
				var html = '';
				html += '<div class="bcca-muted"><?php echo esc_js( __( 'Chunks', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(d.chunk_count||0)+'</strong>'+
					' | <?php echo esc_js( __( 'Average', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(d.average_percent||0)+'%</strong>'+
					' | <?php echo esc_js( __( 'Max', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(d.max_percent||0)+'%</strong>'+
					' | <?php echo esc_js( __( 'Min', 'beeclear-content-analyzer' ) ); ?>: <strong>'+E(d.min_percent||0)+'%</strong></div>';
				if(createdAt){
					html += '<div class="bcca-muted" style="margin-top:6px;">'+(cached?'<?php echo esc_js( __( 'Loaded from cache:', 'beeclear-content-analyzer' ) ); ?>':'<?php echo esc_js( __( 'Generated at:', 'beeclear-content-analyzer' ) ); ?>')+' '+E(createdAt)+'</div>';
				}

				var chunks = d.chunks || [];
				if(!chunks.length){
					html += '<div class="bcca-muted"><?php echo esc_js( __( 'No chunks to show.', 'beeclear-content-analyzer' ) ); ?></div>';
					$('#bcca-chunk-results').html(html);
					return;
				}
				html += '<table class="bcca-table"><thead><tr>'+
					'<th>#</th><th><?php echo esc_js( __( 'Similarity', 'beeclear-content-analyzer' ) ); ?></th><th><?php echo esc_js( __( 'Words', 'beeclear-content-analyzer' ) ); ?></th><th><?php echo esc_js( __( 'Text', 'beeclear-content-analyzer' ) ); ?></th>'+
				'</tr></thead><tbody>';
				chunks.forEach(function(ch){
					var txt = String(ch.text||'');
					var short = txt.length>280 ? txt.slice(0,280)+'…' : txt;
					html += '<tr>'+
						'<td>'+E(ch.index)+'</td>'+
						'<td><strong>'+E(ch.similarity_percent)+'%</strong></td>'+
						'<td>'+E(ch.word_count)+'</td>'+
						'<td>'+E(short)+'</td>'+
					'</tr>';
				});
				html += '</tbody></table>';
				$('#bcca-chunk-results').html(html);
			}

			function runWord(force){
				var phrase=getTopic();
				if(!phrase){ alert('<?php echo esc_js( __( 'Please set a topic first.', 'beeclear-content-analyzer' ) ); ?>'); return; }
				setLoading($('#bcca-word-results'), '<?php echo esc_js( __( 'Analyzing...', 'beeclear-content-analyzer' ) ); ?>');

				if(!force){
					$.post(window.bccaData.ajaxUrl, {action:'bcca_get_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:MODE, analysis_type:'word', phrase:phrase}, function(r){
						if(r && r.success && r.data && r.data.found){
							renderWordResult(r.data.data, r.data.created_at, true);
							refreshCacheInfo();
						} else {
							runWordCompute(force);
						}
					});
				} else {
					runWordCompute(force);
				}
			}

			function runWordCompute(force){
				var phrase=getTopic();
				$.post(window.bccaData.ajaxUrl, {action:'bcca_run_word_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, phrase:phrase, force: force ? 1 : 0}, function(resp){
					if(resp && resp.success && resp.data){
						if(resp.data.mode==='browser'){
							try{
								var computed = browserWordAnalysis(resp.data.text||'', phrase);
								renderWordResult(computed, new Date().toISOString().slice(0,19).replace('T',' '), false);
								$.post(window.bccaData.ajaxUrl, {action:'bcca_save_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:'browser', analysis_type:'word', phrase:phrase, data: JSON.stringify(computed)}, function(){ refreshCacheInfo(); });
							}catch(e){
								$('#bcca-word-results').html('<div class="bcca-muted"><?php echo esc_js( __( 'Browser analysis failed.', 'beeclear-content-analyzer' ) ); ?></div>');
							}
						} else {
							renderWordResult(resp.data.data, resp.data.created_at, !!resp.data.cached);
							refreshCacheInfo();
						}
					} else {
						$('#bcca-word-results').html('<div class="bcca-muted"><?php echo esc_js( __( 'Analysis failed.', 'beeclear-content-analyzer' ) ); ?></div>');
					}
				});
			}

			function runChunk(force){
				var phrase=getTopic();
				if(!phrase){ alert('<?php echo esc_js( __( 'Please set a topic first.', 'beeclear-content-analyzer' ) ); ?>'); return; }
				setLoading($('#bcca-chunk-results'), '<?php echo esc_js( __( 'Analyzing...', 'beeclear-content-analyzer' ) ); ?>');

				if(!force){
					$.post(window.bccaData.ajaxUrl, {action:'bcca_get_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:MODE, analysis_type:'chunk', phrase:phrase}, function(r){
						if(r && r.success && r.data && r.data.found){
							renderChunkResult(r.data.data, r.data.created_at, true);
							refreshCacheInfo();
						} else {
							runChunkCompute(force);
						}
					});
				} else {
					runChunkCompute(force);
				}
			}

			function runChunkCompute(force){
				var phrase=getTopic();
				$.post(window.bccaData.ajaxUrl, {action:'bcca_run_chunk_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, phrase:phrase, force: force ? 1 : 0}, function(resp){
					if(resp && resp.success && resp.data){
						if(resp.data.mode==='browser'){
							try{
								var computed = browserChunkAnalysis(resp.data.chunks||[], phrase);
								renderChunkResult(computed, new Date().toISOString().slice(0,19).replace('T',' '), false);
								$.post(window.bccaData.ajaxUrl, {action:'bcca_save_cached_analysis', nonce:window.bccaData.nonce, post_id:POST_ID, mode:'browser', analysis_type:'chunk', phrase:phrase, data: JSON.stringify(computed)}, function(){ refreshCacheInfo(); });
							}catch(e){
								$('#bcca-chunk-results').html('<div class="bcca-muted"><?php echo esc_js( __( 'Browser analysis failed.', 'beeclear-content-analyzer' ) ); ?></div>');
							}
						} else {
							renderChunkResult(resp.data.data, resp.data.created_at, !!resp.data.cached);
							refreshCacheInfo();
						}
					} else {
						$('#bcca-chunk-results').html('<div class="bcca-muted"><?php echo esc_js( __( 'Analysis failed.', 'beeclear-content-analyzer' ) ); ?></div>');
					}
				});
			}

			$(function(){
				fetchReport();

				$('#bcca-reset-topic').on('click', function(e){
					e.preventDefault();
					$('#bcca-topic').val(defaultTopic);
					$('#bcca-topic-note').text(topicSourceNote);
					refreshCacheInfo();
				});
				$('#bcca-topic').on('change keyup', function(){ refreshCacheInfo(); });

				$('#bcca-run-word').on('click', function(e){ e.preventDefault(); runWord(false); });
				$('#bcca-run-word-force').on('click', function(e){ e.preventDefault(); runWord(true); });

				$('#bcca-run-chunk').on('click', function(e){ e.preventDefault(); runChunk(false); });
				$('#bcca-run-chunk-force').on('click', function(e){ e.preventDefault(); runChunk(true); });
			});
		})(jQuery);
		</script>

	</div>
	<?php
}

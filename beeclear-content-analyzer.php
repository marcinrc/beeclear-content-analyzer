<?php
/**
 * Plugin Name: BeeClear Content Analyzer
 * Plugin URI: https://beeclear.pl
 * Description: Advanced content topicality analysis for WordPress posts and pages — server-side relevance (BM25/TF) and browser-side semantic vectors.
 * Version: 1.2.0
 * Author: <a href="https://beeclear.pl">BeeClear</a>
 * License: GPL v2 or later
 * Text Domain: beeclear-content-analyzer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'CA_VERSION', '1.2.0' );

add_action( 'plugins_loaded', function() {
    // Load translations
    load_plugin_textdomain( 'beeclear-content-analyzer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

register_activation_hook( __FILE__, function() {
    global $wpdb;
    $table = $wpdb->prefix . 'ca_analysis_cache';
    $sql = "CREATE TABLE $table (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id bigint(20) UNSIGNED NOT NULL,
        analysis_data longtext NOT NULL,
        focus_phrase varchar(500) DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY post_id (post_id)
    ) {$wpdb->get_charset_collate()};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
});

/* ============================================================
   SETTINGS (Global)
   ============================================================ */
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

/* --- CLEANUP HELPERS --- */
function ca_clear_all_plugin_data() {
    global $wpdb;
    // Delete plugin options
    delete_option( 'ca_settings' );

    // Delete post meta
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        '_ca_focus_phrase'
    ) );

    // Drop cache table
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


/* --- META BOX --- */
add_action( 'init', function() {
    register_post_meta( '', '_ca_focus_phrase', array(
        'show_in_rest'=>true,'single'=>true,'type'=>'string',
        'auth_callback'=>function(){return current_user_can('edit_posts');}
    ));
});
add_action( 'add_meta_boxes', function() {
    foreach(array('post','page') as $pt)
        add_meta_box('ca_focus_phrase', esc_html__( 'Content Analyzer — Focus topic', 'beeclear-content-analyzer' ), 'ca_render_meta_box', $pt, 'side' );
});
function ca_render_meta_box($post) {
    $ph = get_post_meta($post->ID,'_ca_focus_phrase',true);
    wp_nonce_field('ca_save_meta','ca_meta_nonce');
    echo '<p><label for="ca_focus_phrase">' . esc_html__( 'Focus phrase / topic:', 'beeclear-content-analyzer' ) . '</label>';
    echo '<input type="text" id="ca_focus_phrase" name="ca_focus_phrase" value="'.esc_attr($ph).'" style="width:100%" placeholder="e.g. content marketing"></p>';
    echo '<p class="description">' . esc_html__( 'Default topic used for word and chunk analysis.', 'beeclear-content-analyzer' ) . '</p>';
}
add_action('save_post', function($pid) {
    if(!isset($_POST['ca_meta_nonce'])||!wp_verify_nonce($_POST['ca_meta_nonce'],'ca_save_meta'))return;
    if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
    if(!current_user_can('edit_post',$pid))return;
    if(isset($_POST['ca_focus_phrase']))
        update_post_meta($pid,'_ca_focus_phrase',sanitize_text_field($_POST['ca_focus_phrase']));
});

/* --- MENU --- */
add_action( 'admin_menu', function() {
    // Top-level menu keeps the existing UX, but we add submenus.
    add_menu_page(
        __( 'Content Analyzer', 'beeclear-content-analyzer' ),
        __( 'Content Analyzer', 'beeclear-content-analyzer' ),
        'edit_posts',
        'content-analyzer',
        'ca_render_admin_page',
        'dashicons-chart-bar',
        30
    );

    // Global settings (must be first)
    add_submenu_page(
        'content-analyzer',
        __( 'Global settings', 'beeclear-content-analyzer' ),
        __( 'Global settings', 'beeclear-content-analyzer' ),
        'manage_options',
        'ca-global-settings',
        'ca_render_global_settings_page'
    );

    // Analyzer (existing page)
    add_submenu_page(
        'content-analyzer',
        __( 'Analyzer', 'beeclear-content-analyzer' ),
        __( 'Analyzer', 'beeclear-content-analyzer' ),
        'edit_posts',
        'content-analyzer',
        'ca_render_admin_page'
    );

    // Import / Export
    add_submenu_page(
        'content-analyzer',
        __( 'Import/Export', 'beeclear-content-analyzer' ),
        __( 'Import/Export', 'beeclear-content-analyzer' ),
        'manage_options',
        'ca-import-export',
        'ca_render_import_export_page'
    );
} );

// "Settings" link on the plugins list (must be the first link)
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=ca-global-settings' ) ) . '">' . esc_html__( 'Settings', 'beeclear-content-analyzer' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    $allowed = array(
        'toplevel_page_content-analyzer',
        'content-analyzer_page_ca-global-settings',
        'content-analyzer_page_ca-import-export',
    );
    if ( ! in_array( $hook, $allowed, true ) ) {
        return;
    }

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

/* --- NLP HELPERS --- */
function ca_get_stop_words(){
    return array('i','w','na','z','do','nie','się','to','jest','że','o','jak','ale','za','co','od','po','tak','jej','jego','te','ten','ta','tym','tego','tej','tych','był','była','było','były','być','może','ich','go','mu','mi','ci','nam','was','im','ją','je','nas','ze','są','by','już','tylko','też','ma','czy','więc','dla','gdy','przed','przez','przy','bez','pod','nad','między','ku','lub','albo','oraz','a','u','we','tu','tam','raz','no','ani','bo','pan','pani','jako','sobie','który','która','które','których','którym','którą','czym','gdzie','kiedy','bardzo','będzie','można','mnie','mają','każdy','inne','innych','jednak','tego','tym','tę','tą','nimi','nich','niego','niej','nią','nim','jeszcze','teraz','tutaj','wtedy','zawsze','nigdy','często','czasem','potem','ponieważ','więcej','mniej','dużo','mało','każda','każde','the','a','an','and','or','but','in','on','at','to','for','of','with','by','from','is','are','was','were','be','been','being','have','has','had','do','does','did','will','would','shall','should','may','might','can','could','this','that','these','those','it','its','he','she','they','we','you','me','him','her','us','them','my','your','his','our','their','not','no','so','if','then','than','too','very','just','about','up','out','all','also');
}
function ca_build_tf_vector($text){
    $text=mb_strtolower($text);
    $words=preg_split('/[^\p{L}\p{N}\-]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
    $stop=ca_get_stop_words(); $tf=array(); $total=0;
    foreach($words as $w){$w=trim($w,'-');if(mb_strlen($w)<3||in_array($w,$stop,true))continue;if(!isset($tf[$w]))$tf[$w]=0;$tf[$w]++;$total++;}
    if($total>0)foreach($tf as $t=>$c)$tf[$t]=$c/$total;
    return $tf;
}
function ca_cosine_similarity($va,$vb){
    $terms=array_unique(array_merge(array_keys($va),array_keys($vb)));
    $dot=$ma=$mb=0.0;
    foreach($terms as $t){$a=$va[$t]??0.0;$b=$vb[$t]??0.0;$dot+=$a*$b;$ma+=$a*$a;$mb+=$b*$b;}
    $ma=sqrt($ma);$mb=sqrt($mb);
    return($ma==0||$mb==0)?0.0:round($dot/($ma*$mb),4);
}
function ca_extract_entities($text){
    $text=mb_strtolower($text);$text=preg_replace('/\b\d+\b/','',$text);
    $words=preg_split('/[^\p{L}\p{N}\-]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
    $stop=ca_get_stop_words();$freq=array();$valid=0;
    foreach($words as $w){$w=trim($w,'-');if(mb_strlen($w)<3||in_array($w,$stop,true))continue;if(!isset($freq[$w]))$freq[$w]=0;$freq[$w]++;$valid++;}
    arsort($freq);$ent=array();
    foreach($freq as $term=>$count)$ent[]=array('term'=>$term,'count'=>$count,'frequency'=>$valid>0?round($count/$valid*100,2):0);
    return array_slice($ent,0,150);
}
function ca_extract_headings($content){
    $h=array('h1'=>array(),'h2'=>array(),'h3'=>array(),'h4'=>array(),'h5'=>array(),'h6'=>array(),'total'=>0);
    for($i=1;$i<=6;$i++){preg_match_all('/<h'.$i.'[^>]*>(.*?)<\/h'.$i.'>/si',$content,$m);
        if(!empty($m[1])){foreach($m[1] as $x)$h['h'.$i][]=wp_strip_all_tags($x);$h['total']+=count($m[1]);}}
    return $h;
}
function ca_count_paragraphs($content){
    $content=wpautop($content);preg_match_all('/<p[^>]*>(.*?)<\/p>/si',$content,$m);$c=0;
    if(!empty($m[1]))foreach($m[1] as $p)if(trim(wp_strip_all_tags($p))!=='')$c++;
    return $c;
}
function ca_extract_chunks($content){
    $content=wpautop($content);preg_match_all('/<p[^>]*>(.*?)<\/p>/si',$content,$m);$chunks=array();$idx=0;
    if(!empty($m[1]))foreach($m[1] as $p){$t=trim(wp_strip_all_tags($p));$t=html_entity_decode($t,ENT_QUOTES,'UTF-8');
        if(!empty($t)&&mb_strlen($t)>15){$w=preg_split('/\s+/',$t,-1,PREG_SPLIT_NO_EMPTY);$chunks[]=array('index'=>$idx,'text'=>$t,'word_count'=>count($w),'char_count'=>mb_strlen($t));$idx++;}}
    return $chunks;
}
function ca_get_plain_text($post){$t=wp_strip_all_tags($post->post_content);$t=html_entity_decode($t,ENT_QUOTES,'UTF-8');return preg_replace('/\s+/',' ',trim($t));}
function ca_build_context_vector($text_lower,$target,$all_words){
    $stop=ca_get_stop_words();$window=5;$ctx=array();$total=0;$pos=array();
    foreach($all_words as $i=>$w){$w=trim($w,'-');if($w===$target)$pos[]=$i;}
    foreach($pos as $p){for($j=max(0,$p-$window);$j<=min(count($all_words)-1,$p+$window);$j++){
        if($j===$p)continue;$w=trim($all_words[$j],'-');if(mb_strlen($w)<3||in_array($w,$stop,true))continue;
        if(!isset($ctx[$w]))$ctx[$w]=0;$ctx[$w]++;$total++;}}
    if($total>0)foreach($ctx as $t=>$c)$ctx[$t]=$c/$total;
    return $ctx;
}

/* ============================================================
   ADMIN PAGES: Global settings + Import/Export
   ============================================================ */
function ca_render_global_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'beeclear-content-analyzer' ) );
    }

    $opts = ca_get_settings();

    // Handle save
    if ( isset( $_POST['ca_settings_submit'] ) ) {
        check_admin_referer( 'ca_save_settings', 'ca_settings_nonce' );
        $new_opts = array(
            'mode' => sanitize_text_field( $_POST['ca_mode'] ?? '' ),
            'delete_data_on_deactivate' => ! empty( $_POST['ca_delete_data_on_deactivate'] ) ? 1 : 0,
        );
        $opts = ca_update_settings( $new_opts );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'beeclear-content-analyzer' ) . '</p></div>';
    }

    // Handle clear data
    if ( isset( $_POST['ca_clear_data_submit'] ) ) {
        check_admin_referer( 'ca_clear_data', 'ca_clear_data_nonce' );
        ca_clear_all_plugin_data();
        // Recreate defaults so plugin UI keeps working.
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
                    <th scope="row">
                        <label for="ca_mode"><?php echo esc_html__( 'Analysis mode', 'beeclear-content-analyzer' ); ?></label>
                    </th>
                    <td>
                        <select id="ca_mode" name="ca_mode">
                            <option value="server" <?php selected( $opts['mode'], 'server' ); ?>>
                                <?php echo esc_html__( 'Server (fast, no browser compute)', 'beeclear-content-analyzer' ); ?>
                            </option>
                            <option value="browser" <?php selected( $opts['mode'], 'browser' ); ?>>
                                <?php echo esc_html__( 'Browser (client-side semantic vectors)', 'beeclear-content-analyzer' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php echo esc_html__( 'Server mode runs analysis in PHP. Browser mode computes vectors in your browser (no external services).', 'beeclear-content-analyzer' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php echo esc_html__( 'Data handling', 'beeclear-content-analyzer' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ca_delete_data_on_deactivate" value="1" <?php checked( ! empty( $opts['delete_data_on_deactivate'] ) ); ?> />
                            <?php echo esc_html__( 'Delete plugin data on deactivation', 'beeclear-content-analyzer' ); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__( 'If enabled, plugin settings, focus phrase meta and cache table will be removed when you deactivate the plugin.', 'beeclear-content-analyzer' ); ?>
                        </p>
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
            $json = json_decode( $raw, true );
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


/* --- AJAX: posts list --- */
add_action('wp_ajax_ca_get_posts_list', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $posts=get_posts(array('post_type'=>array('post','page'),'post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC'));
    $r=array();
    foreach($posts as $p){$text=ca_get_plain_text($p);$words=preg_split('/\s+/',$text,-1,PREG_SPLIT_NO_EMPTY);$hdg=ca_extract_headings($p->post_content);
        $cats=array();if($p->post_type==='post'){$c=get_the_category($p->ID);if($c)foreach($c as $cat)$cats[]=$cat->name;}
        $r[]=array('post_id'=>$p->ID,'title'=>$p->post_title,'post_type'=>$p->post_type,'post_status'=>$p->post_status,'slug'=>$p->post_name,
            'url'=>get_permalink($p->ID),'edit_url'=>get_edit_post_link($p->ID,'raw'),'date_published'=>$p->post_date,'date_modified'=>$p->post_modified,
            'categories'=>$cats,'char_count'=>mb_strlen($text),'word_count'=>count($words),'paragraph_count'=>ca_count_paragraphs($p->post_content),'headings'=>$hdg);}
    wp_send_json_success($r);
});

/* --- AJAX: post detail --- */
add_action('wp_ajax_ca_get_post_detail', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $pid=absint($_POST['post_id']??0);$post=get_post($pid);if(!$post)wp_send_json_error('Not found');
    $text=ca_get_plain_text($post);$words=preg_split('/\s+/',$text,-1,PREG_SPLIT_NO_EMPTY);
    $sents=preg_split('/[.!?]+(?=\s|$)/u',$text,-1,PREG_SPLIT_NO_EMPTY);
    $sc=count(array_filter($sents,function($s){return mb_strlen(trim($s))>2;}));
    $cats=array();if($post->post_type==='post'){$c=get_the_category($pid);if($c)foreach($c as $cat)$cats[]=$cat->name;}
    $focus=get_post_meta($pid,'_ca_focus_phrase',true);
    wp_send_json_success(array('post_id'=>$pid,'title'=>$post->post_title,'post_type'=>$post->post_type,
        'url'=>get_permalink($pid),'edit_url'=>get_edit_post_link($pid,'raw'),'date_published'=>$post->post_date,'date_modified'=>$post->post_modified,
        'categories'=>$cats,'char_count'=>mb_strlen($text),'char_count_no_spaces'=>mb_strlen(preg_replace('/\s/','',$text)),
        'word_count'=>count($words),'sentence_count'=>$sc,'paragraph_count'=>ca_count_paragraphs($post->post_content),
        'reading_time'=>max(1,round(count($words)/200)),'headings'=>ca_extract_headings($post->post_content),
        'entities'=>ca_extract_entities($text),'chunks'=>ca_extract_chunks($post->post_content),'focus_phrase'=>$focus?:''));
});

/* --- AJAX: embedding — all words vs topic --- */
add_action('wp_ajax_ca_run_embedding_analysis', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $pid=absint($_POST['post_id']??0);
    $phrase=sanitize_text_field($_POST['phrase']??'');
    if(!$pid||empty($phrase))wp_send_json_error('Missing data');
    $post=get_post($pid);if(!$post)wp_send_json_error('Not found');

    $opts = ca_get_settings();
    $text = ca_get_plain_text($post);

    // Browser mode: return payload for client-side vector analysis (no external services).
    if ( isset($opts['mode']) && $opts['mode'] === 'browser' ) {
        wp_send_json_success(array(
            'mode' => 'browser',
            'phrase' => $phrase,
            'text' => $text,
        ));
    }

    // Server mode: enhanced relevance analysis (TF + context + basic phrase n-grams), with hard limits for performance.
    $topic_vec = ca_build_tf_vector($phrase);

    // Add simple bigrams from the phrase to improve topical matching without external models.
    $phrase_lower = mb_strtolower($phrase);
    $phrase_words = preg_split('/[^\p{L}\p{N}\-]+/u', $phrase_lower, -1, PREG_SPLIT_NO_EMPTY);
    if ( count($phrase_words) >= 2 ) {
        for ( $i=0; $i<count($phrase_words)-1; $i++ ) {
            $bg = trim($phrase_words[$i], '-') . ' ' . trim($phrase_words[$i+1], '-');
            if ( mb_strlen($bg) >= 5 ) {
                $topic_vec[$bg] = ($topic_vec[$bg] ?? 0) + 0.35; // light weight
            }
        }
    }
    $topic_terms = array_keys($topic_vec);

    $text_lower = mb_strtolower($text);
    $all_words = preg_split('/[^\p{L}\p{N}\-]+/u', $text_lower, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ca_get_stop_words();

    // Frequency map (limit unique terms for performance)
    $wf = array();
    foreach($all_words as $w){
        $w=trim($w,'-');
        if(mb_strlen($w)<3||in_array($w,$stop,true))continue;
        if(!isset($wf[$w]))$wf[$w]=0;
        $wf[$w]++;
    }
    arsort($wf);

    // Only analyze top N terms to avoid timeouts on long content.
    $max_terms = 600;
    $wf = array_slice($wf, 0, $max_terms, true);

    $ws = array();
    foreach($wf as $word=>$count){
        $dm = in_array($word,$topic_terms,true)?1.0:0.0;
        $cv = ca_build_context_vector($text_lower,$word,$all_words);
        $cs = ca_cosine_similarity($topic_vec,$cv);

        // Score: direct match + context similarity
        $score = round(min(1.0,($dm*0.55)+($cs*0.45)),4);

        $ws[] = array(
            'word'=>$word,
            'count'=>$count,
            'direct_match'=>$dm>0,
            'context_score'=>round($cs*100,1),
            'relevance_score'=>round($score*100,1)
        );
    }

    usort($ws,function($a,$b){return $b['relevance_score']<=>$a['relevance_score'];});

    $tv = ca_build_tf_vector($text);
    $os = ca_cosine_similarity($topic_vec,$tv);

    $tw = count($ws);
    $hi = count(array_filter($ws,function($w){return $w['relevance_score']>=40;}));
    $md = count(array_filter($ws,function($w){return $w['relevance_score']>=15&&$w['relevance_score']<40;}));
    $lo = count(array_filter($ws,function($w){return $w['relevance_score']<15;}));
    $av = $tw>0?round(array_sum(array_column($ws,'relevance_score'))/$tw,1):0;

    wp_send_json_success(array(
        'mode' => 'server',
        'phrase'=>$phrase,
        'overall_similarity'=>round($os*100,1),
        'total_unique_words'=>$tw,
        'high_relevance'=>$hi,
        'medium_relevance'=>$md,
        'low_relevance'=>$lo,
        'average_relevance'=>$av,
        'words'=>array_slice($ws,0,200)
    ));
});


/* --- AJAX: chunks — all paragraphs vs topic --- */
add_action('wp_ajax_ca_run_chunk_analysis', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $pid=absint($_POST['post_id']??0);
    $phrase=sanitize_text_field($_POST['phrase']??'');
    if(!$pid||empty($phrase))wp_send_json_error('Missing data');
    $post=get_post($pid);if(!$post)wp_send_json_error('Not found');

    $opts = ca_get_settings();

    // Browser mode: return payload for client-side vector analysis (no external services).
    if ( isset($opts['mode']) && $opts['mode'] === 'browser' ) {
        wp_send_json_success(array(
            'mode' => 'browser',
            'phrase' => $phrase,
            'chunks' => ca_extract_chunks($post->post_content),
        ));
    }

    // Server mode (existing UX): TF cosine per paragraph
    $chunks = ca_extract_chunks($post->post_content);
    $tv = ca_build_tf_vector($phrase);

    // Add lightweight phrase bigrams
    $phrase_lower = mb_strtolower($phrase);
    $phrase_words = preg_split('/[^\p{L}\p{N}\-]+/u', $phrase_lower, -1, PREG_SPLIT_NO_EMPTY);
    if ( count($phrase_words) >= 2 ) {
        for ( $i=0; $i<count($phrase_words)-1; $i++ ) {
            $bg = trim($phrase_words[$i], '-') . ' ' . trim($phrase_words[$i+1], '-');
            if ( mb_strlen($bg) >= 5 ) {
                $tv[$bg] = ($tv[$bg] ?? 0) + 0.35;
            }
        }
    }

    $results = array();
    foreach($chunks as $ch){
        $cv = ca_build_tf_vector($ch['text']);
        $sim = ca_cosine_similarity($tv,$cv);

        $cl = mb_strtolower($ch['text']);
        $tf = array();
        foreach(array_keys($tv) as $term){
            // Only check single-word terms for highlighting.
            if ( strpos($term, ' ') !== false ) { continue; }
            $cnt = mb_substr_count($cl,$term);
            if($cnt>0)$tf[]=array('term'=>$term,'count'=>$cnt);
        }
        $results[] = array(
            'index'=>$ch['index'],
            'text'=>$ch['text'],
            'word_count'=>$ch['word_count'],
            'similarity'=>$sim,
            'similarity_percent'=>round($sim*100,1),
            'topic_terms_found'=>$tf
        );
    }

    usort($results,function($a,$b){return $b['similarity']<=>$a['similarity'];});
    $avg = count($results)>0?array_sum(array_column($results,'similarity'))/count($results):0;
    $mx = count($results)>0?max(array_column($results,'similarity_percent')):0;
    $mn = count($results)>0?min(array_column($results,'similarity_percent')):0;

    wp_send_json_success(array(
        'mode' => 'server',
        'phrase'=>$phrase,
        'chunks'=>$results,
        'chunk_count'=>count($results),
        'average_similarity'=>round($avg,4),
        'average_percent'=>round($avg*100,1),
        'max_percent'=>$mx,
        'min_percent'=>$mn
    ));
});


/* --- AJAX: categories --- */
add_action('wp_ajax_ca_get_categories', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $cats=get_categories(array('hide_empty'=>false));$l=array();
    foreach($cats as $c)$l[]=array('id'=>$c->term_id,'name'=>$c->name);
    wp_send_json_success($l);
});

/* ============================================================
   RENDER ADMIN PAGE — HTML + CSS + JS
   ============================================================ */
function ca_render_admin_page(){
?>
<style>
.ca-wrap{max-width:100%;padding:10px 0}
.ca-page-title{display:flex;align-items:center;gap:8px;font-size:23px;margin-bottom:20px}
.ca-page-title .dashicons{font-size:28px;width:28px;height:28px;color:#2271b1}
.ca-filters{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin-bottom:16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.ca-filter-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}
.ca-filter-group{display:flex;flex-direction:column;gap:4px}
.ca-filter-group label{font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px}
.ca-filter-group input[type="text"],.ca-filter-group input[type="date"],.ca-filter-group select{min-width:150px;height:36px;padding:4px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px}
.ca-filter-group input[type="text"]{min-width:200px}
.ca-filter-actions{display:flex;align-items:center;gap:16px}
.ca-results-count{font-size:13px;color:#50575e;font-weight:500}
.ca-loading{display:flex;align-items:center;gap:8px;padding:20px;color:#50575e;font-size:14px}
.ca-loading .spinner{float:none;margin:0}
.ca-table-wrap{overflow-x:auto}
.ca-table{border-collapse:collapse;width:100%;font-size:13px}
.ca-table thead th{background:#f0f0f1;font-weight:600;padding:10px 12px;text-align:left;white-space:nowrap;border-bottom:2px solid #c3c4c7;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#50575e}
.ca-sortable{cursor:pointer;user-select:none}.ca-sortable:hover{color:#2271b1}
.ca-sort-icon::after{content:'↕';font-size:10px;margin-left:4px;opacity:.4}
.ca-sortable.asc .ca-sort-icon::after{content:'↑';opacity:1;color:#2271b1}
.ca-sortable.desc .ca-sort-icon::after{content:'↓';opacity:1;color:#2271b1}
.ca-table tbody td{padding:10px 12px;vertical-align:middle;border-bottom:1px solid #e0e0e0}
.ca-table tbody tr:hover{background:#f6f7f7}
.ca-badge{display:inline-flex;align-items:center;gap:6px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:999px;padding:3px 10px;font-size:12px}
.ca-badge .dashicons{font-size:16px;width:16px;height:16px;color:#50575e}
.ca-title-link{font-weight:600;color:#2271b1;text-decoration:none}
.ca-title-link:hover{text-decoration:underline}
.ca-small{font-size:12px;color:#646970}
.ca-actions a{display:inline-flex;align-items:center;gap:6px;margin-right:10px;text-decoration:none}
.ca-actions a .dashicons{font-size:16px;width:16px;height:16px}
.ca-actions a:hover{text-decoration:underline}
.ca-drawer{margin-top:18px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.ca-drawer-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #e0e0e0}
.ca-drawer-title{font-size:16px;font-weight:700;margin:0}
.ca-close{background:transparent;border:none;cursor:pointer;padding:4px;border-radius:4px}
.ca-close:hover{background:#f0f0f1}
.ca-drawer-body{padding:16px}
.ca-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
.ca-card{grid-column:span 12;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:14px;box-shadow:0 1px 1px rgba(0,0,0,.03)}
.ca-card h3{margin:0 0 10px 0;font-size:14px}
.ca-kpi{display:flex;flex-wrap:wrap;gap:10px}
.ca-kpi .ca-pill{background:#f6f7f7;border:1px solid #e0e0e0;border-radius:999px;padding:6px 10px;font-size:12px}
.ca-tabs{display:flex;gap:8px;margin:10px 0 14px 0;border-bottom:1px solid #e0e0e0}
.ca-tab{padding:8px 10px;cursor:pointer;border:1px solid transparent;border-top-left-radius:6px;border-top-right-radius:6px;font-weight:600;color:#50575e}
.ca-tab.active{background:#fff;border-color:#e0e0e0;border-bottom-color:#fff;color:#111}
.ca-tab-pane{display:none}.ca-tab-pane.active{display:block}
.ca-input-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px}
.ca-input-row input[type="text"]{min-width:260px;height:36px;padding:4px 8px;border:1px solid #8c8f94;border-radius:4px}
.ca-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 12px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer}
.ca-btn.secondary{border-color:#c3c4c7;background:#fff;color:#2271b1}
.ca-btn:hover{filter:brightness(.98)}
.ca-similarity-score{display:flex;align-items:center;gap:12px;margin:10px 0 10px 0}
.ca-score-circle{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#fff}
.ca-score-low{background:#d63638}
.ca-score-medium{background:#dba617}
.ca-score-good{background:#2ea2cc}
.ca-score-great{background:#1d9b4d}
.ca-list{margin:0;padding-left:18px}
.ca-word-table{width:100%;border-collapse:collapse;font-size:13px}
.ca-word-table th,.ca-word-table td{padding:8px 10px;border-bottom:1px solid #e0e0e0}
.ca-word-table th{background:#f6f7f7;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#50575e}
.ca-tag{display:inline-flex;border:1px solid #e0e0e0;background:#f6f7f7;border-radius:999px;padding:2px 8px;font-size:12px}
.ca-no-data{color:#50575e;font-style:italic}
@media (min-width:1000px){
    .ca-card.span-6{grid-column:span 6}
    .ca-card.span-4{grid-column:span 4}
}
</style>

<div class="wrap ca-wrap">
    <div class="ca-page-title"><span class="dashicons dashicons-chart-bar"></span> Content Analyzer</div>

    <div class="ca-filters">
        <div class="ca-filter-row">
            <div class="ca-filter-group">
                <label>Search</label>
                <input type="text" id="ca-filter-search" placeholder="title, slug...">
            </div>
            <div class="ca-filter-group">
                <label>Type</label>
                <select id="ca-filter-type">
                    <option value="">All</option>
                    <option value="post">Post</option>
                    <option value="page">Page</option>
                </select>
            </div>
            <div class="ca-filter-group">
                <label>Status</label>
                <select id="ca-filter-status">
                    <option value="">All</option>
                    <option value="publish">Publish</option>
                    <option value="draft">Draft</option>
                    <option value="private">Private</option>
                </select>
            </div>
            <div class="ca-filter-group">
                <label>Category</label>
                <select id="ca-filter-category"><option value="">All</option></select>
            </div>
            <div class="ca-filter-group">
                <label>Date from</label>
                <input type="date" id="ca-filter-date-from">
            </div>
            <div class="ca-filter-group">
                <label>Date to</label>
                <input type="date" id="ca-filter-date-to">
            </div>
        </div>
        <div class="ca-filter-actions">
            <button class="button button-primary" id="ca-apply-filters">Apply filters</button>
            <button class="button" id="ca-reset-filters">Reset</button>
            <div class="ca-results-count"><span id="ca-count">0</span> results</div>
            <div class="ca-small">Mode: <strong id="ca-mode-badge"></strong></div>
        </div>
    </div>

    <div id="ca-loading" class="ca-loading" style="display:none"><span class="spinner is-active"></span> Loading...</div>

    <div class="ca-table-wrap">
        <table class="ca-table" id="ca-table">
            <thead>
                <tr>
                    <th class="ca-sortable" data-key="title"><span class="ca-sort-icon"></span> Title</th>
                    <th class="ca-sortable" data-key="post_type"><span class="ca-sort-icon"></span> Type</th>
                    <th class="ca-sortable" data-key="post_status"><span class="ca-sort-icon"></span> Status</th>
                    <th>Categories</th>
                    <th class="ca-sortable" data-key="date_published"><span class="ca-sort-icon"></span> Published</th>
                    <th class="ca-sortable" data-key="word_count"><span class="ca-sort-icon"></span> Words</th>
                    <th class="ca-sortable" data-key="paragraph_count"><span class="ca-sort-icon"></span> Paragraphs</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="ca-tbody">
                <tr><td colspan="8" class="ca-no-data">No data.</td></tr>
            </tbody>
        </table>
    </div>

    <div class="ca-drawer" id="ca-drawer" style="display:none">
        <div class="ca-drawer-head">
            <h2 class="ca-drawer-title" id="ca-drawer-title">Details</h2>
            <button class="ca-close" id="ca-close"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="ca-drawer-body">
            <div class="ca-kpi" id="ca-kpi"></div>

            <div class="ca-tabs">
                <div class="ca-tab active" data-tab="overview">Overview</div>
                <div class="ca-tab" data-tab="entities">Entities</div>
                <div class="ca-tab" data-tab="embedding">Word analysis</div>
                <div class="ca-tab" data-tab="chunks">Chunk analysis</div>
            </div>

            <div class="ca-tab-pane active" id="ca-tab-overview">
                <div class="ca-grid">
                    <div class="ca-card span-6">
                        <h3>Headings</h3>
                        <div id="ca-headings" class="ca-small"></div>
                    </div>
                    <div class="ca-card span-6">
                        <h3>Links</h3>
                        <div id="ca-links" class="ca-small">—</div>
                    </div>
                </div>
            </div>

            <div class="ca-tab-pane" id="ca-tab-entities">
                <div class="ca-card">
                    <h3>Top terms (heuristic)</h3>
                    <div id="ca-entities"></div>
                </div>
            </div>

            <div class="ca-tab-pane" id="ca-tab-embedding">
                <div class="ca-card">
                    <h3>Word analysis vs topic</h3>
                    <div class="ca-input-row">
                        <div>
                            <label class="ca-small">Focus topic</label><br>
                            <input type="text" id="ca-embedding-phrase" placeholder="topic phrase...">
                        </div>
                        <button class="ca-btn" id="ca-run-embedding"><span class="dashicons dashicons-search"></span> Analyze</button>
                        <button class="ca-btn secondary" id="ca-use-focus-emb">Use post focus</button>
                    </div>
                    <div id="ca-embedding-results" class="ca-small"></div>
                </div>
            </div>

            <div class="ca-tab-pane" id="ca-tab-chunks">
                <div class="ca-card">
                    <h3>Paragraphs vs topic</h3>
                    <div class="ca-input-row">
                        <div>
                            <label class="ca-small">Focus topic</label><br>
                            <input type="text" id="ca-chunk-phrase" placeholder="topic phrase...">
                        </div>
                        <button class="ca-btn" id="ca-run-chunks"><span class="dashicons dashicons-search"></span> Analyze</button>
                        <button class="ca-btn secondary" id="ca-use-focus-chk">Use post focus</button>
                    </div>
                    <div id="ca-chunk-results" class="ca-small"></div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function($){
'use strict';
function E(t){return String(t||'').replace(/[&<>"']/g,function(m){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[m]})}

var allPosts=[],filteredPosts=[],currentSort={key:'date_published',dir:'desc'},currentPostId=null,currentAnalysis=null,embWords=[];

// ============================================================
// Browser mode: lightweight semantic vectors (feature hashing)
// No external services or libraries required.
// ============================================================
function caTok(s){
    s=(s||'').toLowerCase();
    // split on non letters/numbers/dash
    return s.split(/[^0-9a-ząćęłńóśżź\-]+/i).map(function(x){return x.replace(/^\-+|\-+$/g,'');}).filter(function(x){return x.length>=3;});
}
function caHash32(str){
    // FNV-1a 32-bit
    var h=0x811c9dc5;
    for(var i=0;i<str.length;i++){
        h ^= str.charCodeAt(i);
        h = (h + ((h<<1)+(h<<4)+(h<<7)+(h<<8)+(h<<24))) >>> 0;
    }
    return h>>>0;
}
function caVectorize(text, dim){
    dim = dim || 1024;
    var v = new Array(dim);
    for(var i=0;i<dim;i++) v[i]=0;
    var t = (text||'').toLowerCase();

    // word features
    var toks = caTok(t);
    for(var i=0;i<toks.length;i++){
        var w=toks[i];
        var idx = caHash32('w:'+w) % dim;
        v[idx] += 1;
        if(i<toks.length-1){
            var bg = w+' '+toks[i+1];
            var idx2 = caHash32('b:'+bg) % dim;
            v[idx2] += 0.35;
        }
    }

    // char 4-grams (helps Polish morphology / typos)
    var s = t.replace(/\s+/g,' ');
    for(var j=0;j<s.length-3;j++){
        var g = s.substr(j,4);
        if(g.trim().length<4) continue;
        var idg = caHash32('c:'+g) % dim;
        v[idg] += 0.08;
    }
    return v;
}
function caCos(a,b){
    var dot=0,ma=0,mb=0;
    var n=Math.min(a.length,b.length);
    for(var i=0;i<n;i++){
        var x=a[i]||0,y=b[i]||0;
        dot += x*y; ma += x*x; mb += y*y;
    }
    ma=Math.sqrt(ma); mb=Math.sqrt(mb);
    if(!ma||!mb) return 0;
    return dot/(ma*mb);
}
function caBrowserWordAnalysis(text, phrase){
    var dim=1024;
    var topicVec = caVectorize(phrase, dim);
    var fullVec = caVectorize(text, dim);
    var overall = caCos(topicVec, fullVec);

    var words = caTok(text);
    var stop = {}; // minimal stop set (server still has a bigger list)
    ['który','która','które','oraz','ponieważ','bardzo','więcej','mniej','przez','przed','między','tylko','jest','było','była','były','będzie','można','their','this','that','with','from','have','has','had','will','would','shall','should'].forEach(function(w){stop[w]=1;});

    var freq = {};
    for(var i=0;i<words.length;i++){
        var w=words[i];
        if(stop[w]) continue;
        freq[w]=(freq[w]||0)+1;
    }
    // top terms
    var entries = Object.keys(freq).map(function(k){return {w:k,c:freq[k]};});
    entries.sort(function(a,b){return b.c-a.c;});
    entries = entries.slice(0,600);

    // phrase terms set
    var pset = {};
    caTok(phrase).forEach(function(w){pset[w]=1;});

    var out=[];
    var win=5;
    for(var ei=0;ei<entries.length;ei++){
        var term = entries[ei].w;
        var count = entries[ei].c;
        var dm = pset[term]?1:0;

        // build context string (fast)
        var ctx=[];
        for(var i=0;i<words.length;i++){
            if(words[i]!==term) continue;
            for(var j=Math.max(0,i-win); j<=Math.min(words.length-1,i+win); j++){
                if(j===i) continue;
                ctx.push(words[j]);
            }
            if(ctx.length>200) break; // limit
        }
        var ctxText = ctx.join(' ');
        var ctxVec = caVectorize(ctxText, dim);
        var cs = caCos(topicVec, ctxVec);

        var score = Math.min(1, (dm*0.55)+(cs*0.45));
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
        var rs=out[i].relevance_score;
        sum+=rs;
        if(rs>=40)hi++; else if(rs>=15)md++; else lo++;
    }
    var avg = out.length? Math.round((sum/out.length)*10)/10 : 0;

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
    var topicVec = caVectorize(phrase, dim);

    var res=[];
    for(var i=0;i<chunks.length;i++){
        var ch=chunks[i];
        var vec = caVectorize(ch.text||'', dim);
        var sim = caCos(topicVec, vec);
        res.push({
            index: ch.index,
            text: ch.text,
            word_count: ch.word_count,
            similarity: sim,
            similarity_percent: Math.round(sim*1000)/10,
            topic_terms_found: [] // keep UX consistent; optional enhancement later
        });
    }
    res.sort(function(a,b){return b.similarity-a.similarity;});
    var sum=0, mx=0, mn=100;
    for(var i=0;i<res.length;i++){
        sum+=res[i].similarity;
        mx=Math.max(mx, res[i].similarity_percent);
        mn=Math.min(mn, res[i].similarity_percent);
    }
    var avg = res.length? (sum/res.length) : 0;

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

$(document).ready(function(){loadCats();loadPosts();bindEvents();$('#ca-mode-badge').text((caData && caData.mode)?caData.mode:'server')});

function loadCats(){$.post(caData.ajaxUrl,{action:'ca_get_categories',nonce:caData.nonce},function(r){if(r.success&&r.data){var s=$('#ca-filter-category');r.data.forEach(function(c){s.append('<option value="'+E(c.name)+'">'+E(c.name)+'</option>')})}})}
function loadPosts(){$('#ca-loading').show();$.post(caData.ajaxUrl,{action:'ca_get_posts_list',nonce:caData.nonce},function(r){$('#ca-loading').hide();
    if(r.success&&r.data){allPosts=r.data;filteredPosts=r.data;renderTable();$('#ca-count').text(filteredPosts.length)}else{$('#ca-tbody').html('<tr><td colspan="8" class="ca-no-data">No data.</td></tr>')}})}
function bindEvents(){
    $('#ca-apply-filters').on('click',function(e){e.preventDefault();applyFilters()});
    $('#ca-reset-filters').on('click',function(e){e.preventDefault();resetFilters()});
    $('#ca-table').on('click','.ca-open',function(e){e.preventDefault();openPost($(this).data('id'))});
    $('#ca-close').on('click',function(){closeDrawer()});
    $('.ca-tab').on('click',function(){var t=$(this).data('tab');$('.ca-tab').removeClass('active');$(this).addClass('active');
        $('.ca-tab-pane').removeClass('active');$('#ca-tab-'+t).addClass('active')});
    $('#ca-table').on('click','.ca-sortable',function(){var key=$(this).data('key');if(!key)return;
        if(currentSort.key===key)currentSort.dir=currentSort.dir==='asc'?'desc':'asc';else{currentSort.key=key;currentSort.dir='asc'}
        renderTable()});
    $('#ca-run-embedding').on('click',function(e){e.preventDefault();runEmb()});
    $('#ca-run-chunks').on('click',function(e){e.preventDefault();runChk()});
    $('#ca-use-focus-emb').on('click',function(e){e.preventDefault();if(currentAnalysis&&currentAnalysis.focus_phrase)$('#ca-embedding-phrase').val(currentAnalysis.focus_phrase)});
    $('#ca-use-focus-chk').on('click',function(e){e.preventDefault();if(currentAnalysis&&currentAnalysis.focus_phrase)$('#ca-chunk-phrase').val(currentAnalysis.focus_phrase)});
}
function applyFilters(){
    var s=$('#ca-filter-search').val().toLowerCase().trim(),t=$('#ca-filter-type').val(),st=$('#ca-filter-status').val(),c=$('#ca-filter-category').val(),
        df=$('#ca-filter-date-from').val(),dt=$('#ca-filter-date-to').val();
    filteredPosts=allPosts.filter(function(p){
        if(s && !(String(p.title||'').toLowerCase().includes(s)||String(p.slug||'').toLowerCase().includes(s)))return false;
        if(t && p.post_type!==t)return false;
        if(st && p.post_status!==st)return false;
        if(c && !(p.categories||[]).includes(c))return false;
        if(df && p.date_published < df)return false;
        if(dt && p.date_published > dt)return false;
        return true;
    });
    renderTable();$('#ca-count').text(filteredPosts.length);
}
function resetFilters(){
    $('#ca-filter-search').val('');$('#ca-filter-type').val('');$('#ca-filter-status').val('');$('#ca-filter-category').val('');
    $('#ca-filter-date-from').val('');$('#ca-filter-date-to').val('');
    filteredPosts=allPosts;renderTable();$('#ca-count').text(filteredPosts.length);
}
function sortPosts(arr){
    var k=currentSort.key,dir=currentSort.dir;
    arr.sort(function(a,b){
        var x=a[k],y=b[k];
        if(k==='date_published'||k==='date_modified'){x=String(x||'');y=String(y||'')}
        if(typeof x==='string')x=x.toLowerCase();
        if(typeof y==='string')y=y.toLowerCase();
        if(x<y)return dir==='asc'?-1:1;
        if(x>y)return dir==='asc'?1:-1;
        return 0;
    });
}
function renderTable(){
    sortPosts(filteredPosts);
    $('.ca-sortable').removeClass('asc desc');$('.ca-sortable[data-key="'+currentSort.key+'"]').addClass(currentSort.dir);
    var tb=$('#ca-tbody');tb.empty();
    if(!filteredPosts.length){tb.html('<tr><td colspan="8" class="ca-no-data">No data.</td></tr>');return;}
    filteredPosts.forEach(function(p){
        var cats=(p.categories||[]).map(function(x){return '<span class="ca-tag">'+E(x)+'</span>'}).join(' ')||'—';
        tb.append('<tr>'+
            '<td><a href="#" class="ca-title-link ca-open" data-id="'+p.post_id+'">'+E(p.title)+'</a><div class="ca-small">'+E(p.slug)+'</div></td>'+
            '<td><span class="ca-badge"><span class="dashicons dashicons-media-document"></span> '+E(p.post_type)+'</span></td>'+
            '<td>'+E(p.post_status)+'</td>'+
            '<td>'+cats+'</td>'+
            '<td>'+E(String(p.date_published||'').slice(0,10))+'</td>'+
            '<td>'+E(p.word_count)+'</td>'+
            '<td>'+E(p.paragraph_count)+'</td>'+
            '<td class="ca-actions"><a href="'+E(p.edit_url||'#')+'"><span class="dashicons dashicons-edit"></span> Edit</a><a href="'+E(p.url||'#')+'" target="_blank"><span class="dashicons dashicons-external"></span> View</a></td>'+
        '</tr>');
    });
}
function openPost(id){
    currentPostId=id;
    $('#ca-drawer').show();
    $('#ca-drawer-title').text('Loading...');
    $('#ca-kpi').empty();
    $('#ca-entities').html('');
    $('#ca-headings').html('');
    $('#ca-embedding-results').html('');
    $('#ca-chunk-results').html('');
    $.post(caData.ajaxUrl,{action:'ca_get_post_detail',nonce:caData.nonce,post_id:id},function(r){
        if(!r.success||!r.data){$('#ca-drawer-title').text('Error');return;}
        currentAnalysis=r.data;
        $('#ca-drawer-title').text(r.data.title+' (#'+r.data.post_id+')');
        $('#ca-embedding-phrase').val(r.data.focus_phrase||'');
        $('#ca-chunk-phrase').val(r.data.focus_phrase||'');

        var kpi=$('#ca-kpi');
        kpi.append('<span class="ca-pill">Words: <strong>'+E(r.data.word_count)+'</strong></span>');
        kpi.append('<span class="ca-pill">Sentences: <strong>'+E(r.data.sentence_count)+'</strong></span>');
        kpi.append('<span class="ca-pill">Paragraphs: <strong>'+E(r.data.paragraph_count)+'</strong></span>');
        kpi.append('<span class="ca-pill">Chars: <strong>'+E(r.data.char_count)+'</strong></span>');
        kpi.append('<span class="ca-pill">Reading: <strong>'+E(r.data.reading_time)+' min</strong></span>');

        renderHeadings(r.data.headings||{});
        renderEntities(r.data.entities||[]);
    });
}
function closeDrawer(){currentPostId=null;currentAnalysis=null;$('#ca-drawer').hide()}
function renderHeadings(h){
    var html='';
    ['h1','h2','h3','h4','h5','h6'].forEach(function(k){
        var arr=h[k]||[];
        if(arr.length){html+='<div><strong>'+k.toUpperCase()+'</strong>: '+arr.map(E).join(' | ')+'</div>';}
    });
    if(!html)html='<span class="ca-no-data">No headings.</span>';
    $('#ca-headings').html(html);
}
function renderEntities(list){
    if(!list||!list.length){$('#ca-entities').html('<p class="ca-no-data">No data.</p>');return;}
    var rows=list.slice(0,80).map(function(x){
        return '<tr><td>'+E(x.term)+'</td><td>'+E(x.count)+'</td><td>'+E(x.frequency)+'%</td></tr>';
    }).join('');
    $('#ca-entities').html('<table class="ca-word-table"><thead><tr><th>Term</th><th>Count</th><th>Freq</th></tr></thead><tbody>'+rows+'</tbody></table>');
}

function runEmb(){var ph=$('#ca-embedding-phrase').val().trim();if(!ph||!currentPostId)return;
    var $r=$('#ca-embedding-results');$r.html('<div class="ca-loading"><span class="spinner is-active"></span> '+E('Analyzing words vs. topic...')+'</div>');
    $.post(caData.ajaxUrl,{action:'ca_run_embedding_analysis',nonce:caData.nonce,post_id:currentPostId,phrase:ph},function(res){
        if(res && res.success && res.data){
            if(res.data.mode==='browser' && res.data.text){
                try{
                    var computed = caBrowserWordAnalysis(res.data.text, res.data.phrase||ph);
                    renderEmbRes(computed);
                }catch(e){
                    if(window.console && console.error){console.error(e);}
                    $r.html('<p class="ca-no-data">'+E('Browser analysis failed.')+'</p>');
                }
            }else{
                renderEmbRes(res.data);
            }
        }else{
            $r.html('<p class="ca-no-data">'+E('Error.')+'</p>');
        }
    })}

function renderEmbRes(d){var $r=$('#ca-embedding-results');$r.empty();embWords=d.words||[];
    var pct=d.overall_similarity,sc=pct<15?'ca-score-low':pct<30?'ca-score-medium':pct<50?'ca-score-good':'ca-score-great';
    var html='<div class="ca-similarity-score"><div class="ca-score-circle '+sc+'">'+E(pct)+'%</div>'+
        '<div><div><strong>Overall similarity</strong></div><div class="ca-small">Unique words analyzed: '+E(d.total_unique_words)+
        ' | High: '+E(d.high_relevance)+' | Medium: '+E(d.medium_relevance)+' | Low: '+E(d.low_relevance)+
        ' | Avg: '+E(d.average_relevance)+'%</div></div></div>';
    if(!embWords.length){html+='<p class="ca-no-data">No words.</p>'; $r.html(html);return;}
    html+='<table class="ca-word-table"><thead><tr><th>Word</th><th>Count</th><th>Direct</th><th>Context</th><th>Relevance</th></tr></thead><tbody>';
    embWords.forEach(function(w){
        html+='<tr><td>'+E(w.word)+'</td><td>'+E(w.count)+'</td><td>'+(w.direct_match?'<span class="ca-tag">yes</span>':'—')+
            '</td><td>'+E(w.context_score)+'%</td><td><strong>'+E(w.relevance_score)+'%</strong></td></tr>';
    });
    html+='</tbody></table>';
    $r.html(html)
}

function runChk(){var ph=$('#ca-chunk-phrase').val().trim();if(!ph||!currentPostId)return;
    var $r=$('#ca-chunk-results');$r.html('<div class="ca-loading"><span class="spinner is-active"></span> '+E('Analyzing paragraphs...')+'</div>');
    $.post(caData.ajaxUrl,{action:'ca_run_chunk_analysis',nonce:caData.nonce,post_id:currentPostId,phrase:ph},function(res){
        if(res && res.success && res.data){
            if(res.data.mode==='browser' && res.data.chunks){
                try{
                    var computed = caBrowserChunkAnalysis(res.data.chunks, res.data.phrase||ph);
                    renderChkRes(computed);
                }catch(e){
                    if(window.console && console.error){console.error(e);}
                    $r.html('<p class="ca-no-data">'+E('Browser analysis failed.')+'</p>');
                }
            }else{
                renderChkRes(res.data);
            }
        }else{
            $r.html('<p class="ca-no-data">'+E('Error.')+'</p>');
        }
    })}

function renderChkRes(d){var $r=$('#ca-chunk-results');$r.empty();
    var html='<div class="ca-chunk-summary ca-small">Chunks: <strong>'+E(d.chunk_count)+'</strong> | Average: <strong>'+E(d.average_percent)+'%</strong> | Max: <strong>'+E(d.max_percent)+'%</strong> | Min: <strong>'+E(d.min_percent)+'%</strong></div>';
    if(!d.chunks||!d.chunks.length){html+='<p class="ca-no-data">No chunks.</p>'; $r.html(html);return;}
    html+='<table class="ca-word-table"><thead><tr><th>#</th><th>Similarity</th><th>Words</th><th>Text</th></tr></thead><tbody>';
    d.chunks.slice(0,80).forEach(function(ch){
        html+='<tr><td>'+E(ch.index)+'</td><td><strong>'+E(ch.similarity_percent)+'%</strong></td><td>'+E(ch.word_count)+'</td><td>'+E(ch.text).slice(0,240)+(String(ch.text||'').length>240?'…':'')+'</td></tr>';
    });
    html+='</tbody></table>';
    $r.html(html);
}

})(jQuery);
</script>
<?php
}

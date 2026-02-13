<?php
/**
 * Plugin Name: BeeClear Content Analyzer
 * Plugin URI: https://example.com/content-analyzer
 * Description: Zaawansowana analiza treści stron i wpisów WordPress — encje, embeddingi słów, analiza chunków.
 * Version: 1.1.0
 * Author: BeeClear
 * License: GPL v2 or later
 * Text Domain: beeclear-content-analyzer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'CA_VERSION', '1.1.0' );

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

/* --- META BOX --- */
add_action( 'init', function() {
    register_post_meta( '', '_ca_focus_phrase', array(
        'show_in_rest'=>true,'single'=>true,'type'=>'string',
        'auth_callback'=>function(){return current_user_can('edit_posts');}
    ));
});
add_action( 'add_meta_boxes', function() {
    foreach(array('post','page') as $pt)
        add_meta_box('ca_focus_phrase','Content Analyzer — Fraza fokusowa','ca_render_meta_box',$pt,'side');
});
function ca_render_meta_box($post) {
    $ph = get_post_meta($post->ID,'_ca_focus_phrase',true);
    wp_nonce_field('ca_save_meta','ca_meta_nonce');
    echo '<p><label for="ca_focus_phrase">Fraza/temat do analizy:</label>';
    echo '<input type="text" id="ca_focus_phrase" name="ca_focus_phrase" value="'.esc_attr($ph).'" style="width:100%" placeholder="np. marketing internetowy"></p>';
    echo '<p class="description">Domyślna fraza do analizy embeddingowej i chunkowej.</p>';
}
add_action('save_post', function($pid) {
    if(!isset($_POST['ca_meta_nonce'])||!wp_verify_nonce($_POST['ca_meta_nonce'],'ca_save_meta'))return;
    if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
    if(!current_user_can('edit_post',$pid))return;
    if(isset($_POST['ca_focus_phrase']))
        update_post_meta($pid,'_ca_focus_phrase',sanitize_text_field($_POST['ca_focus_phrase']));
});

/* --- MENU --- */
add_action('admin_menu', function() {
    add_menu_page('Content Analyzer','Content Analyzer','edit_posts','content-analyzer','ca_render_admin_page','dashicons-chart-bar',30);
});
add_action('admin_enqueue_scripts', function($hook) {
    if($hook!=='toplevel_page_content-analyzer')return;
    wp_enqueue_script('jquery'); wp_enqueue_style('dashicons');
    wp_add_inline_script('jquery','var caData='.json_encode(array('ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ca_nonce'))).';');
});

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
    $pid=absint($_POST['post_id']??0);$phrase=sanitize_text_field($_POST['phrase']??'');
    if(!$pid||empty($phrase))wp_send_json_error('Missing data');
    $post=get_post($pid);if(!$post)wp_send_json_error('Not found');
    $text=ca_get_plain_text($post);$topic_vec=ca_build_tf_vector($phrase);$topic_terms=array_keys($topic_vec);
    $text_lower=mb_strtolower($text);$all_words=preg_split('/[^\p{L}\p{N}\-]+/u',$text_lower,-1,PREG_SPLIT_NO_EMPTY);
    $stop=ca_get_stop_words();$wf=array();
    foreach($all_words as $w){$w=trim($w,'-');if(mb_strlen($w)<3||in_array($w,$stop,true))continue;if(!isset($wf[$w]))$wf[$w]=0;$wf[$w]++;}
    $ws=array();
    foreach($wf as $word=>$count){
        $dm=in_array($word,$topic_terms,true)?1.0:0.0;
        $cv=ca_build_context_vector($text_lower,$word,$all_words);
        $cs=ca_cosine_similarity($topic_vec,$cv);
        $score=round(min(1.0,($dm*0.6)+($cs*0.4)),4);
        $ws[]=array('word'=>$word,'count'=>$count,'direct_match'=>$dm>0,'context_score'=>round($cs*100,1),'relevance_score'=>round($score*100,1));
    }
    usort($ws,function($a,$b){return $b['relevance_score']<=>$a['relevance_score'];});
    $tv=ca_build_tf_vector($text);$os=ca_cosine_similarity($topic_vec,$tv);$tw=count($ws);
    $hi=count(array_filter($ws,function($w){return $w['relevance_score']>=40;}));
    $md=count(array_filter($ws,function($w){return $w['relevance_score']>=15&&$w['relevance_score']<40;}));
    $lo=count(array_filter($ws,function($w){return $w['relevance_score']<15;}));
    $av=$tw>0?round(array_sum(array_column($ws,'relevance_score'))/$tw,1):0;
    wp_send_json_success(array('phrase'=>$phrase,'overall_similarity'=>round($os*100,1),'total_unique_words'=>$tw,
        'high_relevance'=>$hi,'medium_relevance'=>$md,'low_relevance'=>$lo,'average_relevance'=>$av,'words'=>array_slice($ws,0,200)));
});

/* --- AJAX: chunks — all paragraphs vs topic --- */
add_action('wp_ajax_ca_run_chunk_analysis', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('edit_posts'))wp_send_json_error('Unauthorized');
    $pid=absint($_POST['post_id']??0);$phrase=sanitize_text_field($_POST['phrase']??'');
    if(!$pid||empty($phrase))wp_send_json_error('Missing data');
    $post=get_post($pid);if(!$post)wp_send_json_error('Not found');
    $chunks=ca_extract_chunks($post->post_content);$tv=ca_build_tf_vector($phrase);
    $results=array();
    foreach($chunks as $ch){
        $cv=ca_build_tf_vector($ch['text']);$sim=ca_cosine_similarity($tv,$cv);
        $cl=mb_strtolower($ch['text']);$tf=array();
        foreach(array_keys($tv) as $term){$cnt=mb_substr_count($cl,$term);if($cnt>0)$tf[]=array('term'=>$term,'count'=>$cnt);}
        $results[]=array('index'=>$ch['index'],'text'=>$ch['text'],'word_count'=>$ch['word_count'],
            'similarity'=>$sim,'similarity_percent'=>round($sim*100,1),'topic_terms_found'=>$tf);
    }
    usort($results,function($a,$b){return $b['similarity']<=>$a['similarity'];});
    $avg=count($results)>0?array_sum(array_column($results,'similarity'))/count($results):0;
    $mx=count($results)>0?max(array_column($results,'similarity_percent')):0;
    $mn=count($results)>0?min(array_column($results,'similarity_percent')):0;
    wp_send_json_success(array('phrase'=>$phrase,'chunks'=>$results,'chunk_count'=>count($results),
        'average_similarity'=>round($avg,4),'average_percent'=>round($avg*100,1),'max_percent'=>$mx,'min_percent'=>$mn));
});

/* --- AJAX: categories --- */
add_action('wp_ajax_ca_get_categories', function(){
    check_ajax_referer('ca_nonce','nonce');
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
.ca-table tbody tr:hover{background:#f0f6fc}.ca-table tbody tr{cursor:pointer;transition:background .15s}
.ca-row-title{font-weight:600;color:#1d2327;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ca-row-slug{font-size:11px;color:#8c8f94;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px}
.ca-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase}
.ca-badge-post{background:#e7f3ff;color:#2271b1}.ca-badge-page{background:#fef3e7;color:#b36b00}
.ca-headings-mini{display:flex;gap:3px;flex-wrap:wrap}
.ca-heading-badge{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:600;background:#f0f0f1;color:#999;line-height:1.6}
.ca-heading-badge.has-items{background:#e7f3ff;color:#2271b1}
.ca-col-actions{white-space:nowrap}
.ca-action-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;color:#50575e;text-decoration:none;transition:all .15s;cursor:pointer}
.ca-action-btn:hover{color:#2271b1;border-color:#2271b1;background:#f0f7fc}
.ca-action-btn .dashicons{font-size:16px;width:16px;height:16px;line-height:16px}
.ca-detail-header{display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.ca-detail-header h2{flex:1;margin:0;font-size:20px}
.ca-detail-actions{display:flex;gap:8px}
.ca-detail-actions .button .dashicons{font-size:18px;width:18px;height:18px;vertical-align:middle;line-height:18px}
.ca-stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:24px}
.ca-stat-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;text-align:center;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.ca-stat-value{font-size:26px;font-weight:700;color:#1d2327;line-height:1.2}
.ca-stat-label{font-size:11px;color:#50575e;text-transform:uppercase;margin-top:4px;letter-spacing:.3px}
.ca-section{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin-bottom:16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.ca-section h3{margin:0 0 16px;font-size:16px;color:#1d2327;border-bottom:2px solid #2271b1;padding-bottom:8px}
.ca-heading-level{margin-bottom:12px}.ca-heading-level-label{font-weight:600;font-size:13px;color:#2271b1;margin-bottom:4px}
.ca-heading-level-items{list-style:none;padding-left:20px;margin:0}
.ca-heading-level-items li{padding:3px 0;color:#50575e;position:relative}
.ca-heading-level-items li::before{content:'—';position:absolute;left:-16px;color:#c3c4c7}
.ca-entities-controls{margin-bottom:12px;display:flex;align-items:center;gap:8px}
.ca-entities-controls input{width:70px;height:30px;text-align:center}
.ca-entity-table,.ca-word-table{width:100%;border-collapse:collapse;font-size:13px}
.ca-entity-table thead th,.ca-word-table thead th{background:#f6f7f7;padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#50575e;font-weight:600;border-bottom:1px solid #c3c4c7}
.ca-entity-table tbody td,.ca-word-table tbody td{padding:6px 12px;border-bottom:1px solid #f0f0f1}
.ca-entity-bar{background:#e7f3ff;height:6px;border-radius:3px;display:inline-block;vertical-align:middle}
.ca-phrase-input-wrap{display:flex;gap:8px;margin-bottom:16px}
.ca-phrase-input-wrap input{flex:1;height:36px;padding:4px 12px;border:1px solid #8c8f94;border-radius:4px;font-size:14px}
.ca-similarity-score{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:16px;background:#f6f7f7;border-radius:6px;flex-wrap:wrap}
.ca-score-circle{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;flex-shrink:0}
.ca-score-low{background:#d63638}.ca-score-medium{background:#dba617}.ca-score-good{background:#00a32a}.ca-score-great{background:#007017}
.ca-score-detail{font-size:14px;color:#50575e;flex:1}.ca-score-detail strong{color:#1d2327}
.ca-mini-stats{display:flex;gap:10px;flex-wrap:wrap}
.ca-mini-stat{text-align:center;padding:8px 12px;background:#fff;border-radius:6px;border:1px solid #e0e0e0}
.ca-mini-stat-val{font-size:18px;font-weight:700;color:#1d2327}.ca-mini-stat-lbl{font-size:10px;color:#50575e;text-transform:uppercase;margin-top:2px}
.ca-relevance-bar{height:8px;border-radius:4px;display:inline-block;vertical-align:middle;min-width:4px}
.ca-rel-high{background:#00a32a}.ca-rel-medium{background:#dba617}.ca-rel-low{background:#d63638}
.ca-direct-yes{color:#00a32a;font-weight:700}.ca-direct-no{color:#ccc}
.ca-word-filter-wrap{display:flex;gap:8px;margin-bottom:12px;align-items:center;flex-wrap:wrap}
.ca-word-filter-wrap input{height:32px;padding:4px 10px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;width:200px}
.ca-word-filter-wrap select{height:32px;border:1px solid #8c8f94;border-radius:4px;font-size:13px}
.ca-chunk-summary{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.ca-chunk-item{border:1px solid #e0e0e0;border-radius:6px;padding:14px;margin-bottom:10px;background:#fff;transition:border-color .15s}
.ca-chunk-item:hover{border-color:#2271b1}
.ca-chunk-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ca-chunk-index{font-weight:600;font-size:12px;color:#2271b1;background:#e7f3ff;padding:2px 8px;border-radius:4px}
.ca-chunk-score{font-weight:700;font-size:14px;padding:3px 10px;border-radius:4px}
.ca-chunk-score-low{background:#fce4e4;color:#d63638}.ca-chunk-score-medium{background:#fef3e7;color:#b36b00}
.ca-chunk-score-good{background:#e7f5e7;color:#00a32a}.ca-chunk-score-great{background:#d4f0d4;color:#007017}
.ca-chunk-text{font-size:13px;color:#50575e;line-height:1.6;margin-top:6px}
.ca-chunk-meta{margin-top:6px;font-size:11px;color:#8c8f94}
.ca-chunk-terms{margin-top:6px;display:flex;gap:4px;flex-wrap:wrap}
.ca-chunk-term-badge{font-size:11px;background:#e7f3ff;color:#2271b1;padding:1px 6px;border-radius:3px;font-weight:600}
.ca-progress-bar{width:100%;height:8px;background:#f0f0f1;border-radius:4px;overflow:hidden;margin-top:6px}
.ca-progress-fill{height:100%;border-radius:4px;transition:width .4s ease}
.ca-no-data{text-align:center;padding:40px;color:#8c8f94;font-size:14px}
@media(max-width:1200px){.ca-filter-row{flex-direction:column}.ca-filter-group input,.ca-filter-group select{min-width:auto;width:100%}.ca-stats-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}.ca-mini-stats{width:100%}}
</style>

<div class="wrap ca-wrap">
<h1 class="ca-page-title"><span class="dashicons dashicons-chart-bar"></span> Content Analyzer Pro</h1>

<div id="ca-list-view">
<div class="ca-filters">
<div class="ca-filter-row">
<div class="ca-filter-group"><label>Szukaj (tytuł/link)</label><input type="text" id="ca-filter-search" placeholder="Wpisz frazę..."></div>
<div class="ca-filter-group"><label>Typ treści</label><select id="ca-filter-type"><option value="">Wszystkie</option><option value="post">Wpis</option><option value="page">Strona</option></select></div>
<div class="ca-filter-group"><label>Kategoria</label><select id="ca-filter-category"><option value="">Wszystkie</option></select></div>
<div class="ca-filter-group"><label>Publikacja od</label><input type="date" id="ca-filter-date-from"></div>
<div class="ca-filter-group"><label>Publikacja do</label><input type="date" id="ca-filter-date-to"></div>
<div class="ca-filter-group"><label>Aktualizacja od</label><input type="date" id="ca-filter-modified-from"></div>
<div class="ca-filter-group"><label>Aktualizacja do</label><input type="date" id="ca-filter-modified-to"></div>
</div>
<div class="ca-filter-actions"><button type="button" id="ca-reset-filters" class="button">Resetuj filtry</button><span id="ca-results-count" class="ca-results-count"></span></div>
</div>
<div id="ca-loading" class="ca-loading" style="display:none"><span class="spinner is-active"></span> Ładowanie danych...</div>
<div class="ca-table-wrap">
<table class="ca-table widefat striped"><thead><tr>
<th class="ca-sortable" data-sort="title">Tytuł <span class="ca-sort-icon"></span></th>
<th class="ca-sortable" data-sort="post_type">Typ <span class="ca-sort-icon"></span></th>
<th>Kategoria</th>
<th class="ca-sortable" data-sort="char_count">Znaki <span class="ca-sort-icon"></span></th>
<th class="ca-sortable" data-sort="word_count">Słowa <span class="ca-sort-icon"></span></th>
<th class="ca-sortable" data-sort="paragraph_count">Akapity <span class="ca-sort-icon"></span></th>
<th>Nagłówki</th>
<th class="ca-sortable" data-sort="date_published">Publikacja <span class="ca-sort-icon"></span></th>
<th class="ca-sortable" data-sort="date_modified">Aktualizacja <span class="ca-sort-icon"></span></th>
<th class="ca-col-actions">Akcje</th>
</tr></thead><tbody id="ca-posts-tbody"></tbody></table>
</div></div>

<div id="ca-detail-view" style="display:none">
<div class="ca-detail-header">
<button type="button" id="ca-back-to-list" class="button"><span class="dashicons dashicons-arrow-left-alt2"></span> Powrót do listy</button>
<h2 id="ca-detail-title"></h2>
<div class="ca-detail-actions">
<a id="ca-detail-view-link" href="#" target="_blank" class="button" title="Podgląd"><span class="dashicons dashicons-visibility"></span></a>
<a id="ca-detail-edit-link" href="#" target="_blank" class="button" title="Edytuj"><span class="dashicons dashicons-edit"></span></a>
</div></div>
<div id="ca-detail-loading" class="ca-loading" style="display:none"><span class="spinner is-active"></span> Analizowanie treści...</div>
<div class="ca-stats-grid" id="ca-stats-grid"></div>
<div class="ca-section"><h3>Struktura nagłówków</h3><div id="ca-headings-detail"></div></div>
<div class="ca-section"><h3>Encje (najczęstsze terminy)</h3>
<div class="ca-entities-controls"><input type="number" id="ca-entities-limit" value="30" min="5" max="150" step="5"><label>wyświetlanych encji</label></div>
<div id="ca-entities-list"></div></div>
<div class="ca-section"><h3>Analiza embeddingowa — trafność słów do tematu</h3>
<p style="color:#50575e;font-size:13px;margin-top:-8px">Każde unikalne słowo w treści oceniane pod kątem trafności do tematu (0–100%). Wynik = dopasowanie bezpośrednie (60%) + podobieństwo kontekstowe (40%).</p>
<div class="ca-phrase-input-wrap"><input type="text" id="ca-embedding-phrase" placeholder="Wpisz temat/frazę..."><button type="button" id="ca-run-embedding" class="button button-primary">Analizuj słowa</button></div>
<div id="ca-embedding-results"></div></div>
<div class="ca-section"><h3>Analiza chunków — trafność akapitów do tematu</h3>
<p style="color:#50575e;font-size:13px;margin-top:-8px">Każdy akapit porównywany z tematem (cosine similarity na wektorach TF). Wynik liczbowy pokazuje jak mocno akapit pasuje do tematu.</p>
<div class="ca-phrase-input-wrap"><input type="text" id="ca-chunk-phrase" placeholder="Wpisz temat/frazę..."><button type="button" id="ca-run-chunk" class="button button-primary">Analizuj akapity</button></div>
<div id="ca-chunk-results"></div></div>
</div></div>

<script>
(function($){
'use strict';
var allPosts=[],filteredPosts=[],currentSort={key:'date_published',dir:'desc'},currentPostId=null,currentAnalysis=null,embWords=[];
$(document).ready(function(){loadCats();loadPosts();bindEvents()});

function loadCats(){$.post(caData.ajaxUrl,{action:'ca_get_categories',nonce:caData.nonce},function(r){if(r.success&&r.data){var s=$('#ca-filter-category');r.data.forEach(function(c){s.append('<option value="'+E(c.name)+'">'+E(c.name)+'</option>')})}})}
function loadPosts(){$('#ca-loading').show();$('#ca-posts-tbody').empty();$.post(caData.ajaxUrl,{action:'ca_get_posts_list',nonce:caData.nonce},function(r){$('#ca-loading').hide();if(r.success&&r.data){allPosts=r.data;applyFilters()}}).fail(function(){$('#ca-loading').hide()})}

function applyFilters(){
    var s=$('#ca-filter-search').val().toLowerCase().trim(),t=$('#ca-filter-type').val(),c=$('#ca-filter-category').val(),
    df=$('#ca-filter-date-from').val(),dt=$('#ca-filter-date-to').val(),mf=$('#ca-filter-modified-from').val(),mt=$('#ca-filter-modified-to').val();
    filteredPosts=allPosts.filter(function(p){
        if(s&&!p.title.toLowerCase().includes(s)&&!(p.slug||'').toLowerCase().includes(s)&&!(p.url||'').toLowerCase().includes(s))return false;
        if(t&&p.post_type!==t)return false;if(c&&(!p.categories||!p.categories.includes(c)))return false;
        if(df&&p.date_published.substring(0,10)<df)return false;if(dt&&p.date_published.substring(0,10)>dt)return false;
        if(mf&&p.date_modified.substring(0,10)<mf)return false;if(mt&&p.date_modified.substring(0,10)>mt)return false;return true;
    });doSort();renderTable();$('#ca-results-count').text('Wyników: '+filteredPosts.length+' z '+allPosts.length);
}
function doSort(){var k=currentSort.key,d=currentSort.dir==='asc'?1:-1;filteredPosts.sort(function(a,b){var va=a[k],vb=b[k];if(typeof va==='string')return(va||'').toLowerCase().localeCompare((vb||'').toLowerCase())*d;return((va||0)-(vb||0))*d})}

function renderTable(){
    var $tb=$('#ca-posts-tbody');$tb.empty();
    if(!filteredPosts.length){$tb.html('<tr><td colspan="10" class="ca-no-data">Brak wyników.</td></tr>');return}
    filteredPosts.forEach(function(p){
        var hb='';for(var i=1;i<=6;i++){var k='h'+i,n=p.headings[k]?p.headings[k].length:0;hb+='<span class="ca-heading-badge'+(n?' has-items':'')+'">H'+i+':'+n+'</span>'}
        var tl=p.post_type==='post'?'Wpis':'Strona',tc=p.post_type==='post'?'ca-badge-post':'ca-badge-page';
        $tb.append('<tr data-post-id="'+p.post_id+'"><td><div class="ca-row-title">'+E(p.title||'(brak)')+'</div><div class="ca-row-slug">/'+E(p.slug||'')+'</div></td>'+
            '<td><span class="ca-badge '+tc+'">'+tl+'</span></td><td>'+E((p.categories||[]).join(', ')||'—')+'</td>'+
            '<td>'+NF(p.char_count)+'</td><td>'+NF(p.word_count)+'</td><td>'+p.paragraph_count+'</td>'+
            '<td><div class="ca-headings-mini">'+hb+'</div></td>'+
            '<td>'+p.date_published.substring(0,10)+'</td><td>'+p.date_modified.substring(0,10)+'</td>'+
            '<td class="ca-col-actions"><a href="'+p.url+'" target="_blank" class="ca-action-btn" title="Podgląd" onclick="event.stopPropagation()"><span class="dashicons dashicons-visibility"></span></a> '+
            '<a href="'+p.edit_url+'" target="_blank" class="ca-action-btn" title="Edytuj" onclick="event.stopPropagation()"><span class="dashicons dashicons-edit"></span></a></td></tr>');
    });
    $('.ca-sortable').removeClass('asc desc');$('.ca-sortable[data-sort="'+currentSort.key+'"]').addClass(currentSort.dir);
}

function bindEvents(){
    $('#ca-filter-search').on('input',DB(applyFilters,300));
    $('#ca-filter-type,#ca-filter-category').on('change',applyFilters);
    $('#ca-filter-date-from,#ca-filter-date-to,#ca-filter-modified-from,#ca-filter-modified-to').on('change',applyFilters);
    $('#ca-reset-filters').on('click',function(){$('#ca-filter-search').val('');$('#ca-filter-type,#ca-filter-category').val('');$('input[type=date]').val('');applyFilters()});
    $(document).on('click','.ca-sortable',function(){var k=$(this).data('sort');if(currentSort.key===k)currentSort.dir=currentSort.dir==='asc'?'desc':'asc';else{currentSort.key=k;currentSort.dir='asc'}doSort();renderTable()});
    $(document).on('click','#ca-posts-tbody tr[data-post-id]',function(){openDetail($(this).data('post-id'))});
    $('#ca-back-to-list').on('click',function(){$('#ca-detail-view').hide();$('#ca-list-view').show()});
    $('#ca-run-embedding').on('click',runEmb);$('#ca-embedding-phrase').on('keypress',function(e){if(e.which===13)runEmb()});
    $('#ca-run-chunk').on('click',runChk);$('#ca-chunk-phrase').on('keypress',function(e){if(e.which===13)runChk()});
    $('#ca-entities-limit').on('change',function(){if(currentAnalysis)renderEntities(currentAnalysis.entities,parseInt($(this).val())||30)});
}

function openDetail(id){
    currentPostId=id;$('#ca-list-view').hide();$('#ca-detail-view').show();$('#ca-detail-loading').show();
    $('#ca-stats-grid,#ca-headings-detail,#ca-entities-list,#ca-embedding-results,#ca-chunk-results').empty();
    $.post(caData.ajaxUrl,{action:'ca_get_post_detail',nonce:caData.nonce,post_id:id},function(r){$('#ca-detail-loading').hide();if(r.success&&r.data){currentAnalysis=r.data;renderDetail(r.data)}});
}
function renderDetail(d){
    $('#ca-detail-title').text(d.title||'(brak)');$('#ca-detail-view-link').attr('href',d.url);$('#ca-detail-edit-link').attr('href',d.edit_url);
    var stats=[{v:NF(d.char_count),l:'Znaków'},{v:NF(d.char_count_no_spaces),l:'Bez spacji'},{v:NF(d.word_count),l:'Słów'},{v:d.sentence_count,l:'Zdań'},
        {v:d.paragraph_count,l:'Akapitów'},{v:d.headings.total,l:'Nagłówków'},{v:d.reading_time+' min',l:'Czas czytania'},{v:d.post_type==='post'?'Wpis':'Strona',l:'Typ'}];
    var $g=$('#ca-stats-grid');stats.forEach(function(s){$g.append('<div class="ca-stat-card"><div class="ca-stat-value">'+s.v+'</div><div class="ca-stat-label">'+s.l+'</div></div>')});
    renderHeadings(d.headings);renderEntities(d.entities,parseInt($('#ca-entities-limit').val())||30);
    if(d.focus_phrase){$('#ca-embedding-phrase').val(d.focus_phrase);$('#ca-chunk-phrase').val(d.focus_phrase);runEmb();runChk()}
}
function renderHeadings(h){var $c=$('#ca-headings-detail'),any=false;for(var i=1;i<=6;i++){var k='h'+i,items=h[k]||[];if(!items.length)continue;any=true;
    var html='<div class="ca-heading-level"><div class="ca-heading-level-label">H'+i+' ('+items.length+')</div><ul class="ca-heading-level-items">';
    items.forEach(function(t){html+='<li>'+E(t)+'</li>'});html+='</ul></div>';$c.append(html)}if(!any)$c.html('<p class="ca-no-data">Brak nagłówków.</p>')}
function renderEntities(ent,limit){var $c=$('#ca-entities-list');$c.empty();if(!ent||!ent.length){$c.html('<p class="ca-no-data">Brak danych.</p>');return}
    var d=ent.slice(0,limit),mx=d[0].count;var html='<table class="ca-entity-table"><thead><tr><th>#</th><th>Termin</th><th>Wystąpienia</th><th>Częstotliwość</th><th>Wykres</th></tr></thead><tbody>';
    d.forEach(function(e,i){var bw=Math.max(5,Math.round(e.count/mx*100));html+='<tr><td>'+(i+1)+'</td><td><strong>'+E(e.term)+'</strong></td><td>'+e.count+'</td><td>'+e.frequency+'%</td><td><span class="ca-entity-bar" style="width:'+bw+'%"></span></td></tr>'});
    html+='</tbody></table>';$c.html(html)}

function runEmb(){var ph=$('#ca-embedding-phrase').val().trim();if(!ph||!currentPostId)return;
    var $r=$('#ca-embedding-results');$r.html('<div class="ca-loading"><span class="spinner is-active"></span> Analizowanie słów względem tematu...</div>');
    $.post(caData.ajaxUrl,{action:'ca_run_embedding_analysis',nonce:caData.nonce,post_id:currentPostId,phrase:ph},function(res){if(res.success&&res.data)renderEmbRes(res.data);else $r.html('<p class="ca-no-data">Błąd.</p>')})}

function renderEmbRes(d){var $r=$('#ca-embedding-results');$r.empty();embWords=d.words||[];
    var pct=d.overall_similarity,sc=pct<15?'ca-score-low':pct<30?'ca-score-medium':pct<50?'ca-score-good':'ca-score-great';
    var html='<div class="ca-similarity-score"><div class="ca-score-circle '+sc+'">'+pct+'%</div>';
    html+='<div class="ca-score-detail"><strong>Ogólne podobieństwo treści do tematu:</strong> „'+E(d.phrase)+'"<br><strong>Średnia trafność słów:</strong> '+d.average_relevance+'%</div>';
    html+='<div class="ca-mini-stats">';
    html+='<div class="ca-mini-stat"><div class="ca-mini-stat-val" style="color:#00a32a">'+d.high_relevance+'</div><div class="ca-mini-stat-lbl">Wysoka ≥40%</div></div>';
    html+='<div class="ca-mini-stat"><div class="ca-mini-stat-val" style="color:#dba617">'+d.medium_relevance+'</div><div class="ca-mini-stat-lbl">Średnia 15-39%</div></div>';
    html+='<div class="ca-mini-stat"><div class="ca-mini-stat-val" style="color:#d63638">'+d.low_relevance+'</div><div class="ca-mini-stat-lbl">Niska &lt;15%</div></div>';
    html+='<div class="ca-mini-stat"><div class="ca-mini-stat-val">'+d.total_unique_words+'</div><div class="ca-mini-stat-lbl">Łącznie</div></div>';
    html+='</div></div>';
    html+='<div class="ca-word-filter-wrap"><input type="text" id="ca-word-filter" placeholder="Filtruj słowa..."><select id="ca-word-level-filter"><option value="">Wszystkie poziomy</option><option value="high">Wysoka ≥40%</option><option value="medium">Średnia 15-39%</option><option value="low">Niska &lt;15%</option></select></div>';
    html+='<div id="ca-word-table-wrap"></div>';$r.html(html);renderWordTbl(embWords);
    $('#ca-word-filter').on('input',DB(filterW,200));$('#ca-word-level-filter').on('change',filterW)}

function filterW(){var s=$('#ca-word-filter').val().toLowerCase().trim(),lv=$('#ca-word-level-filter').val();
    var f=embWords.filter(function(w){if(s&&!w.word.includes(s))return false;if(lv==='high'&&w.relevance_score<40)return false;
        if(lv==='medium'&&(w.relevance_score<15||w.relevance_score>=40))return false;if(lv==='low'&&w.relevance_score>=15)return false;return true});renderWordTbl(f)}

function renderWordTbl(words){var $w=$('#ca-word-table-wrap');if(!words.length){$w.html('<p class="ca-no-data">Brak słów.</p>');return}
    var html='<table class="ca-word-table"><thead><tr><th>#</th><th>Słowo</th><th>Wystąpienia</th><th>Trafność do tematu</th><th>Kontekst</th><th>Bezpośrednie</th><th>Wykres</th></tr></thead><tbody>';
    words.forEach(function(w,i){var rc=w.relevance_score>=40?'ca-rel-high':w.relevance_score>=15?'ca-rel-medium':'ca-rel-low';
        var dm=w.direct_match?'<span class="ca-direct-yes">✓ TAK</span>':'<span class="ca-direct-no">—</span>';
        html+='<tr><td>'+(i+1)+'</td><td><strong>'+E(w.word)+'</strong></td><td>'+w.count+'</td><td><strong>'+w.relevance_score+'%</strong></td><td>'+w.context_score+'%</td><td>'+dm+'</td><td><span class="ca-relevance-bar '+rc+'" style="width:'+Math.max(4,w.relevance_score)+'%"></span></td></tr>'});
    html+='</tbody></table>';$w.html(html)}

function runChk(){var ph=$('#ca-chunk-phrase').val().trim();if(!ph||!currentPostId)return;
    var $r=$('#ca-chunk-results');$r.html('<div class="ca-loading"><span class="spinner is-active"></span> Analizowanie akapitów...</div>');
    $.post(caData.ajaxUrl,{action:'ca_run_chunk_analysis',nonce:caData.nonce,post_id:currentPostId,phrase:ph},function(res){if(res.success&&res.data)renderChkRes(res.data);else $r.html('<p class="ca-no-data">Błąd.</p>')})}

function renderChkRes(d){var $r=$('#ca-chunk-results');$r.empty();
    var html='<div class="ca-chunk-summary">';
    html+='<div class="ca-stat-card"><div class="ca-stat-value">'+d.average_percent+'%</div><div class="ca-stat-label">Średnie podobieństwo</div></div>';
    html+='<div class="ca-stat-card"><div class="ca-stat-value">'+d.max_percent+'%</div><div class="ca-stat-label">Najwyższy</div></div>';
    html+='<div class="ca-stat-card"><div class="ca-stat-value">'+d.min_percent+'%</div><div class="ca-stat-label">Najniższy</div></div>';
    html+='<div class="ca-stat-card"><div class="ca-stat-value">'+d.chunk_count+'</div><div class="ca-stat-label">Akapitów</div></div>';
    html+='<div class="ca-stat-card"><div class="ca-stat-value" style="font-size:14px">'+E(d.phrase)+'</div><div class="ca-stat-label">Temat</div></div></div>';
    if(d.chunks&&d.chunks.length){d.chunks.forEach(function(ch){var pct=ch.similarity_percent;
        var sc=pct<10?'ca-chunk-score-low':pct<25?'ca-chunk-score-medium':pct<50?'ca-chunk-score-good':'ca-chunk-score-great';
        var bc=pct<10?'#d63638':pct<25?'#dba617':pct<50?'#00a32a':'#007017';
        html+='<div class="ca-chunk-item"><div class="ca-chunk-header"><span class="ca-chunk-index">Akapit #'+(ch.index+1)+'</span><span class="ca-chunk-score '+sc+'">'+pct+'% trafności</span></div>';
        html+='<div class="ca-progress-bar"><div class="ca-progress-fill" style="width:'+pct+'%;background:'+bc+'"></div></div>';
        html+='<div class="ca-chunk-text">'+E(ch.text.length>400?ch.text.substring(0,400)+'…':ch.text)+'</div>';
        if(ch.topic_terms_found&&ch.topic_terms_found.length){html+='<div class="ca-chunk-terms">';ch.topic_terms_found.forEach(function(t){html+='<span class="ca-chunk-term-badge">'+E(t.term)+' (×'+t.count+')</span>'});html+='</div>'}
        html+='<div class="ca-chunk-meta">'+ch.word_count+' słów | Cosine similarity: '+ch.similarity+'</div></div>'})
    }else{html+='<p class="ca-no-data">Brak akapitów.</p>'}$r.html(html)}

function E(s){return s?$('<span>').text(s).html():''}
function NF(n){return(n||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ')}
function DB(fn,d){var t;return function(){var a=arguments,c=this;clearTimeout(t);t=setTimeout(function(){fn.apply(c,a)},d)}}
})(jQuery);
</script>
<?php
}

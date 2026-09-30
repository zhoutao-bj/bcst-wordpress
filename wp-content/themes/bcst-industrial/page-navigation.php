<?php
defined('ABSPATH') || exit;

function bcst_nav_source_id($id) {
    if (function_exists('pll_get_post') && function_exists('pll_default_language')) return (int)(pll_get_post($id,pll_default_language()) ?: $id);
    return (int)$id;
}
function bcst_nav_privacy($id) {
    $privacy=(int)get_option('wp_page_for_privacy_policy');
    return $privacy && bcst_nav_source_id($privacy)===bcst_nav_source_id($id);
}
function bcst_nav_public_page($id) {
    if (!$id) return null;
    $page=get_post($id);
    return $page && $page->post_type==='page' && $page->post_status==='publish' ? $page : null;
}
function bcst_nav_local_page($id) {
    if (function_exists('pll_current_language') && function_exists('pll_get_post')) {
        $lang=pll_current_language();
        $translated=$lang ? pll_get_post($id,$lang) : 0;
        if ($translated) return bcst_nav_public_page($translated); // Never expose a draft translation.
    }
    return bcst_nav_public_page($id);
}
// Slug lookup must still work after Contact/Resources become nested pages.
function bcst_nav_find_page($slug) {
    $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','post_name__in'=>array($slug),'posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','lang'=>'','suppress_filters'=>true));
    if (!$pages) return null;
    foreach($pages as $page) if (bcst_nav_source_id($page->ID)===$page->ID) return $page;
    return $pages[0];
}
function bcst_nav_page_config($id) {
    if (bcst_nav_privacy($id)) return array('mode'=>'body','category'=>0,'article'=>0);
    if (function_exists('bcst_page_display_settings')) return bcst_page_display_settings($id);
    return array('mode'=>'body','category'=>0,'article'=>0);
}
function bcst_nav_article_target($page_id) {
    if (post_password_required($page_id)) return 0;
    $config=bcst_nav_page_config($page_id);
    if ($config['mode']!=='article') return 0;
    $id=$config['article'];
    if ($id && function_exists('pll_get_post_language') && function_exists('pll_get_post')) {
        $lang=pll_get_post_language($page_id);
        if ($lang && pll_get_post_language($id)!==$lang) $id=(int)pll_get_post($id,$lang);
    }
    return $id && get_post_type($id)==='post' && get_post_status($id)==='publish' ? (int)$id : 0;
}
function bcst_nav_page_url($id) {
    $target=bcst_nav_article_target($id);
    return get_permalink($target ?: $id);
}
function bcst_nav_about_tree() {
    $id=absint(get_option('bcst_navigation_root',0));
    if (!$id) { $page=bcst_nav_find_page('about'); $id=$page ? $page->ID : 0; }
    $id=bcst_nav_source_id($id);
    $root=bcst_nav_public_page($id);
    $home=bcst_nav_source_id((int)get_option('page_on_front'));
    if (!$root || bcst_nav_privacy($id) || $id===$home) return array();
    $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>array('menu_order'=>'ASC','title'=>'ASC','ID'=>'ASC'),'lang'=>'','suppress_filters'=>true));
    $children=array();
    foreach($pages as $page) {
        if (bcst_nav_source_id($page->ID)!==$page->ID || bcst_nav_privacy($page->ID) || $page->ID===$home) continue;
        $children[(int)$page->post_parent][]=$page;
    }
    $build=function ($page,$seen=array()) use (&$build,$children) {
        if (isset($seen[$page->ID])) return null;
        $seen[$page->ID]=true;
        $local=bcst_nav_local_page($page->ID);
        if (!$local) return null;
        $items=array();
        foreach($children[$page->ID] ?? array() as $child) {
            $node=$build($child,$seen);
            if ($node) $items[]=$node;
        }
        return array('label'=>$local->post_title,'url'=>bcst_nav_page_url($local->ID),'children'=>$items);
    };
    $node=$build($root);
    return $node ? array($node) : array();
}
add_action('template_redirect',function () {
    if (!is_page() || is_front_page() || is_preview() || is_feed() || is_embed() || post_password_required()) return;
    $target=bcst_nav_article_target(get_queried_object_id());
    if ($target) { wp_safe_redirect(get_permalink($target),302); exit; }
});
function bcst_page_category_list($page_id) {
    $config=bcst_nav_page_config($page_id);
    if ($config['mode']!=='category' || post_password_required($page_id)) return;
    $term_id=$config['category'];
    $lang=function_exists('pll_get_post_language') ? pll_get_post_language($page_id) : '';
    if ($term_id && $lang && function_exists('pll_get_term_language') && function_exists('pll_get_term') && pll_get_term_language($term_id)!==$lang) $term_id=(int)pll_get_term($term_id,$lang);
    if (!$term_id || !term_exists($term_id,'category')) {
        echo '<p>'.esc_html(bcst_t('No items found.')).'</p>'; return;
    }
    $paged=isset($_GET['bcst_pg']) && is_scalar($_GET['bcst_pg']) ? max(1,absint($_GET['bcst_pg'])) : 1;
    $args=array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>9,'paged'=>$paged,'ignore_sticky_posts'=>true,'orderby'=>array('date'=>'DESC','ID'=>'DESC'),'tax_query'=>array(array('taxonomy'=>'category','field'=>'term_id','terms'=>array($term_id),'include_children'=>true)));
    if ($lang) $args['lang']=$lang;
    $query=new WP_Query($args);
    echo '<div class="grid">';
    while($query->have_posts()) { $query->the_post(); bcst_card(); }
    if (!$query->post_count) echo '<p>'.esc_html(bcst_t('No items found.')).'</p>';
    echo '</div>';
    wp_reset_postdata();
    if ($query->max_num_pages>1) {
        $base=str_replace('999999999','%#%',esc_url(add_query_arg('bcst_pg',999999999,get_permalink($page_id))));
        echo '<nav class="pagination" aria-label="'.esc_attr(bcst_t('Pagination')).'">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$paged,'total'=>$query->max_num_pages,'prev_text'=>'←','next_text'=>'→','type'=>'list'))).'</nav>';
    }
}

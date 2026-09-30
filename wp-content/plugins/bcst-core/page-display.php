<?php
defined('ABSPATH') || exit;

// These settings do not create, delete or publish any content during deployment.
add_action('admin_init', function () {
    register_setting('bcst_settings', 'bcst_navigation_root', array(
        'type'=>'integer', 'default'=>0, 'sanitize_callback'=>function ($value) {
            $id=is_scalar($value) ? absint($value) : 0;
            if (!$id || (get_post_type($id)==='page' && get_post_status($id)==='publish' && $id!==(int)get_option('wp_page_for_privacy_policy') && $id!==(int)get_option('page_on_front'))) return $id;
            add_settings_error('bcst_navigation_root','invalid_root','请选择已发布的普通页面作为 About 根页面，不能选择首页或隐私政策。');
            return absint(get_option('bcst_navigation_root',0));
        },
    ));
});
function bcst_navigation_root_control() {
    echo '<h2>About 导航根页面</h2><label for="bcst-navigation-root">导航读取这个页面及其已发布的子孙页面</label>';
    wp_dropdown_pages(array('name'=>'bcst_navigation_root','id'=>'bcst-navigation-root','selected'=>get_option('bcst_navigation_root',0),'show_option_none'=>'自动查找 About（别名 about）','option_none_value'=>0,'post_status'=>'publish','lang'=>function_exists('pll_default_language') ? pll_default_language() : ''));
    echo '<p class="description">在页面编辑器的“页面属性”中设置父级和排序。以主语言层级为骨架，各语言使用关联译文；尚无译文时链接回主语言，译文为草稿时不显示该项。首页、隐私政策不加入 About 树。新增页面不会自动加入，需指定 About 或其子页面为父级。</p>';
}
function bcst_page_is_privacy($id) {
    $privacy=absint(get_option('wp_page_for_privacy_policy',0));
    if (!$privacy) return false;
    if ($privacy===(int)$id) return true;
    return function_exists('pll_get_post_translations') && in_array((int)$id,array_map('intval',pll_get_post_translations($privacy)),true);
}
function bcst_page_display_settings($id) {
    $source=$id;
    if (!metadata_exists('post',$id,'_bcst_page_mode') && function_exists('pll_get_post') && function_exists('pll_default_language')) {
        $source=pll_get_post($id,pll_default_language()) ?: $id;
    }
    $mode=get_post_meta($source,'_bcst_page_mode',true);
    if (!in_array($mode,array('body','category','article'),true) || bcst_page_is_privacy($id)) $mode='body';
    return array('mode'=>$mode,'category'=>absint(get_post_meta($source,'_bcst_page_category',true)),'article'=>absint(get_post_meta($source,'_bcst_page_article',true)));
}
add_action('add_meta_boxes_page',function ($post) {
    add_meta_box('bcst-page-display','页面展示方式 / 导航目标','bcst_page_display_box','page','normal','high');
});
function bcst_page_display_box($post) {
    if (bcst_page_is_privacy($post->ID)) {
        echo '<p>隐私政策固定展示本页正文，不绑定分类或文章。发布后在页脚显示，不加入 About 导航。</p>'; return;
    }
    $settings=bcst_page_display_settings($post->ID);
    $lang=function_exists('pll_get_post_language') ? pll_get_post_language($post->ID) : '';
    wp_nonce_field('bcst_page_display','bcst_page_display_nonce');
    echo '<p><label for="bcst-page-mode">展示方式</label> <select id="bcst-page-mode" name="bcst_page_mode">';
    foreach(array('body'=>'页面正文','category'=>'分类文章列表','article'=>'指定文章（点击直接跳转）') as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($settings['mode'],$value,false).'>'.esc_html($label).'</option>';
    echo '</select></p><p><label for="bcst-page-category">文章分类（仅分类列表模式使用）</label> ';
    // Include all languages so inherited source IDs remain visible and are not silently lost on save.
    $terms=get_terms(array('taxonomy'=>'category','hide_empty'=>false,'lang'=>''));
    echo '<select id="bcst-page-category" name="bcst_page_category"><option value="0">请选择分类</option>';
    if (!is_wp_error($terms)) foreach($terms as $term) {
        $term_lang=function_exists('pll_get_term_language') ? pll_get_term_language($term->term_id) : '';
        echo '<option value="'.esc_attr($term->term_id).'" '.selected($settings['category'],$term->term_id,false).'>'.esc_html($term->name.($term_lang ? ' ['.$term_lang.']' : '').' #'.$term->term_id).'</option>';
    }
    echo '</select></p><p><label for="bcst-page-article">指定文章（仅直接跳转模式使用）</label> <select id="bcst-page-article" name="bcst_page_article"><option value="0">请选择文章</option>';
    $articles=get_posts(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','lang'=>'','suppress_filters'=>true));
    foreach($articles as $article) {
        $article_lang=function_exists('pll_get_post_language') ? pll_get_post_language($article->ID) : '';
        echo '<option value="'.esc_attr($article->ID).'" '.selected($settings['article'],$article->ID,false).'>'.esc_html($article->post_title.($article_lang ? ' ['.$article_lang.']' : '').' #'.$article->ID).'</option>';
    }
    echo '</select></p><p class="description">分类列表保留本页面的标题、地址和正文，下面显示对应分类（包含子分类）的已发布文章。指定文章模式使用临时跳转，不接受外部网址。译文优先使用本页语言对应的分类/文章；缺少对应译文时不混入其他语言内容。</p><p class="description">导航父级和顺序请在“页面属性”中设置。首页仍使用首页模板；WordPress 指定的“文章页”仍使用文章列表模板，需先在“设置 → 阅读”中解除该指定，才能使用这里的展示方式。</p>';
}
add_action('save_post_page',function ($id) {
    if (wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post',$id)) return;
    if (!isset($_POST['bcst_page_display_nonce']) || !is_string($_POST['bcst_page_display_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bcst_page_display_nonce'])),'bcst_page_display')) return;
    if (bcst_page_is_privacy($id)) return;
    $mode=isset($_POST['bcst_page_mode']) && is_string($_POST['bcst_page_mode']) ? sanitize_key($_POST['bcst_page_mode']) : 'body';
    if (!in_array($mode,array('body','category','article'),true)) $mode='body';
    $category=isset($_POST['bcst_page_category']) && is_scalar($_POST['bcst_page_category']) ? absint($_POST['bcst_page_category']) : 0;
    $article=isset($_POST['bcst_page_article']) && is_scalar($_POST['bcst_page_article']) ? absint($_POST['bcst_page_article']) : 0;
    if (($mode==='category' && (!$category || !term_exists($category,'category'))) || ($mode==='article' && (get_post_type($article)!=='post' || get_post_status($article)!=='publish'))) {
        add_filter('redirect_post_location',function ($url) { return add_query_arg('bcst_page_error',1,$url); });
        return; // Keep the previous working configuration on invalid input.
    }
    update_post_meta($id,'_bcst_page_mode',$mode);
    update_post_meta($id,'_bcst_page_category',$category);
    update_post_meta($id,'_bcst_page_article',$article);
});
add_action('admin_notices',function () {
    if (!empty($_GET['bcst_page_error'])) echo '<div class="notice notice-error"><p>页面正文已保存，但展示配置未更新：请选择有效分类或已发布文章。原展示配置已保留。</p></div>';
});

// Default to the main-language list; explicit language selection (including all) is preserved.
add_action('load-edit.php',function () {
    if (($_GET['post_type'] ?? '')!=='page' || !function_exists('pll_default_language') || array_key_exists('lang',$_GET) || !empty($_POST)) return;
    if (!empty($_GET['action']) && $_GET['action']!=='-1') return;
    $lang=pll_default_language();
    if ($lang) { wp_safe_redirect(add_query_arg('lang',$lang)); exit; }
});
add_action('admin_notices',function () {
    $screen=get_current_screen();
    if (!$screen || $screen->id!=='edit-page' || !function_exists('pll_default_language')) return;
    $base=admin_url('edit.php?post_type=page');
    echo '<div class="notice notice-info"><p>多语言页面按语言筛选显示；同一内容的译文仍保留，点击国旗列的铅笔或加号维护。<a href="'.esc_url(add_query_arg('lang',pll_default_language(),$base)).'">主语言页面</a> · <a href="'.esc_url(add_query_arg('lang','all',$base)).'">全部语言</a>。其他语言可在顶部工具栏切换。</p></div>';
});

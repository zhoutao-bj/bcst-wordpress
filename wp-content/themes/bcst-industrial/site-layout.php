<?php
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',function () {
    wp_enqueue_style('bcst-layout',get_template_directory_uri() . '/site-layout.css',array('bcst-industrial','bcst-product-navigation'),'2.1.0');
    wp_enqueue_script('bcst-layout',get_template_directory_uri() . '/site-layout.js',array(),'2.0.0',true);
});
add_action('admin_init',function () {
    if (function_exists('pll_register_string')) foreach (array('About','News','Company Logo','Language','Open navigation') as $text) pll_register_string($text,$text,'BCST');
});
function bcst_layout_logo() {
    $logo_id=absint(get_option('bcst_company_logo',0));
    if ($logo_id && wp_attachment_is_image($logo_id)) {
        $image=wp_get_attachment_image($logo_id,'full',false,array('class'=>'custom-logo','alt'=>get_bloginfo('name'),'loading'=>'eager'));
        if ($image) {
            echo '<a class="custom-logo-link" href="' . esc_url(bcst_home()) . '" rel="home">' . $image . '</a>';
            return;
        }
    }
    if (has_custom_logo()) { the_custom_logo(); return; }
    echo '<a class="bcst-logo-placeholder" href="' . esc_url(bcst_home()) . '" aria-label="' . esc_attr(bcst_t('Home')) . '"><span aria-hidden="true">▧</span><span>' . esc_html(bcst_t('Company Logo')) . '</span></a>';
}
// Missing translations use the original published destination, never a fabricated URL.
function bcst_layout_tree() {
    static $nodes=null;
    if ($nodes!==null) return $nodes;
    $nodes=array(); $tree=array();
    $args=array('taxonomy'=>'bcst_category','hide_empty'=>false,'orderby'=>'name','order'=>'ASC');
    if (function_exists('pll_current_language')) { $lang=pll_current_language(); if ($lang) $args['lang']=$lang; }
    $terms=get_terms($args);
    if (!is_wp_error($terms)) foreach ($terms as $term) $tree[(int)$term->parent][]=$term;
    $build=function ($parent,$seen=array()) use (&$build,$tree) {
        if (isset($seen[$parent])) return array();
        $seen[$parent]=true; $result=array();
        foreach ($tree[$parent] ?? array() as $term) {
            $url=get_term_link($term); if (is_wp_error($url)) continue;
            $result[]=array('label'=>$term->name,'url'=>$url,'children'=>$build((int)$term->term_id,$seen));
        }
        return $result;
    };
    $nodes[]=array('label'=>bcst_t('Home'),'url'=>bcst_home(),'children'=>array());
    $nodes=array_merge($nodes,$build(0));
    $nodes=array_merge($nodes,bcst_nav_about_tree());
    return $nodes;
}
function bcst_layout_menu($nodes,$footer=false,$depth=0) {
    $class=$footer ? ($depth ? 'bcst-footer-children' : 'bcst-footer-map') : ($depth ? 'sub-menu bcst-category-menu' : 'menu bcst-main-menu');
    $id=wp_unique_id('bcst-nav-');
    echo '<ul class="' . esc_attr($class) . '" id="' . esc_attr($id) . '">';
    foreach ($nodes as $node) {
        echo '<li' . (!$footer && !$depth ? ' class="bcst-product-nav"' : '') . '>';
        if ($node['url']) echo '<a href="' . esc_url($node['url']) . '">' . esc_html($node['label']) . '</a>';
        else echo '<span>' . esc_html($node['label']) . '</span>';
        if ($node['children']) {
            if (!$footer) echo '<button type="button" class="bcst-category-toggle" aria-expanded="false" aria-label="' . esc_attr($node['label']) . '"><span aria-hidden="true">▾</span></button>';
            bcst_layout_menu($node['children'],$footer,$depth+1);
        }
        echo '</li>';
    }
    echo '</ul>';
}
function bcst_layout_languages($footer=false) {
    if (!function_exists('pll_the_languages')) return;
    $languages=pll_the_languages(array('raw'=>1,'hide_if_empty'=>1,'hide_if_no_translation'=>1));
    if (!$languages) return;
    if (!$footer) echo '<details class="bcst-header-language"><summary>' . esc_html(pll_current_language('name') ?: bcst_t('Language')) . '<span aria-hidden="true"> ▾</span></summary>';
    echo '<ul class="bcst-language-list">';
    foreach ($languages as $language) echo '<li><a href="' . esc_url($language['url']) . '"' . (!empty($language['current_lang']) ? ' aria-current="true"' : '') . '>' . esc_html($language['name']) . '</a></li>';
    echo '</ul>'; if (!$footer) echo '</details>';
}

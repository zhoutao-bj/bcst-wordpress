<?php
defined('ABSPATH') || exit;

// Fetch once per request. Empty categories are intentional navigation destinations.
function bcst_product_dropdown() {
    static $tree = null;
    if ($tree === null) {
        $tree = array();
        $terms = get_terms(array('taxonomy' => 'bcst_category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tree[(int) $term->parent][] = $term;
            }
        }
    }
    return bcst_product_branch($tree, 0, array());
}

function bcst_product_branch($tree, $parent, $ancestors) {
    if (empty($tree[$parent]) || isset($ancestors[$parent])) return '';
    $ancestors[$parent] = true;
    $items = '';
    foreach ($tree[$parent] as $term) {
        $url = get_term_link($term);
        if (is_wp_error($url)) continue;
        $items .= '<li><a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
        $items .= bcst_product_branch($tree, (int) $term->term_id, $ancestors) . '</li>';
    }
    if ($items === '') return '';
    $id = wp_unique_id('bcst-categories-');
    return '<button type="button" class="bcst-category-toggle" aria-expanded="false" aria-controls="' . esc_attr($id) . '" aria-label="' . esc_attr(bcst_t('Product categories')) . '"><span aria-hidden="true">▾</span></button><ul id="' . esc_attr($id) . '" class="sub-menu bcst-category-menu">' . $items . '</ul>';
}

// Also support a manually configured primary menu pointing at the product archive.
function bcst_is_product_menu_item($item) {
    $archive = get_post_type_archive_link('bcst_product');
    return ($item->type === 'post_type_archive' && $item->object === 'bcst_product')
        || ($archive && untrailingslashit($item->url) === untrailingslashit($archive));
}
add_filter('wp_nav_menu_objects', function ($items, $args) {
    if ($args->theme_location !== 'primary') return $items;
    $parents = array();
    foreach ($items as $item) {
        if (bcst_is_product_menu_item($item)) {
            $item->classes[] = 'bcst-product-nav';
            $parents[(int) $item->ID] = true;
        }
    }
    // Replace old manually maintained descendants, without changing saved menus.
    do {
        $changed = false;
        foreach ($items as $item) {
            if (isset($parents[(int) $item->menu_item_parent]) && !isset($parents[(int) $item->ID])) {
                $parents[(int) $item->ID] = true;
                $changed = true;
            }
        }
    } while ($changed);
    return array_filter($items, function ($item) use ($parents) {
        return !isset($parents[(int) $item->menu_item_parent]);
    });
}, 10, 2);
add_filter('walker_nav_menu_start_el', function ($html, $item, $depth, $args) {
    return $args->theme_location === 'primary' && bcst_is_product_menu_item($item)
        ? $html . bcst_product_dropdown() : $html;
}, 10, 4);
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('bcst-product-navigation', get_template_directory_uri() . '/product-navigation.css', array('bcst-industrial'), '1.0.1');
    wp_enqueue_script('bcst-product-navigation', get_template_directory_uri() . '/product-navigation.js', array(), '1.0.1', true);
});

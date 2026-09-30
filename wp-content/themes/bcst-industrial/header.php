<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?><a class="screen-reader-text" href="#main"><?php echo esc_html(bcst_t('Skip to content')); ?></a>
<header class="site-header bcst-fixed-header"><div class="wrap bcst-header-layout">
<div class="bcst-header-logo"><?php bcst_layout_logo(); ?></div>
<nav aria-label="<?php echo esc_attr(bcst_t('Main navigation')); ?>" class="bcst-header-nav"><?php bcst_layout_menu(bcst_layout_tree()); ?></nav>
<div class="bcst-header-tools">
<form class="bcst-header-search" role="search" method="get" action="<?php echo esc_url(bcst_home()); ?>">
<label class="screen-reader-text" for="bcst-header-query"><?php echo esc_html(bcst_t('Search')); ?></label>
<input id="bcst-header-query" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>" placeholder="<?php echo esc_attr(bcst_t('Search')); ?>" required>
<?php if(function_exists('pll_current_language') && pll_current_language()): ?><input type="hidden" name="lang" value="<?php echo esc_attr(pll_current_language()); ?>"><?php endif; ?>
<button type="submit" aria-label="<?php echo esc_attr(bcst_t('Search')); ?>"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="10" cy="10" r="6.5"/><path d="m15 15 6 6"/></svg></button>
</form>
<?php bcst_layout_languages(); ?>
</div></div></header>
<main id="main">

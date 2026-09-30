<?php get_header(); while(have_posts()): the_post(); ?>
<article class="wrap section content">
<div class="breadcrumb"><a href="<?php echo esc_url(bcst_home()); ?>"><?php echo esc_html(bcst_t('Home')); ?></a> / <?php the_title(); ?></div>
<h1><?php the_title(); ?></h1>
<?php if(has_post_thumbnail()) the_post_thumbnail('large'); the_content(); ?>
<?php bcst_page_category_list(get_the_ID()); ?>
</article>
<?php endwhile; get_footer(); ?>

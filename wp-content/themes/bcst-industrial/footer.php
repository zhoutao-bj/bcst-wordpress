</main>
<?php
$social_links=array();
foreach(array('facebook'=>'Facebook','tiktok'=>'TikTok') as $key=>$label) {
    $url=esc_url(trim(bcst_setting($key)),array('http','https'));
    if($url) $social_links[$key]=array('label'=>$label,'url'=>$url);
}
$wa=preg_replace('/\D/','',bcst_setting('whatsapp'));
if($wa) $social_links['whatsapp']=array('label'=>'WhatsApp','url'=>'https://wa.me/'.$wa);
$footer_nodes=bcst_layout_tree();
$footer_about=bcst_nav_about_tree();
// Home is first; About, when present, is last. The middle entries are product categories.
$footer_products=array_slice($footer_nodes,1,count($footer_nodes)-1-count($footer_about));
$footer_intro=bcst_setting('footer_intro',bcst_setting('intro'));
?>
<footer class="site-footer bcst-reference-footer"><div class="wrap bcst-footer-wrap">
<div class="bcst-footer-columns">
<section class="bcst-footer-brand" aria-label="<?php echo esc_attr(get_bloginfo('name')); ?>">
<div class="bcst-footer-logo"><?php bcst_layout_logo(); ?></div>
<?php if($footer_intro): ?><p class="bcst-footer-intro"><?php echo nl2br(esc_html(bcst_t($footer_intro))); ?></p><?php endif; ?>
<?php if($social_links): ?><div class="bcst-footer-follow"><h2><?php echo esc_html(bcst_t('Follow us')); ?></h2><div class="bcst-footer-socials">
<?php foreach($social_links as $key=>$link): ?><a href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($link['label']); ?>" title="<?php echo esc_attr($link['label']); ?>"><span aria-hidden="true"><?php echo esc_html(array('facebook'=>'f','tiktok'=>'Tk','whatsapp'=>'Wa')[$key]); ?></span></a><?php endforeach; ?>
</div></div><?php endif; ?>
</section>
<?php if($footer_products): ?><section class="bcst-footer-group"><h2><?php echo esc_html(bcst_t('Products')); ?></h2><nav aria-label="<?php echo esc_attr(bcst_t('Products')); ?>"><?php bcst_layout_menu($footer_products,true); ?></nav></section><?php endif; ?>
<div class="bcst-footer-information">
<?php if($footer_about): ?><section class="bcst-footer-group"><h2><a href="<?php echo esc_url($footer_about[0]['url']); ?>"><?php echo esc_html($footer_about[0]['label']); ?></a></h2>
<?php if($footer_about[0]['children']): ?><nav aria-label="<?php echo esc_attr($footer_about[0]['label']); ?>"><?php bcst_layout_menu($footer_about[0]['children'],true); ?></nav><?php endif; ?>
</section><?php endif; ?>
<?php if(bcst_setting('email') || bcst_setting('phone') || bcst_setting('address')): ?><section class="bcst-footer-group bcst-footer-contact"><h2><?php echo esc_html(bcst_t('Contact Us')); ?></h2>
<?php if(is_email(bcst_setting('email'))): ?><a href="<?php echo esc_url('mailto:'.bcst_setting('email')); ?>"><?php echo esc_html(bcst_setting('email')); ?></a><?php endif; ?>
<?php if(bcst_setting('phone')): ?><p><?php echo esc_html(bcst_setting('phone')); ?></p><?php endif; ?>
<?php if(bcst_setting('address')): ?><p><?php echo nl2br(esc_html(bcst_t(bcst_setting('address')))); ?></p><?php endif; ?>
</section><?php endif; ?>
</div></div>
<div class="copyright bcst-footer-bottom"><div><?php if(get_privacy_policy_url()): ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>"><?php echo esc_html(bcst_t('Privacy policy')); ?></a><?php endif; ?></div><p><?php echo esc_html(bcst_t('Copyright')); ?> &copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?> | <?php echo esc_html(bcst_t('All Rights Reserved.')); ?></p></div>
</div></footer>
<?php if($social_links): ?>
<nav class="bcst-social-float" aria-label="<?php echo esc_attr(bcst_t('Social media')); ?>">
<?php foreach($social_links as $key=>$link): ?>
<a class="bcst-social-button bcst-social-<?php echo esc_attr($key); ?>" href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['label']); ?> <span aria-hidden="true">↗</span></a>
<?php endforeach; ?>
</nav>
<?php endif; wp_footer(); ?></body></html>

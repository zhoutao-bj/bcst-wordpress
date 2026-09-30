</main><footer class="site-footer"><div class="wrap">
<div class="bcst-footer-logo"><?php bcst_layout_logo(); ?></div>
<nav class="bcst-footer-navigation" aria-label="Footer navigation"><?php bcst_layout_menu(bcst_layout_tree(),true); ?></nav>
<div class="bcst-footer-contact">
<?php foreach(array('address','phone') as $key): if(bcst_setting($key)): ?><p><?php echo esc_html(bcst_setting($key)); ?></p><?php endif; endforeach; ?>
<?php if(get_privacy_policy_url()): ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>"><?php echo esc_html(bcst_t('Privacy policy')); ?></a><?php endif; ?>
</div>
<div class="copyright">&copy; <?php echo esc_html(wp_date('Y')); ?> <?php bloginfo('name'); ?></div>
</div></footer>
<?php
$social_links=array();
foreach(array('facebook'=>'Facebook','tiktok'=>'TikTok') as $key=>$label) {
    $url=esc_url(trim(bcst_setting($key)),array('http','https'));
    if($url) $social_links[$key]=array('label'=>$label,'url'=>$url);
}
$wa=preg_replace('/\D/','',bcst_setting('whatsapp'));
if($wa) $social_links['whatsapp']=array('label'=>'WhatsApp','url'=>'https://wa.me/'.$wa);
if($social_links): ?>
<nav class="bcst-social-float" aria-label="Social media">
<?php foreach($social_links as $key=>$link): ?>
<a class="bcst-social-button bcst-social-<?php echo esc_attr($key); ?>" href="<?php echo esc_url($link['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['label']); ?> <span aria-hidden="true">↗</span></a>
<?php endforeach; ?>
</nav>
<?php endif; wp_footer(); ?></body></html>

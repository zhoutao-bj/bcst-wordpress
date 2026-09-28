<?php
/**
 * Plugin Name: BCST Industrial Core
 * Description: 工业品、三级分类、询盘及站点配置。
 * Version: 1.0.0
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

function bcst_register() {
    register_post_type('bcst_product', array('labels'=>array('name'=>'产品','singular_name'=>'产品','add_new_item'=>'添加产品'), 'public'=>true,'has_archive'=>'products','rewrite'=>array('slug'=>'product'),'show_in_rest'=>true,'menu_icon'=>'dashicons-products','supports'=>array('title','editor','excerpt','thumbnail','revisions','page-attributes')));
    register_taxonomy('bcst_category','bcst_product',array('label'=>'产品分类（最多三级）','public'=>true,'hierarchical'=>true,'show_in_rest'=>true,'rewrite'=>array('slug'=>'product-category')));
    register_post_type('bcst_inquiry',array('labels'=>array('name'=>'询盘','singular_name'=>'询盘'),'public'=>false,'show_ui'=>true,'show_in_rest'=>false,'menu_icon'=>'dashicons-email','supports'=>array('title'),'capability_type'=>'post','capabilities'=>array('edit_posts'=>'manage_options','edit_others_posts'=>'manage_options','publish_posts'=>'manage_options','read_private_posts'=>'manage_options','delete_posts'=>'manage_options','create_posts'=>'do_not_allow'),'map_meta_cap'=>true));
}
add_action('init','bcst_register');
register_activation_hook(__FILE__,function(){bcst_register();flush_rewrite_rules();});
register_deactivation_hook(__FILE__,'flush_rewrite_rules');

// Validate both the new parent depth and the existing subtree when moving a term.
function bcst_depth_check($term,$taxonomy,$args=array()) {
    if ($taxonomy !== 'bcst_category') return $term;
    $args=wp_parse_args($args);
    $parent=isset($args['parent']) ? absint($args['parent']) : 0;
    if ($parent && count(get_ancestors($parent,$taxonomy))+2 > 3) return new WP_Error('bcst_depth','产品分类最多三级。');
    return $term;
}
add_filter('pre_insert_term','bcst_depth_check',10,3);
add_filter('wp_update_term_parent',function($parent,$id,$taxonomy){
    if ($taxonomy !== 'bcst_category') return $parent;
    $old=get_term($id,$taxonomy);
    $depth=$parent ? count(get_ancestors($parent,$taxonomy))+2 : 1;
    $children=get_term_children($id,$taxonomy); $height=0;
    if (!is_wp_error($children)) foreach($children as $child) {
        $anc=get_ancestors($child,$taxonomy); $position=array_search((int)$id,array_map('intval',$anc),true);
        if ($position !== false) $height=max($height,$position+1);
    }
    return ($parent==$id || in_array((int)$parent,array_map('intval',is_array($children)?$children:array()),true) || $depth+$height>3) ? (int)$old->parent : $parent;
},10,3);

function bcst_fields(){return array('model'=>'型号','specs'=>'参数（每行 参数名 | 参数值）','gallery'=>'相册图片 ID（逗号分隔，从媒体库附件链接获取）','download'=>'资料 PDF 地址（媒体库上传后复制）','video'=>'视频地址（YouTube 等支持嵌入的地址）','faq'=>'常见问题（每行 问题 | 回答）','related'=>'相关产品 ID（逗号分隔）');}
add_action('add_meta_boxes',function(){
    add_meta_box('bcst_product_data','产品参数与资料',function($post){
        wp_nonce_field('bcst_product_save','bcst_product_nonce');
        foreach(bcst_fields() as $key=>$label) echo '<p><label>'.esc_html($label).'<textarea rows="3" style="width:100%" name="bcst_'.esc_attr($key).'">'.esc_textarea(get_post_meta($post->ID,'_bcst_'.$key,true)).'</textarea></label></p>';
    },'bcst_product');
    add_meta_box('bcst_lead_data','询盘详情 / 跟进',function($post){
        echo '<pre style="white-space:pre-wrap">'.esc_html($post->post_content).'</pre>';
        wp_nonce_field('bcst_lead_save','bcst_lead_nonce');
        echo '<p>邮件通知：'.esc_html(get_post_meta($post->ID,'_bcst_mail',true)).'</p><label>跟进状态 <select name="bcst_status">';
        foreach(array('new'=>'待处理','contacted'=>'已联系','quoted'=>'已报价','closed'=>'已关闭') as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected(get_post_meta($post->ID,'_bcst_status',true),$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label><p>跟进备注<textarea name="bcst_notes" rows="5" style="width:100%">'.esc_textarea(get_post_meta($post->ID,'_bcst_notes',true)).'</textarea></p>';
    },'bcst_inquiry');
});
add_action('save_post',function($id){
    if (wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post',$id)) return;
    if (isset($_POST['bcst_product_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bcst_product_nonce'])),'bcst_product_save')) {
        foreach(bcst_fields() as $key=>$label) {
            $raw=isset($_POST['bcst_'.$key]) && is_string($_POST['bcst_'.$key]) ? wp_unslash($_POST['bcst_'.$key]) : '';
            $value=in_array($key,array('video','download'),true)?esc_url_raw($raw):sanitize_textarea_field($raw);
            update_post_meta($id,'_bcst_'.$key,$value);
        }
    }
    if (current_user_can('manage_options') && isset($_POST['bcst_lead_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bcst_lead_nonce'])),'bcst_lead_save')) {
        $state=isset($_POST['bcst_status'])?sanitize_key($_POST['bcst_status']):'new';
        update_post_meta($id,'_bcst_status',in_array($state,array('new','contacted','quoted','closed'),true)?$state:'new');
        update_post_meta($id,'_bcst_notes',sanitize_textarea_field(wp_unslash($_POST['bcst_notes'] ?? '')));
    }
});

add_action('admin_menu',function(){add_options_page('工业站设置','工业站设置','manage_options','bcst-settings','bcst_settings_page');});
add_action('admin_init',function(){register_setting('bcst_settings','bcst_settings',array('sanitize_callback'=>function($value){
    $out=array();foreach(array('email','phone','whatsapp','address','headline','intro','facebook','tiktok') as $key){$v=isset($value[$key])&&is_string($value[$key])?$value[$key]:'';$out[$key]=$key==='email'?sanitize_email($v):(in_array($key,array('facebook','tiktok'),true)?esc_url_raw($v):sanitize_textarea_field($v));}return $out;
}));});
function bcst_settings_page(){
    if(!current_user_can('manage_options'))return;
    echo '<div class="wrap"><h1>工业站设置</h1><p>询盘同时保存在后台。邮件投递需配置 SMTP 并实际测试。WhatsApp 填国家码加号码，仅数字。</p><form method="post" action="options.php">';settings_fields('bcst_settings');$values=get_option('bcst_settings',array());
    foreach(array('email'=>'销售收件邮箱','phone'=>'联系电话','whatsapp'=>'WhatsApp','address'=>'公司地址','headline'=>'首页标题','intro'=>'首页介绍','facebook'=>'Facebook 链接','tiktok'=>'TikTok 链接') as $key=>$label)echo '<p><label>'.esc_html($label).'<br><textarea class="large-text" name="bcst_settings['.esc_attr($key).']">'.esc_textarea($values[$key]??'').'</textarea></label></p>';
    submit_button();echo '</form><hr><h2>初始化页面</h2><p>补齐基础页面与示例产品草稿，不覆盖现有内容。示例分类仅用于演示，可按客户目录调整；产品必须补齐真实参数后发布。首页设置只在首次执行时更改。</p><form action="'.esc_url(admin_url('admin-post.php')).'" method="post"><input type="hidden" name="action" value="bcst_setup">';wp_nonce_field('bcst_setup');submit_button('创建基础页面');echo '</form><p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bcst_export'),'bcst_export')).'">导出最近 1000 条询盘 CSV</a></p></div>';
}
add_action('admin_post_bcst_setup',function(){
    if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('bcst_setup');
    $pages=array('home'=>'Home','about'=>'About Us','industries'=>'Industries','resources'=>'Resources','contact'=>'Contact Us','insights'=>'Insights');$ids=array();
    $bodies=array('home'=>'','about'=>'<h2>Company profile</h2><p>[Replace with your verified company introduction before launch.]</p><h2>Manufacturing & quality</h2><p>[Add genuine factory photos, quality procedures and certificates.]</p>','industries'=>'<h2>Solutions for your application</h2><p>[Describe the industries your company actually serves. Include application conditions and links to suitable products.]</p><h2>Discuss your requirements</h2>[bcst_inquiry]','resources'=>'<h2>Technical resources</h2><p>[Upload approved product catalogues and manuals to the media library, then add download links here.]</p>','contact'=>'[bcst_inquiry]','insights'=>'');
    foreach($pages as $slug=>$title){$p=get_page_by_path($slug);$ids[$slug]=$p?$p->ID:wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug,'post_content'=>$bodies[$slug]));}
    if(!get_option('bcst_initialized')){update_option('show_on_front','page');update_option('page_on_front',$ids['home']);update_option('page_for_posts',$ids['insights']);update_option('bcst_initialized',1);}
    foreach(array('Technical Training','Case Studies','Company News') as $name)if(!term_exists($name,'category'))wp_insert_term($name,'category');
    if(!get_option('bcst_sample_created')){
        $parent=0;foreach(array('Automatic Valves','Control Valves','Pneumatic Control Valves') as $name){$term=term_exists($name,'bcst_category',$parent);if(!$term)$term=wp_insert_term($name,'bcst_category',array('parent'=>$parent));if(is_wp_error($term))break;$parent=(int)$term['term_id'];}
        $sample=wp_insert_post(array('post_type'=>'bcst_product','post_status'=>'draft','post_title'=>'Demo — Pneumatic Control Valve','post_content'=>'<p>演示草稿：请替换为客户实际产品介绍、应用场景、材质和规格，并上传真实产品图。请勿直接发布示例参数。</p>','post_excerpt'=>'Replace with a verified product summary.'));
        if($sample){wp_set_object_terms($sample,array($parent),'bcst_category');update_post_meta($sample,'_bcst_specs',"Material | To be confirmed\nPressure rating | To be confirmed");update_option('bcst_sample_created',1);}
    }
    flush_rewrite_rules();wp_safe_redirect(admin_url('options-general.php?page=bcst-settings'));exit;
});

function bcst_text($text){return function_exists('pll__')?pll__($text):$text;}
function bcst_input($key){return isset($_POST[$key])&&is_string($_POST[$key])?trim(wp_unslash($_POST[$key])):'';}
add_shortcode('bcst_inquiry',function(){
    $product=is_singular('bcst_product')?get_the_ID():0;
    ob_start(); ?>
    <form class="inquiry" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
    <h2><?php echo esc_html(bcst_text('Request a quotation')); ?></h2>
    <?php wp_nonce_field('bcst_inquiry','bcst_nonce'); ?>
    <input type="hidden" name="action" value="bcst_inquiry"><input type="hidden" name="product" value="<?php echo esc_attr($product); ?>">
    <input type="hidden" name="source" value="<?php echo esc_url(get_permalink()); ?>">
    <input type="hidden" name="language" value="<?php echo esc_attr(function_exists('pll_current_language')?pll_current_language():get_locale()); ?>">
    <input type="hidden" name="campaign" value="" class="bcst-campaign">
    <div class="bcst-trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <?php foreach(array('name'=>'Name','email'=>'Email','company'=>'Company','country'=>'Country','phone'=>'Phone / WhatsApp') as $key=>$label): ?>
    <label><?php echo esc_html(bcst_text($label)); ?><input name="<?php echo esc_attr($key); ?>" type="<?php echo $key==='email'?'email':'text'; ?>" maxlength="200" <?php echo in_array($key,array('name','email','country'),true)?'required':''; ?>></label>
    <?php endforeach; ?>
    <label><?php echo esc_html(bcst_text('Requirements')); ?><textarea name="message" rows="5" maxlength="5000" required></textarea></label>
    <label><input type="checkbox" name="consent" value="1" required> <?php echo esc_html(bcst_text('I agree to be contacted about this inquiry.')); ?> <?php if(get_privacy_policy_url()): ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>"><?php echo esc_html(bcst_text('Privacy policy')); ?></a><?php endif; ?></label>
    <button type="submit"><?php echo esc_html(bcst_text('Send inquiry')); ?></button></form>
    <?php return ob_get_clean();
});
function bcst_submit(){
    if(!wp_verify_nonce(bcst_input('bcst_nonce'),'bcst_inquiry'))wp_die('Form expired. Please refresh the page and try again.',400);
    if(bcst_input('website')!=='')wp_die('Unable to submit.',400);
    $email=sanitize_email(bcst_input('email'));$name=sanitize_text_field(bcst_input('name'));$message=sanitize_textarea_field(bcst_input('message'));$country=sanitize_text_field(bcst_input('country'));
    if(!$name || !is_email($email) || !$message || !$country || bcst_input('consent')!=='1' || strlen($message)>20000 || strlen($name)>600 || strlen($country)>600)wp_die('Please complete the required fields.',400);
    $key='bcst_rate_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'',wp_salt());$count=(int)get_transient($key);
    if($count>=5)wp_die('Too many submissions. Please try again in ten minutes.',429);
    set_transient($key,$count+1,10*MINUTE_IN_SECONDS);
    $product=absint(bcst_input('product'));if(get_post_type($product)!=='bcst_product'||get_post_status($product)!=='publish')$product=0;
    $data=array('Name'=>$name,'Email'=>$email,'Company'=>sanitize_text_field(substr(bcst_input('company'),0,600)),'Country'=>$country,'Phone'=>sanitize_text_field(substr(bcst_input('phone'),0,200)),'Product'=>$product?get_the_title($product):'General inquiry','Source'=>esc_url_raw(substr(bcst_input('source'),0,2000)),'Language'=>sanitize_text_field(substr(bcst_input('language'),0,30)),'Campaign'=>sanitize_text_field(substr(bcst_input('campaign'),0,2000)),'Consent'=>'Yes','Requirements'=>$message);
    $body='';foreach($data as $label=>$value)$body.=$label.': '.$value."\n\n";
    $id=wp_insert_post(array('post_type'=>'bcst_inquiry','post_status'=>'private','post_title'=>wp_slash($name.' — '.current_time('mysql')),'post_content'=>wp_slash($body)),true);
    if(is_wp_error($id))wp_die('Unable to save. Please try again later.',500);
    update_post_meta($id,'_bcst_status','new');$settings=get_option('bcst_settings',array());$to=$settings['email']??get_option('admin_email');
    $sent=wp_mail($to,'New website inquiry #'.$id,$body,array('Reply-To: '.$email));update_post_meta($id,'_bcst_mail',$sent?'Mail accepted by transport (delivery unconfirmed)':'Failed — check SMTP');
    nocache_headers();wp_die(esc_html(bcst_text('Thank you. Your inquiry has been saved.')).'<p><a href="'.esc_url(home_url('/')).'">'.esc_html(bcst_text('Home')).'</a></p>',esc_html(bcst_text('Inquiry received')),array('response'=>200));
}
add_action('admin_post_bcst_inquiry','bcst_submit');add_action('admin_post_nopriv_bcst_inquiry','bcst_submit');

add_action('admin_post_bcst_export',function(){
    if(!current_user_can('manage_options'))wp_die('Forbidden',403);check_admin_referer('bcst_export');
    nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="inquiries.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array('ID','日期','客户','状态','详情','备注','邮件'));
    $posts=get_posts(array('post_type'=>'bcst_inquiry','post_status'=>'private','posts_per_page'=>1000,'suppress_filters'=>true));
    foreach($posts as $p){$row=array($p->ID,$p->post_date,$p->post_title,get_post_meta($p->ID,'_bcst_status',true),$p->post_content,get_post_meta($p->ID,'_bcst_notes',true),get_post_meta($p->ID,'_bcst_mail',true));foreach($row as &$cell){$cell=(string)$cell;if(preg_match('/^[\s]*[=+@-]/u',$cell))$cell="'".$cell;}unset($cell);fputcsv($out,$row);}fclose($out);exit;
});
add_filter('manage_bcst_inquiry_posts_columns',function($columns){$columns['bcst_status']='跟进状态';$columns['bcst_mail']='邮件通知';return $columns;});
add_action('manage_bcst_inquiry_posts_custom_column',function($column,$id){if($column==='bcst_status')echo esc_html(get_post_meta($id,'_bcst_status',true));if($column==='bcst_mail')echo esc_html(get_post_meta($id,'_bcst_mail',true));},10,2);

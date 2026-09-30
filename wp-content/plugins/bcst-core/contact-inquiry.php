<?php
defined('ABSPATH') || exit;

function bcst_contact_clean($value) {
    $out=array('page'=>0,'people'=>array());
    if(!is_array($value))return $out;
    $page=isset($value['page'])&&is_scalar($value['page'])?absint($value['page']):0;
    if($page && get_post_type($page)==='page')$out['page']=$page;
    foreach(array_slice(isset($value['people'])&&is_array($value['people'])?$value['people']:array(),0,50) as $row){
        if(!is_array($row))continue;
        $person=array();
        foreach(array('name','role','email','phone','whatsapp','photo') as $key){
            $v=isset($row[$key])&&is_scalar($row[$key])?(string)$row[$key]:'';
            $person[$key]=$key==='email'?sanitize_email($v):sanitize_text_field($v);
        }
        $person['photo']=absint($person['photo']);
        if(!wp_attachment_is_image($person['photo']))$person['photo']=0;
        $person['whatsapp']=preg_replace('/\D/','',$person['whatsapp']);
        if($person['name']!=='')$out['people'][]=$person;
    }
    return $out;
}
add_action('admin_init',function(){
    register_setting('bcst_settings','bcst_contact',array('sanitize_callback'=>'bcst_contact_clean'));
    if(function_exists('pll_register_string'))foreach(array('Contact our sales team','Company Email','Company Telephone','Factory Address','Request a quotation','Close','Sending…','Unable to submit. Please try again.','Form expired. Please refresh the page and try again.','Unable to submit.','Please complete the required fields.','Too many submissions. Please try again in ten minutes.','Unable to save. Please try again later.') as $text)pll_register_string($text,$text,'BCST');
});
add_action('admin_enqueue_scripts',function($hook){
    if($hook!=='settings_page_bcst-settings')return;
    wp_enqueue_media();
    wp_enqueue_script('bcst-contact-admin',plugins_url('contact-admin.js',__FILE__),array('jquery','media-views'),'1.0.0',true);
});
function bcst_contact_row($index,$person=array()){
    echo '<fieldset class="bcst-person" style="padding:16px;border:1px solid #ccd0d4;margin:12px 0"><legend>销售人员</legend>';
    foreach(array('name'=>'姓名（留空则不展示）','role'=>'职位','email'=>'邮箱','phone'=>'电话','whatsapp'=>'WhatsApp（国家码加号码）') as $key=>$label){
        echo '<p><label>'.esc_html($label).' <input type="'.($key==='email'?'email':'text').'" class="regular-text" name="bcst_contact[people]['.esc_attr($index).']['.esc_attr($key).']" value="'.esc_attr($person[$key]??'').'"></label></p>';
    }
    $photo=absint($person['photo']??0);
    echo '<input class="bcst-person-photo" type="hidden" name="bcst_contact[people]['.esc_attr($index).'][photo]" value="'.esc_attr($photo).'">';
    echo '<div class="bcst-person-preview">'.($photo?wp_get_attachment_image($photo,'thumbnail'):'').'</div><p><button type="button" class="button bcst-person-upload">上传 / 选择头像</button> <button type="button" class="button bcst-person-clear">移除头像</button> <button type="button" class="button bcst-person-remove">删除此人员</button></p></fieldset>';
}
function bcst_contact_control(){
    $settings=get_option('bcst_contact',array());
    echo '<h2>Contact Us / 销售团队</h2><p>人员资料各语言共用；未填写的联系方式不显示。删除人员后需保存更改，不会删除媒体文件。</p><label>联系方式页面 ';
    wp_dropdown_pages(array('name'=>'bcst_contact[page]','selected'=>$settings['page']??0,'show_option_none'=>'自动识别原 Contact Us 页面（contact）','option_none_value'=>0));
    echo '</label><p>选定页面自动展示销售团队与下方公司联系方式。原 [bcst_inquiry] 在该页面兼容为联系方式，不再显示大表单。其他位置的该短代码显示询盘弹窗按钮。</p><div id="bcst-people">';
    foreach(($settings['people']??array()) as $index=>$person)bcst_contact_row($index,$person);
    echo '</div><button type="button" class="button" id="bcst-person-add">新增销售人员</button><template id="bcst-person-template">';
    bcst_contact_row('__INDEX__');echo '</template><hr>';
}
function bcst_is_contact_page(){
    if(!is_page())return false;
    $settings=get_option('bcst_contact',array());$id=absint($settings['page']??0);
    if(!$id){
        $pages=get_posts(array('post_type'=>'page','name'=>'contact','post_status'=>'publish','posts_per_page'=>1,'lang'=>'','suppress_filters'=>true));
        $id=$pages?(int)$pages[0]->ID:0;
    }
    if(!$id)return false;
    $current=get_queried_object_id();
    if($current===$id)return true;
    if(function_exists('pll_get_post_translations'))return in_array($current,array_map('intval',pll_get_post_translations($id)),true);
    return false;
}
function bcst_contact_links($person){
    $html='';
    if(!empty($person['email']))$html.='<p><a href="'.esc_url('mailto:'.$person['email']).'">'.esc_html($person['email']).'</a></p>';
    if(!empty($person['phone']))$html.='<p><a href="'.esc_url('tel:'.preg_replace('/[^+0-9]/','',$person['phone'])).'">'.esc_html($person['phone']).'</a></p>';
    $number=preg_replace('/\D/','',$person['whatsapp']??'');
    if($number)$html.='<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url('https://wa.me/'.$number).'">WhatsApp +'.esc_html($number).'</a></p>';
    return $html;
}
function bcst_contact_team(){
    $settings=get_option('bcst_contact',array());$company=get_option('bcst_settings',array());
    $html='<section class="bcst-contact-team"><h2>'.esc_html(bcst_text('Contact our sales team')).'</h2><div class="bcst-contact-grid">';
    foreach(($settings['people']??array()) as $person){
        if(empty($person['name']))continue;
        $html.='<article class="bcst-contact-card">';
        if(!empty($person['photo']))$html.=wp_get_attachment_image($person['photo'],'thumbnail',false,array('class'=>'bcst-person-avatar','alt'=>bcst_text($person['name'])));
        $html.='<h3>'.esc_html(bcst_text($person['name'])).'</h3>';
        if(!empty($person['role']))$html.='<p>'.esc_html(bcst_text($person['role'])).'</p>';
        $html.=bcst_contact_links($person).'</article>';
    }
    foreach(array('email'=>'Company Email','phone'=>'Company Telephone','whatsapp'=>'WhatsApp','address'=>'Factory Address') as $key=>$label){
        if(empty($company[$key]))continue;
        $html.='<article class="bcst-contact-card"><h3>'.esc_html(bcst_text($label)).'</h3>'.($key==='address'?'<p>'.nl2br(esc_html(bcst_text($company[$key]))).'</p>':bcst_contact_links(array($key=>$company[$key]))).'</article>';
    }
    return $html.'</div><p>'.bcst_inquiry_button().'</p></section>';
}
function bcst_inquiry_button(){
    return '<button type="button" class="button bcst-inquiry-open">'.esc_html(bcst_text('Request a quotation')).'</button>';
}
add_action('init',function(){
    add_shortcode('bcst_inquiry',function(){return bcst_is_contact_page()?bcst_contact_team():bcst_inquiry_button();});
    add_shortcode('bcst_contact_team','bcst_contact_team');
},20);
add_filter('the_content',function($content){
    if(!is_admin()&&is_main_query()&&in_the_loop()&&bcst_is_contact_page()){
        $raw=get_post_field('post_content',get_queried_object_id());
        if(!has_shortcode($raw,'bcst_inquiry')&&!has_shortcode($raw,'bcst_contact_team'))$content.=bcst_contact_team();
    }
    return $content;
},20);
function bcst_inquiry_error($message,$status){
    if(wp_doing_ajax())wp_send_json_error(array('message'=>bcst_inquiry_text($message)),$status);
    wp_die(esc_html(bcst_inquiry_text($message)),'',array('response'=>$status));
}
add_action('wp_ajax_bcst_inquiry','bcst_submit');
add_action('wp_ajax_nopriv_bcst_inquiry','bcst_submit');
// Fetch a fresh nonce when opening: cached public pages must not retain expired form tokens.
add_action('wp_ajax_bcst_inquiry_token','bcst_inquiry_token');
add_action('wp_ajax_nopriv_bcst_inquiry_token','bcst_inquiry_token');
function bcst_inquiry_token(){nocache_headers();wp_send_json_success(array('nonce'=>wp_create_nonce('bcst_inquiry')));}
add_action('wp_enqueue_scripts',function(){
    wp_enqueue_style('bcst-contact',plugins_url('contact-inquiry.css',__FILE__),array(),'1.0.0');
    wp_enqueue_script('bcst-contact',plugins_url('contact-inquiry.js',__FILE__),array(),'1.1.0',true);
    wp_localize_script('bcst-contact','bcstInquiry',array('url'=>admin_url('admin-ajax.php'),'sending'=>bcst_text('Sending…'),'error'=>bcst_text('Unable to submit. Please try again.'),'required'=>bcst_text('Please complete the required fields.'),'email'=>bcst_text('Please enter a valid email address.'),'consent'=>bcst_text('Please agree to be contacted about this inquiry.')));
});
add_action('wp_footer',function(){ ?>
<dialog id="bcst-inquiry-dialog" aria-labelledby="bcst-inquiry-title">
<button type="button" class="bcst-inquiry-close" aria-label="<?php echo esc_attr(bcst_text('Close')); ?>">×</button>
<form novalidate id="bcst-modal-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
<h2 id="bcst-inquiry-title"><?php echo esc_html(bcst_text('Request a quotation')); ?></h2>
<input type="hidden" name="action" value="bcst_inquiry"><input type="hidden" name="bcst_nonce" value="">
<input type="hidden" name="product" value="<?php echo is_singular('bcst_product')?absint(get_queried_object_id()):0; ?>">
<input type="hidden" name="source" value=""><input type="hidden" name="campaign" value="">
<input type="hidden" name="language" value="<?php echo esc_attr(function_exists('pll_current_language')?pll_current_language():get_locale()); ?>">
<div class="bcst-modal-trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<label><?php echo esc_html(bcst_text('Name')); ?><input name="name" maxlength="200" autocomplete="name" required></label>
<label><?php echo esc_html(bcst_text('Email')); ?><input name="email" type="email" maxlength="200" autocomplete="email" required></label>
<label><?php echo esc_html(bcst_text('Requirements')); ?><textarea name="message" rows="5" maxlength="5000" required></textarea></label>
<label class="bcst-modal-consent"><input name="consent" type="checkbox" value="1" required> <span><?php echo esc_html(bcst_text('I agree to be contacted about this inquiry.')); ?> <?php if(get_privacy_policy_url()): ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(bcst_text('Privacy policy')); ?></a><?php endif; ?></span></label>
<p class="bcst-inquiry-status" role="status" aria-live="polite"></p><button type="submit"><?php echo esc_html(bcst_text('Send inquiry')); ?></button>
</form></dialog>
<?php });

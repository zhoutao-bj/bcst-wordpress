<?php
defined('ABSPATH') || exit;
add_action('admin_enqueue_scripts',function(){
    if (!current_user_can('manage_options')) return;
    $screen=get_current_screen();if (!$screen) return;
    $context='';$taxonomy='';
    if ($screen->base==='edit' && in_array($screen->post_type,bcst_tx_types(),true)) $context='content';
    if ($screen->base==='upload') $context='content';
    if ($screen->base==='edit-tags' && in_array($screen->taxonomy,bcst_tx_taxonomies(),true)) { $context='taxonomy';$taxonomy=$screen->taxonomy; }
    if (isset($_GET['page']) && $_GET['page']==='mlang_strings') $context='string';
    if (isset($_GET['page']) && $_GET['page']==='bcst-settings') $context='settings';
    if (!$context) return;
    wp_enqueue_script('bcst-translation-actions',plugins_url('translation-actions.js',__FILE__),array(),'2.0.0',true);
    wp_localize_script('bcst-translation-actions','bcstTranslationActions',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bcst_tx_select'),'kind'=>$context,'taxonomy'=>$taxonomy,'media'=>$screen->base==='upload','mediaList'=>admin_url('upload.php?mode=list')));
});
function bcst_tx_setting_strings() {
    $values=get_option('bcst_settings',array());$out=array();
    foreach (array('headline','intro','footer_intro','address') as $key) if (!empty($values[$key])) $out['setting_'.$key]=$values[$key];
    $contact=get_option('bcst_contact',array());
    foreach (($contact['people']??array()) as $i=>$person) foreach (array('name','role') as $key) if (!empty($person[$key])) $out['person_'.$i.'_'.$key]=$person[$key];
    return $out;
}
add_action('admin_init',function(){
    if (!function_exists('pll_register_string')) return;
    foreach (array('Copyright','Social media','Pagination') as $text) pll_register_string($text,$text,'BCST');
    foreach (bcst_tx_setting_strings() as $key=>$value) pll_register_string('bcst_'.$key,$value,'BCST Industrial Settings',true);
},30);
add_action('wp_ajax_bcst_tx_select',function(){
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_tx_select','nonce');
    try {
        bcst_bt_preflight();
        $kind=bcst_bailian_input('kind');$all=bcst_bailian_input('all')==='1';$taxonomy=bcst_bailian_input('taxonomy');
        $ids=isset($_POST['ids'])&&is_array($_POST['ids'])?array_values(array_filter(wp_unslash($_POST['ids']),'is_scalar')):array();
        $ids=array_values(array_unique(array_map('strval',$ids)));
        if (!in_array($kind,array('content','taxonomy','string','settings'),true)) throw new Exception('无效翻译入口。');
        if ($kind==='taxonomy') {
            if (!in_array($taxonomy,bcst_tx_taxonomies(),true)) throw new Exception('无效分类类型。');
            if ($all) {
                $ids=get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false,'lang'=>pll_default_language(),'fields'=>'ids'));
                if (is_wp_error($ids)) throw new Exception($ids->get_error_message());
            }
            foreach ($ids as $id) { $t=get_term((int)$id,$taxonomy);if (!$t || is_wp_error($t) || !current_user_can('edit_term',$id)) throw new Exception('分类不可编辑。'); }
        } elseif ($kind==='content') {
            foreach ($ids as $id) { $p=get_post((int)$id);if (!$p || !in_array($p->post_type,bcst_tx_types(),true) || !current_user_can('edit_post',$id)) throw new Exception('所选内容不可编辑。'); }
        } else {
            $strings=bcst_tx_strings();
            if ($kind==='settings') {
                $values=array_values(bcst_tx_setting_strings());$ids=array();
                foreach ($strings as $key=>$row) if (in_array($row['string'],$values,true)) $ids[]=(string)$key;
            } elseif ($all) $ids=array_map('strval',array_keys($strings));
            foreach ($ids as $id) if (!isset($strings[$id])) throw new Exception('公共文字列表已变化，请刷新后重新选择。');
        }
        if (!$ids) throw new Exception('请先勾选需要翻译的内容；如果没有数据，请先维护源文案。');
        if (count($ids)>100 && !$all && $kind!=='settings') throw new Exception('每次最多勾选 100 条，请分批处理。');
        if (count($ids)>3000) throw new Exception('内容超过 3000 条，请分批勾选。');
        $selection=array('kind'=>$kind==='settings'?'string':$kind,'ids'=>$ids,'taxonomy'=>$taxonomy);
        if ($selection['kind']==='string') foreach ($ids as $id) $selection['originals'][$id]=$strings[$id]['string'];
        set_transient('bcst_tx_selection_'.get_current_user_id(),$selection,3600);
        wp_send_json_success(array('url'=>bcst_bt_url()));
    } catch (Throwable $e) { wp_send_json_error(array('message'=>$e->getMessage()),400); }
});
function bcst_tx_plan($selection,$targets,$overwrite,$source_lang) {
    $tasks=array();
    if (!$selection || empty($selection['ids'])) throw new Exception('选择记录已过期，请返回列表重新勾选。');
    foreach ($selection['ids'] as $id) foreach ($targets as $lang) {
        $extra=array();
        if ($selection['kind']==='taxonomy') $extra['taxonomy']=$selection['taxonomy'];
        if ($selection['kind']==='string') {
            if (!isset(bcst_bailian_languages()[$source_lang])) throw new Exception('请选择公共文字实际使用的源语言。');
            $extra=array('source_lang'=>$source_lang,'original'=>$selection['originals'][$id]);
        }
        bcst_tx_add($tasks,$selection['kind'],$selection['kind']==='string'?$id:(int)$id,$lang,$overwrite,$extra);
    }
    return array_values($tasks);
}
function bcst_tx_form() {
    $selection=get_transient('bcst_tx_selection_'.get_current_user_id());
    if (!$selection) { echo '<p>尚未选择内容，或选择已过期。请返回对应列表，使用 Filter 旁的“翻译所选内容”按钮。下方已有任务仍可继续执行。</p>';return; }
    $labels=array('content'=>'产品 / 文章 / 页面 / 媒体文字','taxonomy'=>'分类 / 标签','string'=>'公共文字 / 工业站文案');
    echo '<h2>确认所选内容</h2><p>范围：'.esc_html($labels[$selection['kind']]).'；已选择 '.count($selection['ids']).' 条。源语言按内容自身识别，顶部语言筛选不决定目标语言。</p>';
    echo '<p>默认补齐缺失译文，已有译文（含草稿）跳过。新产品、文章、页面保存为草稿；分类、公共文字、媒体文字和对已发布译文的更新立即生效。关联分类、标签、父页面及页面绑定目标会自动补齐（已有依赖不覆盖）。请先备份数据库，并审核结果。</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bcst_bt_create"><input type="hidden" name="selection_hash" value="'.esc_attr(hash('sha256',wp_json_encode($selection))).'">';wp_nonce_field('bcst_bt_create');
    if ($selection['kind']==='string') {
        echo '<p><label>公共原文实际语言 <select name="source_lang">';
        foreach (bcst_bailian_languages() as $code=>$language) echo '<option value="'.esc_attr($code).'" '.selected($code,pll_default_language(),false).'>'.esc_html($language['name']).'</option>';
        echo '</select></label> 公共文字没有独立语言属性，请按原文选择。不同语言混合的文字应分批处理。</p>';
    }
    echo '<fieldset><legend>目标语言（可多选）</legend>';
    foreach (bcst_bailian_languages() as $code=>$language) echo '<label style="display:inline-block;margin:8px 20px 8px 0"><input type="checkbox" name="targets[]" value="'.esc_attr($code).'">'.esc_html($language['name'].' ('.$code.')').'</label>';
    echo '</fieldset><p><label><input type="checkbox" name="overwrite" value="1">更新所选内容的已有译文（覆盖人工修改，已发布内容立即改变；依赖内容仍只补缺失）</label></p><p><label><input type="checkbox" required name="consent" value="1">同意将文字发送到当前配置的翻译服务并承担接口费用；创建任务替换本人旧任务进度，已生成内容保留。</label></p>';
    submit_button('创建翻译任务（不立即调用接口）');echo '</form><hr>';
}
// Translate attachment text at render time without modifying or duplicating the binary file.
add_filter('wp_get_attachment_image_attributes',function($attr,$attachment){
    if (!is_admin() && function_exists('pll_current_language') && function_exists('pll_get_post')) {
        $id=pll_get_post($attachment->ID,pll_current_language());
        if ($id) $attr['alt']=get_post_meta($id,'_wp_attachment_image_alt',true);
    }
    return $attr;
},20,2);
add_filter('wp_get_attachment_caption',function($caption,$id){
    if (!is_admin() && function_exists('pll_current_language') && function_exists('pll_get_post')) {
        $translated=pll_get_post($id,pll_current_language());if ($translated) return get_post_field('post_excerpt',$translated);
    }
    return $caption;
},20,2);

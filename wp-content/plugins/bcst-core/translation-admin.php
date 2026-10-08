<?php
defined('ABSPATH') || exit;
// Hide the internal string registration name only on Polylang's translations screen.
add_action('admin_head',function(){
    if (!isset($_GET['page']) || $_GET['page']!=='mlang_strings') return;
    echo '<style>.wp-list-table .column-name{display:none!important}</style>';
});
// Language filters belong to the current screen URL, not a shared user preference.
add_filter('pll_admin_languages_filter','__return_empty_array');
add_filter('get_user_metadata',function($value,$id,$key,$single){
    if (!is_admin() || wp_doing_ajax() || $key!=='pll_filter_content') return $value;
    if (isset($_GET['page']) && $_GET['page']==='mlang_strings') return $single?'':array('');
    $lang=isset($_GET['lang']) && is_string($_GET['lang']) ? sanitize_key($_GET['lang']) : '';
    if ($lang==='all' || is_numeric($lang)) $lang='';
    return $single?$lang:array($lang);
},10,4);
function bcst_tx_list_language_default() {
    if (!function_exists('pll_default_language') || isset($_GET['lang']) || !empty($_POST)) return;
    $screen=get_current_screen();
    if (!$screen || !(($screen->base==='edit' && in_array($screen->post_type,bcst_tx_types(),true)) || $screen->base==='upload' || ($screen->base==='edit-tags' && in_array($screen->taxonomy,bcst_tx_taxonomies(),true)))) return;
    if (!empty($_GET['action']) && $_GET['action']!=='-1') return;
    $lang=pll_default_language();
    if ($lang) { wp_safe_redirect(add_query_arg('lang',$lang));exit; }
}
foreach (array('load-edit.php','load-edit-tags.php','load-upload.php') as $hook) add_action($hook,'bcst_tx_list_language_default');
add_action('admin_enqueue_scripts',function(){
    if (!current_user_can('manage_options')) return;
    $screen=get_current_screen();if (!$screen) return;
    $context='';$taxonomy='';
    if ($screen->base==='edit' && in_array($screen->post_type,bcst_tx_types(),true)) $context='content';
    if ($screen->base==='upload') $context='content';
    if ($screen->base==='edit-tags' && in_array($screen->taxonomy,bcst_tx_taxonomies(),true)) { $context='taxonomy';$taxonomy=$screen->taxonomy; }
    if (isset($_GET['page']) && $_GET['page']==='mlang_strings') $context='string';
    if (!$context) return;
    wp_enqueue_script('bcst-translation-actions',plugins_url('translation-actions.js',__FILE__),array(),'2.4.3',true);
    wp_localize_script('bcst-translation-actions','bcstTranslationLanguages',array('languages'=>bcst_bailian_languages(),'selected'=>isset($_GET['lang'])&&is_string($_GET['lang'])?sanitize_key($_GET['lang']):'all'));
    $ui=array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bcst_tx_select'),'kind'=>$context,'taxonomy'=>$taxonomy,'media'=>$screen->base==='upload','mediaList'=>admin_url('upload.php?mode=list'),'sourceLanguage'=>function_exists('pll_default_language')?pll_default_language():'en');
    if ($context==='string') $ui['strings']=array_map(function($row){return $row['string'];},bcst_tx_strings());
    wp_localize_script('bcst-translation-actions','bcstTranslationActions',$ui);
});
// Translate one short public string at a time; the native form owns saving.
add_action('wp_ajax_bcst_tx_inline',function(){
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_tx_select','nonce');
    $locked=false;
    try {
        bcst_bt_preflight();
        $id=bcst_bailian_input('id');$original=isset($_POST['original'])&&is_string($_POST['original'])?wp_unslash($_POST['original']):'';
        $source=pll_default_language();$target=bcst_bailian_input('target');
        $strings=bcst_tx_strings();$languages=bcst_bailian_languages();
        if (!isset($strings[$id]) || $strings[$id]['string']!==$original) throw new Exception('原文已变化，请保存当前修改后刷新页面。');
        if (!isset($languages[$source],$languages[$target]) || $source===$target) throw new Exception('无效的源语言或目标语言。');
        if (strlen($original)>20000) throw new Exception('此文案过长，请缩短后翻译。');
        if (!add_option('bcst_bt_lock',time(),'',false)) throw new Exception('已有翻译请求正在执行，请稍后重试。');
        $locked=true;
        $text=bcst_tx_string_value($original,$target);
        $existing=$text!=='';
        if (!$existing) {
            $result=bcst_bailian_translate($original,$source,$target);
            $text=$result['text'];
            if (trim($text)==='') throw new Exception('模型返回空译文，请重试。');
            preg_match_all('/[0-9]+(?:[.,][0-9]+)*/u',$original,$before);
            preg_match_all('/[0-9]+(?:[.,][0-9]+)*/u',$text,$after);
            sort($before[0]);sort($after[0]);
            if ($before[0]!==$after[0]) throw new Exception('译文数字发生变化，已停止填入，请检查原文。');
        }
    } catch (Throwable $e) { $error=$e->getMessage(); }
    finally { if ($locked) delete_option('bcst_bt_lock'); }
    if (isset($error)) wp_send_json_error(array('message'=>$error),400);
    wp_send_json_success(array('text'=>$text,'existing'=>$existing));
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
    foreach (bcst_tx_setting_strings() as $key=>$value) pll_register_string('bcst_'.$key,$value,'工业站设置',true);
},110);
add_action('wp_ajax_bcst_tx_select',function(){
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_tx_select','nonce');
    try {
        bcst_bt_preflight();
        $kind=bcst_bailian_input('kind');$all=bcst_bailian_input('all')==='1';$taxonomy=bcst_bailian_input('taxonomy');
        $ids=isset($_POST['ids'])&&is_array($_POST['ids'])?array_values(array_filter(wp_unslash($_POST['ids']),'is_scalar')):array();
        $ids=array_values(array_unique(array_map('strval',$ids)));
        if (!in_array($kind,array('content','taxonomy','string','settings'),true)) throw new Exception('无效翻译入口。');
        if ($all && $kind!=='settings') throw new Exception('请在列表勾选需要翻译的内容。');
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
    if (!$selection) { echo '<p>尚未选择内容，或选择已过期。请返回对应列表，勾选内容并点击“翻译”。下方已有任务仍可继续执行。</p>';return; }
    $labels=array('content'=>'产品 / 文章 / 页面 / 媒体文字','taxonomy'=>'分类 / 标签','string'=>'公共文字 / 工业站文案');
    echo '<h2>确认所选内容</h2><p>范围：'.esc_html($labels[$selection['kind']]).'；已选择 '.count($selection['ids']).' 条。自动翻译到其他所有已配置语言，列表筛选只决定显示哪些内容。</p>';
    echo '<p>补齐缺失译文，已有译文（含草稿）跳过。新产品、文章、页面保存为草稿；分类、公共文字、媒体文字保存后立即生效。关联分类、标签、父页面及页面绑定目标会自动补齐（已有依赖不覆盖）。请审核结果。</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bcst_bt_create"><input type="hidden" name="selection_hash" value="'.esc_attr(hash('sha256',wp_json_encode($selection))).'">';wp_nonce_field('bcst_bt_create');
    if ($selection['kind']==='string') {
        echo '<p><label>公共原文实际语言 <select name="source_lang">';
        foreach (bcst_bailian_languages() as $code=>$language) echo '<option value="'.esc_attr($code).'" '.selected($code,pll_default_language(),false).'>'.esc_html($language['name']).'</option>';
        echo '</select></label> 公共文字没有独立语言属性，请按原文选择。不同语言混合的文字应分批处理。</p>';
    }
    echo '<p>语言范围：'.esc_html(implode('、',array_column(bcst_bailian_languages(),'name'))).'。每条内容自动排除自身语言，已有译文（含草稿）始终跳过。</p>';
    echo '<p><label><input type="checkbox" required name="consent" value="1">同意将文字发送到当前配置的翻译服务并承担接口费用；创建任务替换本人旧任务进度，已生成内容保留。</label></p>';
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

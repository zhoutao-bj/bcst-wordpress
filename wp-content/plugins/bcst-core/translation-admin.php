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
    wp_enqueue_script('bcst-translation-actions',plugins_url('translation-actions.js',__FILE__),array(),'2.5.0',true);
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
// Execution state is scoped to this administrator and browser run, not a task page.
function bcst_tx_run_key($run) {
    if (!preg_match('/^[a-f0-9-]{36}$/D',$run)) throw new Exception('翻译会话无效，请重新勾选翻译。');
    return 'bcst_tx_run_'.get_current_user_id().'_'.$run;
}
function bcst_tx_run_progress($state) {
    $done=0;$skipped=0;$pending=0;$fragments=0;$current='';
    foreach ($state['tasks'] as $task) {
        if ($task['status']==='done') $done++;
        elseif ($task['status']==='skipped') $skipped++;
        else {
            $pending++;
            if (!$current) $current='#'.$task['id'].' → '.$task['lang'];
        }
        foreach (($task['parts']??array()) as $parts) foreach ($parts as $part) if (isset($part['text'])) $fragments++;
    }
    return array('pending'=>$pending>0,'message'=>sprintf('已处理 %d/%d 项（含关联内容），已生成 %d，已跳过 %d',$done+$skipped,count($state['tasks']),$done,$skipped).($pending?'；正在处理 '.$current.'，当前未完成内容已处理 '.$fragments.' 个片段':'；翻译完成，刷新列表可查看译文。'));
}
add_action('wp_ajax_bcst_tx_start',function(){
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_tx_select','nonce');
    try {
        bcst_bt_preflight();
        $kind=bcst_bailian_input('kind');$taxonomy=bcst_bailian_input('taxonomy');
        if (!in_array($kind,array('content','taxonomy'),true)) throw new Exception('无效翻译入口。');
        if ($kind==='taxonomy' && !in_array($taxonomy,bcst_tx_taxonomies(),true)) throw new Exception('无效分类类型。');
        $ids=isset($_POST['ids'])&&is_array($_POST['ids'])?array_values(array_unique(array_filter(array_map('absint',array_filter($_POST['ids'],'is_scalar'))))):array();
        if (!$ids || count($ids)>100) throw new Exception('请勾选 1 至 100 条内容后翻译。');
        $selection=array('kind'=>$kind,'ids'=>$ids,'taxonomy'=>$taxonomy);
        $tasks=bcst_tx_plan($selection,array_keys(bcst_bailian_languages()),false,'');
        if (!$tasks) throw new Exception('没有可处理的内容。');
        $run=wp_generate_uuid4();$state=array('tasks'=>$tasks);
        if (!set_transient(bcst_tx_run_key($run),$state,DAY_IN_SECONDS)) throw new Exception('无法保存翻译进度，请检查数据库。');
        $response=bcst_tx_run_progress($state);$response['run']=$run;
    } catch (Throwable $e) { wp_send_json_error(array('message'=>$e->getMessage()),400); }
    wp_send_json_success($response);
});
add_action('wp_ajax_bcst_tx_step',function(){
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_tx_select','nonce');
    $locked=false;
    try {
        bcst_bt_preflight();
        $key=bcst_tx_run_key(bcst_bailian_input('run'));
        if (!add_option('bcst_bt_lock',time(),'',false)) throw new Exception('已有翻译请求正在执行，请稍后再点击翻译。');
        $locked=true;
        $state=get_transient($key);
        if (!$state) throw new Exception('翻译进度已过期，请刷新列表后重新勾选翻译。');
        foreach ($state['tasks'] as &$task) if ($task['status']==='pending') {
            try { bcst_tx_tick($task); }
            catch (Throwable $e) { $failure='#'.$task['id'].' → '.$task['lang'].'：'.$e->getMessage(); }
            break;
        }
        unset($task);
        // Preserve completed fragments even after a failed request; no automatic retry.
        $state['revision']=($state['revision']??0)+1;
        if (!set_transient($key,$state,DAY_IN_SECONDS)) throw new Exception('无法保存翻译进度，已停止，请检查数据库。');
        $response=bcst_tx_run_progress($state);
        if (isset($failure)) { $response['halted']=true;$response['message'].='；已停止：'.$failure; }
    } catch (Throwable $e) { $error=$e->getMessage(); }
    finally { if ($locked) delete_option('bcst_bt_lock'); }
    if (isset($error)) wp_send_json_error(array('message'=>$error),400);
    wp_send_json_success($response);
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

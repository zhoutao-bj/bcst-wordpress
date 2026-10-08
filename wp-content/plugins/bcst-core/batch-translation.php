<?php
/** Durable, administrator-driven queue. One paid text fragment per AJAX request. */
defined('ABSPATH') || exit;
require_once __DIR__ . '/translation-content.php';
require_once __DIR__ . '/translation-admin.php';

function bcst_bt_preflight() {
    $raw = get_option('bcst_bailian', array());
    if (empty($raw['model']) || !in_array($raw['model'], bcst_bailian_models(), true)) throw new Exception('尚未配置有效翻译模型，请先前往多语言翻译中心配置翻译服务。');
    bcst_bailian_key($raw);
    bcst_bailian_endpoint(bcst_bailian_config());
    foreach (array('pll_get_post_language','pll_get_term_language','pll_get_post_translations','pll_get_term_translations','pll_set_post_language','pll_set_term_language','pll_save_post_translations','pll_save_term_translations','pll_languages_list','pll_is_translated_post_type','pll_is_translated_taxonomy') as $fn) {
        if (!function_exists($fn)) throw new Exception('请先启用 Polylang。');
    }
    if (!pll_is_translated_post_type('bcst_product') || !pll_is_translated_post_type('post') || !pll_is_translated_taxonomy('bcst_category')) throw new Exception('请在 Polylang 开启产品、文章及产品分类的多语言管理。');
    if (count(bcst_bailian_languages()) < 2) throw new Exception('请先在 Polylang 添加至少两种语言。');
}
function bcst_bt_key() { return 'bcst_bt_job_' . get_current_user_id(); }
function bcst_bt_url() { return admin_url('tools.php?page=bcst-batch-translation&view=task'); }
function bcst_bt_error($message) {
    set_transient('bcst_bt_notice_' . get_current_user_id(), $message, 300);
    return bcst_bt_url();
}
add_action('admin_menu',function () { add_management_page('多语言翻译中心','多语言翻译中心','manage_options','bcst-batch-translation','bcst_translation_center'); });
function bcst_translation_center() {
    if (!current_user_can('manage_options')) return;
    if (isset($_GET['view']) && $_GET['view'] === 'task') { bcst_bt_page(); return; }
    echo '<div class="wrap"><h1>多语言翻译中心</h1><p>先检查和配置翻译服务，再按内容类型进入管理页面。进入本页不会自动调用翻译接口，也没有整站一键翻译。</p>';
    bcst_bailian_status();
    bcst_bailian_page();
    echo '<h2>内容翻译入口</h2>';
    $entries = array(
        array('产品翻译','edit.php?post_type=bcst_product','勾选产品并点击“翻译”，补齐其他所有语言，已有译文跳过。'),
        array('文章翻译 / All Posts','edit.php','勾选文章并点击“翻译”，补齐其他所有语言，已有译文跳过。'),
        array('页面翻译 / Pages','edit.php?post_type=page','勾选页面后点击翻译按钮，自动补齐父页面、绑定分类或文章，保留排序和展示方式。'),
        array('产品分类翻译','edit-tags.php?taxonomy=bcst_category&post_type=bcst_product','勾选分类并点击“翻译”，补齐其他所有语言；父级先处理。'),
        array('文章分类翻译','edit-tags.php?taxonomy=category','勾选分类并点击“翻译”，翻译名称、描述，保留层级与语言关联。'),
        array('文章标签翻译','edit-tags.php?taxonomy=post_tag','勾选标签并点击“翻译”，补齐其他所有语言的名称和描述。'),
        array('固定界面与公共文字翻译','admin.php?page=mlang_strings','勾选公共文字，点击“翻译”补齐其他所有语言，已有译文跳过。可按分组筛选页头、页脚、表单等文案。'),
        array('媒体说明文字','upload.php?mode=list','切换媒体列表模式，勾选后翻译标题、说明、图注及图片 Alt，不处理图片或视频内部内容。'),
        array('工业站文案翻译','admin.php?page=mlang_strings&group='.rawurlencode('工业站设置'),'在工业站设置保存源文案后，从这里勾选翻译首页、页脚、地址、销售人员姓名和职位；邮箱和电话保持原样。'),
        array('语言管理','admin.php?page=mlang','新增或维护语言。批量任务的目标语言自动读取 Polylang 配置。'),
    );
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1200px;margin-top:20px">';
    foreach ($entries as $entry) {
        echo '<section style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px"><h2 style="margin-top:0"><a href="' . esc_url(admin_url($entry[1])) . '">' . esc_html($entry[0]) . '</a></h2><p>' . esc_html($entry[2]) . '</p><a class="button" href="' . esc_url(admin_url($entry[1])) . '">进入管理</a></section>';
    }
    echo '</div><h2>翻译任务</h2><p>确认所选内容，自动补齐其他所有语言，已有译文（含草稿）跳过。新产品、文章和页面保存草稿，分类、媒体文字及公共文字保存后立即生效。</p><p><a class="button" href="' . esc_url(bcst_bt_url()) . '">进入任务确认 / 查看进度</a></p></div>';
}
function bcst_bt_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>多语言翻译中心 — 批量任务</h1><p><a href="' . esc_url(admin_url('tools.php?page=bcst-batch-translation')) . '">返回多语言翻译中心</a> | <a href="' . esc_url(bcst_bailian_settings_url()) . '">配置翻译服务（API Key / 模型 / 地域）</a></p>';
    $notice = get_transient('bcst_bt_notice_' . get_current_user_id());
    if ($notice) { echo '<div class="notice notice-warning inline"><p>' . esc_html($notice) . '</p></div>'; delete_transient('bcst_bt_notice_' . get_current_user_id()); }
    try { bcst_bt_preflight(); } catch (Throwable $e) { echo '<p>' . esc_html($e->getMessage()) . '</p></div>'; return; }
    bcst_tx_form();
    $job = get_option(bcst_bt_key());
    if ($job) {
        echo '<h2>任务进度</h2><p>保持本页打开。关闭页面会停止后续请求，已完成片段保留；可返回继续。失败不会自动重试计费。</p><button class="button button-primary" id="bcst-run">开始 / 继续</button> <button class="button" id="bcst-pause">暂停后续请求</button> <button class="button" id="bcst-retry">重试失败项（可能计费）</button><pre id="bcst-progress" style="white-space:pre-wrap">' . esc_html(bcst_bt_summary($job)) . '</pre>';
        wp_enqueue_script('bcst-batch',plugins_url('batch-translation.js',__FILE__),array(),'1.0.1',true);
        wp_localize_script('bcst-batch','bcstBatch',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('bcst_bt_step')));
    }
    echo '</div>';
}
function bcst_bt_task($kind,$id,$lang) { return array('kind'=>$kind,'id'=>(int)$id,'lang'=>$lang,'status'=>'pending'); }
function bcst_bt_add_term(&$tasks,$id,$lang,$seen=array()) {
    if (isset($seen[$id])) throw new Exception('分类存在循环父级关系。');
    $seen[$id] = true;
    $term = get_term($id,'bcst_category');
    if (!$term || is_wp_error($term)) throw new Exception('产品分类不存在。');
    if ($term->parent) bcst_bt_add_term($tasks,(int)$term->parent,$lang,$seen);
    $key = 'term-' . $id . '-' . $lang;
    if (!isset($tasks[$key])) $tasks[$key] = bcst_bt_task('term',$id,$lang);
}
add_action('admin_post_bcst_bt_create',function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden','',array('response'=>403));
    check_admin_referer('bcst_bt_create');
    $creation_lock=false;
    try {
        bcst_bt_preflight();
        if (!add_option('bcst_bt_lock',time(),'',false)) throw new Exception('有翻译请求正在执行，请稍后再创建任务。');
        $creation_lock=true;
        if (bcst_bailian_input('consent') !== '1') throw new Exception('请确认文字发送和费用。');
        $targets = array_keys(bcst_bailian_languages());
        if (count($targets)<2) throw new Exception('请先配置至少两种语言。');
        $selection = get_transient('bcst_tx_selection_' . get_current_user_id());
        if (!$selection || !hash_equals(hash('sha256',wp_json_encode($selection)),bcst_bailian_input('selection_hash'))) throw new Exception('所选内容已过期或在其他标签页被更改，请刷新确认页重新核对。');
        $tasks = bcst_tx_plan($selection,$targets,false,bcst_bailian_input('source_lang'));
        if (!$tasks) throw new Exception('没有可处理的内容。');
        if (count($tasks)>3000) throw new Exception('任务超过 3000 项，请缩小范围。');
        update_option(bcst_bt_key(),array('tasks'=>array_values($tasks),'created'=>time()),false);
        $message = '任务已创建，点击开始 / 继续执行。自动补齐其他所有语言，同源语言和已有译文（含草稿）跳过。';
    } catch (Throwable $e) { $message = $e->getMessage(); }
    finally { if ($creation_lock) delete_option('bcst_bt_lock'); }
    wp_safe_redirect(bcst_bt_error($message)); exit;
});

// Preserve markup verbatim; translate only visible text, in bounded UTF-8 fragments.
function bcst_bt_parts($text) {
    $parts = preg_split('/(<!--[\s\S]*?-->|<[^>]*>|\[[^\]\r\n]*\]|https?:\/\/[^\s<>]+|&(?:#\d+|#x[0-9a-fA-F]+|[a-zA-Z]+);|\r\n|\r|\n|\|)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) throw new Exception('内容不是有效 UTF-8。');
    $out = array(); $raw = false;
    foreach ($parts as $part) {
        if (preg_match('/^<(script|style|code|pre)\b/i',$part)) $raw = true;
        $literal = $raw || preg_match('/^(?:<|\[|https?:\/\/|&)/u',$part) || !preg_match('/\p{L}/u',$part);
        if ($literal) $out[] = array('source'=>$part,'text'=>$part);
        else {
            preg_match_all('/.{1,700}(?:\s+|$)|.{1,700}/us',$part,$chunks);
            foreach ($chunks[0] as $chunk) $out[] = array('source'=>$chunk);
        }
        if (preg_match('/^<\/(script|style|code|pre)\s*>/i',$part)) $raw = false;
    }
    return $out;
}
function bcst_bt_source($task) {
    if ($task['kind'] === 'term') {
        $t = get_term($task['id'],'bcst_category');
        if (!$t || is_wp_error($t)) throw new Exception('源分类不存在。');
        return array('language'=>pll_get_term_language($t->term_id),'fields'=>array('name'=>$t->name,'description'=>$t->description),'parent'=>(int)$t->parent);
    }
    $p = get_post($task['id']);
    if (!$p || !in_array($p->post_type,array('post','bcst_product'),true) || $p->post_status === 'trash' || !current_user_can('edit_post',$p->ID)) throw new Exception('源内容不存在、已删除或不可编辑。');
    $fields = array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt);
    $meta = array(); $terms = array();
    if ($p->post_type === 'bcst_product') {
        foreach (array('specs','faq') as $key) $fields['_bcst_' . $key] = get_post_meta($p->ID,'_bcst_' . $key,true);
        foreach (array('model','gallery','download','video','related') as $key) $meta['_bcst_' . $key] = get_post_meta($p->ID,'_bcst_' . $key,true);
        $terms = wp_get_object_terms($p->ID,'bcst_category',array('fields'=>'ids'));
        if (is_wp_error($terms)) throw new Exception($terms->get_error_message());
    }
    $meta['_thumbnail_id'] = get_post_thumbnail_id($p->ID);
    return array('language'=>pll_get_post_language($p->ID),'fields'=>$fields,'type'=>$p->post_type,'meta'=>$meta,'terms'=>$terms);
}
function bcst_bt_links($task) { return $task['kind'] === 'term' ? pll_get_term_translations($task['id']) : pll_get_post_translations($task['id']); }
function bcst_bt_commit(&$task,$source,$fields) {
    $links = bcst_bt_links($task);
    if (!empty($links[$task['lang']])) { $task['status']='skipped'; return; }
    $lang = $task['lang'];
    if ($task['kind'] === 'term') {
        $parent = 0;
        if ($source['parent']) {
            $parents = pll_get_term_translations($source['parent']);
            $parent = $parents[$lang] ?? 0;
            if (!$parent) throw new Exception('父分类翻译未完成，请先修复失败的父分类。');
        }
        $slug = 'bcst-tr-term-' . $task['id'] . '-' . $lang;
        $existing = get_term_by('slug',$slug,'bcst_category');
        if ($existing && get_term_meta($existing->term_id,'_bcst_translation_source',true) != $task['id']) throw new Exception('分类别名冲突，未覆盖。');
        if ($existing) $id = $existing->term_id;
        else {
            $new = wp_insert_term(wp_strip_all_tags($fields['name']),'bcst_category',array('slug'=>$slug,'parent'=>$parent,'description'=>$fields['description']));
            if (is_wp_error($new)) throw new Exception($new->get_error_message());
            $id = $new['term_id']; update_term_meta($id,'_bcst_translation_source',$task['id']);
        }
        pll_set_term_language($id,$lang); $links[$lang]=$id; pll_save_term_translations($links);
    } else {
        $translated_terms = array();
        foreach ($source['terms'] as $term_id) {
            $map = pll_get_term_translations($term_id);
            if (empty($map[$lang])) throw new Exception('所属产品分类翻译未完成，请先重试失败分类。');
            $translated_terms[] = (int)$map[$lang];
        }
        $slug = 'bcst-tr-post-' . $task['id'] . '-' . $lang;
        $existing = get_page_by_path($slug,OBJECT,$source['type']);
        if ($existing && get_post_meta($existing->ID,'_bcst_translation_source',true) != $task['id']) throw new Exception('译文别名冲突，未覆盖。');
        if ($existing) $id = $existing->ID;
        else {
            $id = wp_insert_post(wp_slash(array('post_type'=>$source['type'],'post_status'=>'draft','post_name'=>$slug,'post_title'=>wp_strip_all_tags($fields['post_title']),'post_content'=>$fields['post_content'],'post_excerpt'=>$fields['post_excerpt'],'comment_status'=>'closed','meta_input'=>array('_bcst_translation_source'=>$task['id']))),true);
            if (is_wp_error($id)) throw new Exception($id->get_error_message());
        }
        pll_set_post_language($id,$lang);
        if ($source['type'] === 'bcst_product') {
            $result = wp_set_object_terms($id,$translated_terms,'bcst_category');
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            foreach (array('_bcst_specs','_bcst_faq') as $key) update_post_meta($id,$key,wp_slash($fields[$key]));
        }
        foreach ($source['meta'] as $key=>$value) {
            // Do not link translated pages to unrelated source-language products.
            if ($key === '_bcst_related') {
                $related = array();
                foreach (array_filter(array_map('absint',explode(',',$value))) as $rid) { $map=pll_get_post_translations($rid); if (!empty($map[$lang])) $related[]=$map[$lang]; }
                $value = implode(',',$related);
            }
            update_post_meta($id,$key,is_string($value)?wp_slash($value):$value);
        }
        $links[$lang]=$id; pll_save_post_translations($links);
    }
    $task['status']='done'; $task['result']=$id; unset($task['parts']);
}
function bcst_bt_tick(&$task) {
    if (in_array($task['kind'],array('content','taxonomy','string'),true)) { bcst_tx_tick($task); return; }
    $source = bcst_bt_source($task);
    $links = bcst_bt_links($task);
    $languages = bcst_bailian_languages();
    if (!isset($languages[$source['language']],$languages[$task['lang']])) throw new Exception('源内容语言未设置或任务语言已被删除，请检查 Polylang 并重新创建任务。');
    if ($source['language'] === $task['lang'] || !empty($links[$task['lang']])) { $task['status']='skipped'; unset($task['parts']); return; }
    $hash = hash('sha256',wp_json_encode($source));
    if (isset($task['hash']) && $task['hash'] !== $hash) throw new Exception('源内容已变更，请重新创建任务，避免混用新旧译文。');
    $task['hash']=$hash;
    if (!isset($task['parts'])) { foreach ($source['fields'] as $field=>$text) $task['parts'][$field] = bcst_bt_parts((string)$text); }
    foreach ($task['parts'] as &$parts) foreach ($parts as &$part) {
        if (isset($part['text'])) continue;
        $result = bcst_bailian_translate($part['source'],$source['language'],$task['lang']);
        // Keep text from becoming executable HTML; original tags are separate literal parts.
        preg_match('/^\s*/u',$part['source'],$prefix); preg_match('/\s*$/u',$part['source'],$suffix);
        preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$part['source'],$before);
        preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$result['text'],$after);
        sort($before[0]); sort($after[0]);
        if ($before[0] !== $after[0]) throw new Exception('译文中的数字发生变化，已阻止保存。请审核原文或重试。');
        $safe = str_replace(array('[',']','|'),array('&#91;','&#93;','&#124;'),esc_html(trim($result['text'])));
        $part['text'] = $prefix[0] . $safe . $suffix[0];
        $task['tokens'] = ($task['tokens'] ?? 0) + $result['tokens'];
        return;
    }
    unset($parts,$part);
    $fields=array(); foreach ($task['parts'] as $name=>$parts) $fields[$name]=implode('',array_column($parts,'text'));
    foreach (array('name','post_title','_bcst_specs','_bcst_faq') as $plain) if (isset($fields[$plain])) $fields[$plain]=html_entity_decode($fields[$plain],ENT_QUOTES | ENT_HTML5,'UTF-8');
    bcst_bt_commit($task,$source,$fields);
}
function bcst_bt_summary($job) {
    $counts = array('pending'=>0,'done'=>0,'skipped'=>0,'failed'=>0); $lines=array();
    foreach ($job['tasks'] as $t) {
        $counts[$t['status']]++;
        if ($t['status']==='failed') $lines[]=$t['kind'] . ' #' . $t['id'] . ' → ' . $t['lang'] . '：' . $t['error'];
    }
    return sprintf('待处理 %d | 已生成 %d | 已跳过 %d | 失败 %d',$counts['pending'],$counts['done'],$counts['skipped'],$counts['failed']) . "\n" . implode("\n",$lines);
}
add_action('wp_ajax_bcst_bt_step',function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message'=>'没有操作权限。'),403);
    check_ajax_referer('bcst_bt_step','nonce');
    $locked=false;
    try {
        bcst_bt_preflight();
        $job=get_option(bcst_bt_key()); if (!$job) throw new Exception('任务不存在，请重新创建。');
        if (!add_option('bcst_bt_lock',time(),'',false)) throw new Exception('有任务正在执行，请稍后继续。如 PHP 异常终止留下锁，请联系管理员核实后清理 bcst_bt_lock。');
        $locked=true;
        if (bcst_bailian_input('retry')==='1') {
            foreach ($job['tasks'] as &$task) if ($task['status']==='failed') { $task['status']='pending'; unset($task['error']); }
            unset($task);
        } else {
            foreach ($job['tasks'] as &$task) if ($task['status']==='pending') {
                try { bcst_bt_tick($task); } catch (Throwable $e) { $task['status']='failed'; $task['error']=$e->getMessage(); $halt=true; }
                break;
            }
            unset($task);
        }
        update_option(bcst_bt_key(),$job,false);
        $pending=false; foreach ($job['tasks'] as $task) if ($task['status']==='pending') $pending=true;
        $response=array('message'=>bcst_bt_summary($job) . (!empty($halt) ? "\n遇到错误已暂停后续请求，请修复后重试失败项。" : ''),'pending'=>$pending && empty($halt));
    } catch (Throwable $e) { $error=$e->getMessage();
    } finally { if ($locked) delete_option('bcst_bt_lock'); }
    if (isset($error)) wp_send_json_error(array('message'=>$error . ' 可前往设置检查配置。'));
    wp_send_json_success($response);
});

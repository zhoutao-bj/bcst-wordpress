<?php
/** Content adapters for the administrator-driven translation queue. */
defined('ABSPATH') || exit;

function bcst_tx_types() { return array('post','page','bcst_product','attachment'); }
function bcst_tx_taxonomies() { return array('category','post_tag','bcst_category'); }
function bcst_tx_strings() {
    if (!class_exists('PLL_Admin_Strings') || !is_callable(array('PLL_Admin_Strings','get_strings'))) throw new Exception('当前 Polylang 不提供公共文字接口，请检查插件版本。');
    return PLL_Admin_Strings::get_strings();
}
function bcst_tx_mo($language) {
    if (!class_exists('PLL_MO') || !function_exists('PLL')) throw new Exception('公共文字翻译存储接口不可用。');
    $lang=PLL()->model->get_language($language);
    if (!$lang) throw new Exception('目标语言不存在。');
    $mo=new PLL_MO(); $mo->import_from_db($lang);
    return array($mo,$lang);
}
function bcst_tx_string_value($text,$language) {
    list($mo) = bcst_tx_mo($language);
    $entry=$mo->translate_entry(new Translation_Entry(array('singular'=>$text)));
    return $entry && isset($entry->translations[0]) ? (string)$entry->translations[0] : '';
}
function bcst_tx_source($task) {
    if ($task['kind']==='string') {
        $strings=bcst_tx_strings();
        if (!isset($strings[$task['id']]) || $strings[$task['id']]['string']!==$task['original']) throw new Exception('公共原文已变更或未注册，请重新选择。');
        return array('language'=>$task['source_lang'],'fields'=>array('text'=>$task['original']),'registration'=>$strings[$task['id']]);
    }
    if ($task['kind']==='taxonomy') {
        $term=get_term($task['id'],$task['taxonomy']);
        if (!$term || is_wp_error($term) || !in_array($term->taxonomy,bcst_tx_taxonomies(),true) || !current_user_can('edit_term',$term->term_id)) throw new Exception('分类或标签不存在，或没有编辑权限。');
        if (!pll_is_translated_taxonomy($term->taxonomy)) throw new Exception('请先在 Polylang 开启该分类或标签的多语言管理。');
        return array('language'=>pll_get_term_language($term->term_id),'fields'=>array('name'=>$term->name,'description'=>$term->description),'parent'=>(int)$term->parent,'taxonomy'=>$term->taxonomy);
    }
    $p=get_post($task['id']);
    if (!$p || !in_array($p->post_type,bcst_tx_types(),true) || $p->post_status==='trash' || !current_user_can('edit_post',$p->ID)) throw new Exception('内容不存在、已删除或无编辑权限。');
    if (!pll_is_translated_post_type($p->post_type)) throw new Exception('请先在 Polylang 开启此内容类型的多语言管理（媒体请开启 Media）。');
    $fields=array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt);
    $meta=array();$terms=array();
    if ($p->post_type==='bcst_product') {
        foreach (array('specs','faq') as $key) $fields['_bcst_'.$key]=get_post_meta($p->ID,'_bcst_'.$key,true);
        foreach (array('model','gallery','download','video','related') as $key) $meta['_bcst_'.$key]=get_post_meta($p->ID,'_bcst_'.$key,true);
    }
    if ($p->post_type==='attachment') $fields['_wp_attachment_image_alt']=get_post_meta($p->ID,'_wp_attachment_image_alt',true);
    // Explicit adapters, never translate arbitrary plugin metadata or secrets.
    foreach (array('_yoast_wpseo_title','_yoast_wpseo_metadesc','rank_math_title','rank_math_description') as $key) {
        if (metadata_exists('post',$p->ID,$key)) $fields[$key]=get_post_meta($p->ID,$key,true);
    }
    $meta['_thumbnail_id']=get_post_thumbnail_id($p->ID);
    if ($p->post_type==='page') {
        $display=bcst_page_display_settings($p->ID);
        $meta['_bcst_page_mode']=$display['mode'];$meta['_bcst_page_category']=$display['category'];$meta['_bcst_page_article']=$display['article'];
        $meta['_wp_page_template']=get_post_meta($p->ID,'_wp_page_template',true);
    }
    foreach (bcst_tx_taxonomies() as $taxonomy) if (is_object_in_taxonomy($p->post_type,$taxonomy)) {
        $ids=wp_get_object_terms($p->ID,$taxonomy,array('fields'=>'ids'));
        if (is_wp_error($ids)) throw new Exception($ids->get_error_message());
        $terms[$taxonomy]=$ids;
    }
    return array('language'=>pll_get_post_language($p->ID),'fields'=>$fields,'meta'=>$meta,'terms'=>$terms,'type'=>$p->post_type,'parent'=>$p->post_type==='page'?(int)$p->post_parent:0,'order'=>(int)$p->menu_order);
}
function bcst_tx_target($task) {
    if ($task['kind']==='string') return bcst_tx_string_value($task['original'],$task['lang']);
    $links=$task['kind']==='taxonomy'?pll_get_term_translations($task['id']):pll_get_post_translations($task['id']);
    return !empty($links[$task['lang']])?(int)$links[$task['lang']]:0;
}
function bcst_tx_target_hash($task) {
    $target=bcst_tx_target($task);
    if ($task['kind']==='string') return hash('sha256',$target);
    if (!$target) return 'missing';
    $copy=$task;$copy['id']=$target;
    $content=bcst_tx_source($copy);
    if ($task['kind']==='content') $content['status']=get_post_status($target);
    return hash('sha256',wp_json_encode(array($target,$content)));
}
function bcst_tx_add(&$tasks,$kind,$id,$lang,$overwrite=false,$extra=array(),$path=array()) {
    $key=$kind.':'.$id.':'.$lang;
    if (isset($path[$key])) throw new Exception('页面或分类存在循环关联，请先修正层级或导航目标。');
    if (isset($tasks[$key])) { if ($overwrite) $tasks[$key]['overwrite']=true; return; }
    $task=array_merge(array('kind'=>$kind,'id'=>$id,'lang'=>$lang,'status'=>'pending','overwrite'=>$overwrite),$extra);
    $source=bcst_tx_source($task);
    if (!isset(bcst_bailian_languages()[$source['language']])) throw new Exception('源内容 #'.$id.' 未指定有效语言。');
    $path[$key]=true;
    if ($source['language']!==$lang && ($overwrite || !bcst_tx_target($task))) {
        if ($kind==='taxonomy' && $source['parent']) bcst_tx_add($tasks,'taxonomy',$source['parent'],$lang,false,array('taxonomy'=>$source['taxonomy']),$path);
        if ($kind==='content') {
            if ($source['parent']) bcst_tx_add($tasks,'content',$source['parent'],$lang,false,array(),$path);
            foreach ($source['terms'] as $taxonomy=>$ids) foreach ($ids as $tid) bcst_tx_add($tasks,'taxonomy',(int)$tid,$lang,false,array('taxonomy'=>$taxonomy),$path);
            if ($source['type']==='page') {
                if ($source['meta']['_bcst_page_mode']==='category' && $source['meta']['_bcst_page_category']) bcst_tx_add($tasks,'taxonomy',$source['meta']['_bcst_page_category'],$lang,false,array('taxonomy'=>'category'),$path);
                if ($source['meta']['_bcst_page_mode']==='article' && $source['meta']['_bcst_page_article']) bcst_tx_add($tasks,'content',$source['meta']['_bcst_page_article'],$lang,false,array(),$path);
            }
        }
    }
    $task['planned_source']=hash('sha256',wp_json_encode($source));
    $task['planned_target']=bcst_tx_target_hash($task);
    $tasks[$key]=$task;
    if (count($tasks)>3000) throw new Exception('任务超过 3000 项，请分批选择。');
}
function bcst_tx_mapped($id,$lang,$term=false) {
    if (!$id) return 0;
    $translated=$term?pll_get_term($id,$lang):pll_get_post($id,$lang);
    if (!$translated) throw new Exception('依赖内容 #'.$id.' 的 '.$lang.' 译文未完成，请先处理失败项。');
    return (int)$translated;
}
function bcst_tx_commit(&$task,$source,$fields) {
    if (bcst_tx_target_hash($task)!==$task['planned_target']) throw new Exception('目标译文在任务创建后已变更，为保护人工编辑已停止；请重新创建任务。');
    $lang=$task['lang'];$id=bcst_tx_target($task);
    if ($task['kind']==='string') {
        list($mo,$language)=bcst_tx_mo($lang);$row=$source['registration'];
        $text=apply_filters('pll_sanitize_string_translation',$fields['text'],$row['name'],$row['context'],$task['original'],$id);
        $mo->add_entry($mo->make_entry($task['original'],$text));$mo->export_to_db($language);
        do_action('pll_save_strings_translations');
        if (bcst_tx_string_value($task['original'],$lang)!==$text) throw new Exception('公共文字保存校验失败，请检查数据库。');
    } elseif ($task['kind']==='taxonomy') {
        $args=array('name'=>wp_strip_all_tags($fields['name']),'description'=>$fields['description'],'parent'=>bcst_tx_mapped($source['parent'],$lang,true));
        if ($id) $saved=wp_update_term($id,$source['taxonomy'],wp_slash($args));
        else {
            $slug='bcst-tr-term-'.$task['id'].'-'.$lang;
            $existing=get_term_by('slug',$slug,$source['taxonomy']);
            if ($existing) throw new Exception('分类别名已存在但未关联，请先人工核对。');
            $args['slug']=$slug;$saved=wp_insert_term($args['name'],$source['taxonomy'],wp_slash($args));
        }
        if (is_wp_error($saved)) throw new Exception($saved->get_error_message());
        $id=(int)$saved['term_id'];pll_set_term_language($id,$lang);
        $links=pll_get_term_translations($task['id']);$links[$lang]=$id;pll_save_term_translations($links);
    } else {
        $fresh=!$id;$mapped_terms=array();
        foreach ($source['terms'] as $taxonomy=>$ids) foreach ($ids as $tid) $mapped_terms[$taxonomy][]=bcst_tx_mapped($tid,$lang,true);
        $meta=$source['meta'];
        if ($source['type']==='page') {
            $meta['_bcst_page_category']=$meta['_bcst_page_mode']==='category'?bcst_tx_mapped($meta['_bcst_page_category'],$lang,true):0;
            $meta['_bcst_page_article']=$meta['_bcst_page_mode']==='article'?bcst_tx_mapped($meta['_bcst_page_article'],$lang):0;
        }
        $parent=bcst_tx_mapped($source['parent'],$lang);
        if ($fresh && $source['type']==='attachment') {
            if (!is_callable(array(PLL()->model->post,'create_media_translation'))) throw new Exception('当前 Polylang 媒体翻译接口不可用。');
            $id=PLL()->model->post->create_media_translation($task['id'],$lang);
            if (!$id || is_wp_error($id)) throw new Exception('无法创建媒体语言版本。');
        }
        $data=array('post_title'=>wp_strip_all_tags($fields['post_title']),'post_content'=>$fields['post_content'],'post_excerpt'=>$fields['post_excerpt'],'menu_order'=>$source['order']);
        if ($source['type']==='page') $data['post_parent']=$parent;
        if ($id) $data['ID']=$id;
        else {
            $slug='bcst-tr-post-'.$task['id'].'-'.$lang;
            if (get_page_by_path($slug,OBJECT,$source['type'])) throw new Exception('译文别名已存在但未关联，请先人工核对。');
            $data+=array('post_type'=>$source['type'],'post_status'=>'draft','post_name'=>$slug,'comment_status'=>'closed');
        }
        $saved=wp_insert_post(wp_slash($data),true);
        if (!$saved || is_wp_error($saved)) throw new Exception(is_wp_error($saved)?$saved->get_error_message():'保存译文失败。');
        $id=(int)$saved;pll_set_post_language($id,$lang);
        foreach ($source['terms'] as $taxonomy=>$unused) {
            $result=wp_set_object_terms($id,$mapped_terms[$taxonomy]??array(),$taxonomy);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
        }
        foreach ($fields as $key=>$value) if (substr($key,0,1)==='_' || strpos($key,'rank_math_')===0) $meta[$key]=$value;
        foreach ($meta as $key=>$value) {
            if ($key==='_bcst_related') {
                $mapped=array();foreach (array_filter(array_map('absint',explode(',',$value))) as $rid) { $translated=pll_get_post($rid,$lang);if ($translated) $mapped[]=$translated; }$value=implode(',',$mapped);
            }
            if ($key==='_thumbnail_id' && $value) $value=pll_get_post($value,$lang)?:$value;
            update_post_meta($id,$key,is_string($value)?wp_slash($value):$value);
        }
        $links=pll_get_post_translations($task['id']);$links[$lang]=$id;pll_save_post_translations($links);
    }
    $task['status']='done';$task['result']=$id;unset($task['parts']);
}
function bcst_tx_tick(&$task) {
    $source=bcst_tx_source($task);$languages=bcst_bailian_languages();
    if (!isset($languages[$source['language']],$languages[$task['lang']])) throw new Exception('源语言或目标语言不存在。');
    if ($source['language']===$task['lang'] || (empty($task['overwrite']) && bcst_tx_target($task)!=='' && bcst_tx_target($task)!==0)) { $task['status']='skipped';unset($task['parts']);return; }
    if (hash('sha256',wp_json_encode($source))!==$task['planned_source']) throw new Exception('源内容已变更，请重新创建任务。');
    if (bcst_tx_target_hash($task)!==$task['planned_target']) throw new Exception('目标译文已变更，请重新创建任务，避免覆盖人工编辑。');
    if (!isset($task['parts'])) foreach ($source['fields'] as $key=>$value) $task['parts'][$key]=bcst_tx_parts((string)$value);
    foreach ($task['parts'] as &$parts) foreach ($parts as &$part) {
        if (isset($part['text'])) continue;
        $result=bcst_bailian_translate($part['source'],$source['language'],$task['lang']);
        preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$part['source'],$before);preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$result['text'],$after);sort($before[0]);sort($after[0]);
        if ($before[0]!==$after[0]) throw new Exception('译文数字发生变化，已阻止保存。');
        preg_match('/^\s*/u',$part['source'],$prefix);preg_match('/\s*$/u',$part['source'],$suffix);
        $part['text']=$prefix[0].str_replace(array('[',']','|'),array('&#91;','&#93;','&#124;'),esc_html(trim($result['text']))).$suffix[0];
        $task['tokens']=($task['tokens']??0)+$result['tokens'];return;
    }
    unset($parts,$part);$fields=array();
    foreach ($task['parts'] as $key=>$parts) {
        $value=implode('',array_column($parts,'text'));
        $html=in_array($key,array('post_content','post_excerpt','description'),true) || ($key==='text' && strpos($task['original'],'<')!==false);
        $fields[$key]=$html?$value:html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    bcst_tx_commit($task,$source,$fields);
}
// Protect placeholders, URLs, email addresses and typical model codes as literal segments.
function bcst_tx_parts($text) {
    $out=array();
    foreach (bcst_bt_parts($text) as $part) {
        if (isset($part['text'])) {
            // Translate human-facing attributes without changing href, src, IDs or classes.
            if (preg_match('/^<(?![!\/])/', $part['source']) && !preg_match('/^<(?:script|style|code|pre)\b/i',$part['source'])) {
                $tag=$part['source'];$offset=0;
                preg_match_all('/\s(?:alt|title|aria-label|placeholder)\s*=\s*(["\'])(.*?)\1/is',$tag,$matches,PREG_OFFSET_CAPTURE);
                foreach ($matches[2] as $match) {
                    $before=substr($tag,$offset,$match[1]-$offset);$out[]=array('source'=>$before,'text'=>$before);
                    foreach (bcst_tx_parts($match[0]) as $attribute_part) $out[]=$attribute_part;
                    $offset=$match[1]+strlen($match[0]);
                }
                $tail=substr($tag,$offset);$out[]=array('source'=>$tail,'text'=>$tail);
            } else $out[]=$part;
            continue;
        }
        $pattern='/(%%[^%]+%%|%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|%%|\{\{[^}]+\}\}|\{[a-zA-Z_][a-zA-Z0-9_]*\}|[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}|\b[A-Z][A-Z0-9]*[-_][A-Z0-9-]*\d[A-Z0-9-]*\b)/u';
        $bits=preg_split($pattern,$part['source'],-1,PREG_SPLIT_DELIM_CAPTURE);
        foreach ($bits as $i=>$bit) if ($bit!=='') $out[]=($i%2 || !preg_match('/\p{L}/u',$bit))?array('source'=>$bit,'text'=>$bit):array('source'=>$bit);
    }
    return $out;
}

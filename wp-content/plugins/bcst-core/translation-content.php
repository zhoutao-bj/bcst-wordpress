<?php
/** Content adapters for inline list translation. */
defined('ABSPATH') || exit;

function bcst_tx_types() { return array('post','page','bcst_product','attachment'); }
function bcst_tx_taxonomies() { return array('category','post_tag','bcst_category'); }
function bcst_tx_strings() {
    if (!class_exists('PLL_Admin_Strings') || !is_callable(array('PLL_Admin_Strings','get_strings'))) throw new Exception('当前 Polylang 不提供公共文字接口，请检查插件版本。');
    // Date/time formats are PHP format tokens, not prose. Keep them in
    // Polylang's native editor, but never send them to a translation model.
    return array_filter(PLL_Admin_Strings::get_strings(),function($row){
        $name=$row['name']??'';
        return !in_array($name,array('date_format','time_format'),true) && !preg_match('/^bcst_person_\d+_name$/',$name);
    });
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
    if (count($tasks)>3000) throw new Exception('翻译范围超过 3000 项，请分批选择。');
}
function bcst_tx_mapped($id,$lang,$term=false) {
    if (!$id) return 0;
    $translated=$term?pll_get_term($id,$lang):pll_get_post($id,$lang);
    if (!$translated) throw new Exception('依赖内容 #'.$id.' 的 '.$lang.' 译文未完成，请先处理失败项。');
    return (int)$translated;
}
function bcst_tx_commit(&$task,$source,$fields) {
    if (bcst_tx_target_hash($task)!==$task['planned_target']) throw new Exception('目标译文在本次翻译开始后已变更，为保护人工编辑已停止；请刷新列表后重新勾选翻译。');
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
    if (hash('sha256',wp_json_encode($source))!==$task['planned_source']) throw new Exception('源内容已变更，请刷新列表后重新勾选翻译。');
    if (bcst_tx_target_hash($task)!==$task['planned_target']) throw new Exception('目标译文已变更，请刷新列表后重新勾选翻译，避免覆盖人工编辑。');
    if (!isset($task['parts'])) foreach ($source['fields'] as $key=>$value) $task['parts'][$key]=bcst_tx_parts((string)$value);
    foreach ($task['parts'] as &$parts) if (bcst_tx_translate_part($parts,$source['language'],$task['lang'],$task)) return;
    unset($parts);$fields=array();
    foreach ($task['parts'] as $key=>$parts) {
        $value=implode('',array_column($parts,'text'));
        $html=in_array($key,array('post_content','post_excerpt','description'),true) || ($key==='text' && strpos($task['original'],'<')!==false);
        $fields[$key]=$html?$value:html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
    }
    bcst_tx_commit($task,$source,$fields);
}
// A call translates at most one fragment (including human-facing attributes).
function bcst_tx_translate_part(&$parts,$source,$target,&$task) {
    foreach ($parts as &$part) {
        if (isset($part['text'])) continue;
        if (isset($part['children'])) {
            if (bcst_tx_translate_part($part['children'],$source,$target,$task)) return true;
            $part['text']=implode('',array_column($part['children'],'text'));continue;
        }
        if (isset($part['keep'])) foreach ($part['keep'] as &$children) {
            if (bcst_tx_translate_part($children,$source,$target,$task)) return true;
        }
        unset($children);
        $result=bcst_bailian_translate($part['source'],$source,$target);
        $part['text']=bcst_tx_restore_fragment($part,$result['text']);
        $task['tokens']=($task['tokens']??0)+$result['tokens'];return true;
    }
    return false;
}
function bcst_tx_restore_fragment($part,$translated) {
    $map=array();foreach (($part['keep']??array()) as $token=>$children) $map[$token]=implode('',array_column($children,'text'));
    preg_match_all('/__BCST_[a-f0-9]{12}_\d+__/',$part['source'],$expected);
    preg_match_all('/__BCST_[a-f0-9]{12}_\d+__/',$translated,$actual);
    if ($expected[0]!==$actual[0]) throw new Exception('译文的标签或保护占位符发生变化，已阻止保存；不会自动重试计费。');
    $before_text=strtr($part['source'],array_fill_keys(array_keys($map),''));
    $after_text=strtr($translated,array_fill_keys(array_keys($map),''));
    preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$before_text,$before);preg_match_all('/[0-9]+(?:[.,][0-9]+)*/',$after_text,$after);sort($before[0]);sort($after[0]);
    if ($before[0]!==$after[0]) throw new Exception('译文数字发生变化，已阻止保存。');
    preg_match('/^\s*/u',$part['source'],$prefix);preg_match('/\s*$/u',$part['source'],$suffix);
    $escaped=str_replace(array('[',']','|'),array('&#91;','&#93;','&#124;'),esc_html(trim($translated)));
    return $prefix[0].strtr($escaped,$map).$suffix[0];
}
// Keep sentences together across entities, inline markup, URLs and placeholders.
function bcst_tx_parts($text,$attribute=false) {
    $out=array();$buffer='';$keep=array();$index=0;
    $salt=substr(hash('sha256',$text),0,12);
    while (strpos($text,'__BCST_'.$salt.'_')!==false) $salt=substr(hash('sha256',$salt),0,12);
    $protect=function($children) use (&$keep,&$index,$salt) {
        $token='__BCST_'.$salt.'_'.(++$index).'__';$keep[$token]=$children;return $token;
    };
    $flush=function() use (&$out,&$buffer,&$keep) {
        if ($buffer==='') return;
        // Never cut a sentence or an atomic protected token merely to fit a limit.
        $sentences=strlen($buffer)<=5500?array($buffer):preg_split('/(?<=[.!?。！？])(?=\s)/u',$buffer);
        $chunks=array();$chunk='';foreach ($sentences as $sentence) {
            if (strlen($sentence)>5500) throw new Exception('单句超过翻译长度限制，请先拆成完整短句后重试。');
            if (strlen($chunk.$sentence)>5500) {$chunks[]=$chunk;$chunk='';}
            $chunk.=$sentence;
        }
        if ($chunk!=='') $chunks[]=$chunk;
        foreach ($chunks as $chunk) {
            $map=array();foreach ($keep as $token=>$children) if (strpos($chunk,$token)!==false) $map[$token]=$children;
            $visible=strtr($chunk,array_fill_keys(array_keys($map),''));
            if (!preg_match('/\p{L}/u',$visible)) {
                $pieces=preg_split('/(__BCST_[a-f0-9]{12}_\d+__)/',$chunk,-1,PREG_SPLIT_DELIM_CAPTURE);$children=array();
                foreach ($pieces as $piece) if (isset($map[$piece])) $children=array_merge($children,$map[$piece]);else $children[]=array('source'=>$piece,'text'=>str_replace(array('[',']','|'),array('&#91;','&#93;','&#124;'),esc_html($piece)));
                $out[]=array('children'=>$children);
            } else $out[]=array('source'=>$chunk,'keep'=>$map);
        }
        $buffer='';$keep=array();
    };
    $parts=$attribute?array(array('source'=>$text)):bcst_bt_parts($text);
    foreach ($parts as $part) {
        $value=$part['source'];
        if (!empty($part['opaque'])) {$flush();$out[]=$part;continue;}
        $inline=!$attribute && preg_match('/^<\/?(?:a|abbr|b|bdi|bdo|br|cite|del|em|i|img|ins|mark|q|s|small|span|strong|sub|sup|u|wbr)\b/i',$value);
        if (!$attribute && isset($part['text']) && preg_match('/^<(?![!\/])/', $value) && !preg_match('/^<(?:script|style|code|pre)\b/i',$value)) {
            $children=array();$offset=0;
            preg_match_all('/\s(?:alt|title|aria-label|placeholder)\s*=\s*(["\'])(.*?)\1/is',$value,$matches,PREG_OFFSET_CAPTURE);
            foreach ($matches[2] as $match) {
                $literal=substr($value,$offset,$match[1]-$offset);$children[]=array('source'=>$literal,'text'=>$literal);
                $children=array_merge($children,bcst_tx_parts($match[0],true));$offset=$match[1]+strlen($match[0]);
            }
            $literal=substr($value,$offset);$children[]=array('source'=>$literal,'text'=>$literal);
            if ($inline) $buffer.=$protect($children);else {$flush();$out[]=array('children'=>$children);}
        } elseif (!$attribute && isset($part['text'])) {
            if ($inline) $buffer.=$protect(array($part));
            elseif (preg_match('/^\s*$/u',$value) && strpos($value,"\n\n")===false) $buffer.=$value;
            elseif (!preg_match('/^(?:<|\[)/',$value) && $value!=='|') $buffer.=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
            else {$flush();$out[]=$part;}
        } else {
            $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
            $pattern='/(https?:\/\/[^\s<>]+|%%[^%]+%%|%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]|%%|\{\{[^}]+\}\}|\{[a-zA-Z_][a-zA-Z0-9_]*\}|[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}|\b[A-Z][A-Z0-9]*[-_][A-Z0-9-]*\d[A-Z0-9-]*\b)/u';
            $buffer.=preg_replace_callback($pattern,function($m) use ($protect){return $protect(array(array('source'=>$m[0],'text'=>esc_html($m[0]))));},$value);
        }
    }
    $flush();return $out;
}

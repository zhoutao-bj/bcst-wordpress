<?php
/** Translation center and bounded text fragments for inline list translation. */
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
add_action('admin_menu',function () { add_management_page('多语言翻译中心','多语言翻译中心','manage_options','bcst-batch-translation','bcst_translation_center'); });
function bcst_translation_center() {
    if (!current_user_can('manage_options')) return;
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
        array('语言管理','admin.php?page=mlang','新增或维护语言。翻译目标语言自动读取 Polylang 配置。'),
    );
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:1200px;margin-top:20px">';
    foreach ($entries as $entry) {
        echo '<section style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px"><h2 style="margin-top:0"><a href="' . esc_url(admin_url($entry[1])) . '">' . esc_html($entry[0]) . '</a></h2><p>' . esc_html($entry[2]) . '</p><a class="button" href="' . esc_url(admin_url($entry[1])) . '">进入管理</a></section>';
    }
    echo '</div></div>';
}

// Preserve markup verbatim; translate only visible text, in bounded UTF-8 fragments.
function bcst_bt_parts($text) {
    // Only registered shortcode tokens are syntax. Ordinary [sentences]
    // are visible prose; enclosing shortcode bodies still need translation.
    global $shortcode_tags;
    $tags=array_map(function($tag){return preg_quote($tag,'/');},array_keys((array)$shortcode_tags));
    $shortcode=$tags?'\\[\\[?\\/?(?:'.implode('|',$tags).')(?=[\\s\\/\\]])[^\\]\\r\\n]*\\]\\]?':'(?!)';
    $parts = preg_split('/(<!--[\s\S]*?-->|<[^>]*>|'.$shortcode.'|https?:\/\/[^\s<>]+|&(?:#\d+|#x[0-9a-fA-F]+|[a-zA-Z]+);|\r\n|\r|\n|\|)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) throw new Exception('内容不是有效 UTF-8。');
    $out = array(); $raw = false;
    foreach ($parts as $part) {
        if (preg_match('/^<(script|style|code|pre)\b/i',$part)) $raw = true;
        $literal = $raw || preg_match('/^(?:<|https?:\/\/|&)/u',$part) || preg_match('/^(?:'.$shortcode.')$/u',$part) || !preg_match('/\p{L}/u',$part);
        if ($literal) $out[] = array('source'=>$part,'text'=>$part);
        else {
            preg_match_all('/.{1,700}(?:\s+|$)|.{1,700}/us',$part,$chunks);
            foreach ($chunks[0] as $chunk) $out[] = array('source'=>$chunk);
        }
        if (preg_match('/^<\/(script|style|code|pre)\s*>/i',$part)) $raw = false;
    }
    return $out;
}

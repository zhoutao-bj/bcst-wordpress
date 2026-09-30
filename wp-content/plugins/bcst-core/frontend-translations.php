<?php
/** Registry of theme-owned frontend text. Existing translations are never overwritten. */
defined('ABSPATH') || exit;
function bcst_ui_catalog() {
    return array(
        'BCST · 页头导航'=>array('Home','Products','Industries','About','About Us','Insights','Contact Us','Resources','News','Company Logo','Language','Open navigation','Main navigation','Skip to content'),
        'BCST · 页脚'=>array('Follow us','Copyright','All Rights Reserved.','Privacy policy','Social media'),
        'BCST · 搜索与分页'=>array('Search','No items found.','Pagination','Previous','Next','Page','Page not found','Back to home'),
        'BCST · 首页'=>array('Fluid measurement & control','Find the right equipment for your process','Explore our product range and send your operating requirements to discuss a suitable specification.','Tell us about your application','Share the medium, pressure, temperature and connection size with our team.','Latest insights','Browse products'),
        'BCST · 产品与资源'=>array('View details','Product specifications','Related products','Downloads','Frequently asked questions','Product categories','Model','Product information','Technical resources','Video'),
        'BCST · 联系我们'=>array('Contact our sales team','Company Email','Company Telephone','Factory Address'),
        'BCST · 询盘表单'=>array('Request a quotation','Name','Email','Company','Country','Phone / WhatsApp','Requirements','I agree to be contacted about this inquiry.','Send inquiry','Close','Sending…'),
        'BCST · 表单提示'=>array('Thank you. Your inquiry has been saved.','Inquiry received','Unable to submit. Please try again.','Form expired. Please refresh the page and try again.','Unable to submit.','Please complete the required fields.','Please enter a valid email address.','Please agree to be contacted about this inquiry.','Too many submissions. Please try again in ten minutes.','Unable to save. Please try again later.'),
    );
}
add_action('admin_init',function(){
    if (!function_exists('pll_register_string')) return;
    // Run after legacy registrations: regroup the same original strings, preserving translations.
    foreach (bcst_ui_catalog() as $group=>$strings) foreach ($strings as $text) pll_register_string('bcst_ui_'.md5($text),$text,$group,strlen($text)>90);
},100);
function bcst_ui_coverage() {
    echo '<h2>固定界面文案翻译</h2><p>以下文案按位置分组，点击分组进入公共文字列表，可勾选翻译、补齐缺失译文或人工修订。原文为英语；新增语言也需补齐这些文案。公司简介、页面及文章正文仍通过各自入口翻译。</p><p>';
    foreach (bcst_ui_catalog() as $group=>$strings) echo '<a class="button" style="margin:0 8px 8px 0" href="'.esc_url(add_query_arg(array('page'=>'mlang_strings','group'=>$group),admin_url('admin.php'))).'">'.esc_html($group).' ('.count($strings).')</a>';
    echo '</p>';
    if (!function_exists('pll_languages_list') || !class_exists('PLL_MO')) { echo '<p>请先启用 Polylang 才能查看译文覆盖情况。</p>';return; }
    $strings=array_unique(array_merge(...array_values(bcst_ui_catalog())));
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>语言</th><th>固定文案</th><th>缺失译文</th></tr></thead><tbody>';
    foreach (bcst_bailian_languages() as $code=>$language) {
        if ($language['api']==='en') { echo '<tr><td>'.esc_html($language['name']).'</td><td>使用英文原文</td><td>—</td></tr>';continue; }
        try {
            $missing=0;foreach ($strings as $text) if (bcst_tx_string_value($text,$code)==='') $missing++;
            echo '<tr><td>'.esc_html($language['name']).'</td><td>'.count($strings).'</td><td>'.(int)$missing.'</td></tr>';
        } catch (Throwable $e) { echo '<tr><td>'.esc_html($language['name']).'</td><td colspan="2">'.esc_html($e->getMessage()).'</td></tr>'; }
    }
    echo '</tbody></table><p>此统计只读取已保存译文，不调用翻译接口。缺失时前台暂回退原文，不代表已完成翻译；已保存也不代表译文质量已审核。</p>';
}
// Public inquiry responses must use the visitor's language, not the administrator locale.
function bcst_inquiry_text($text) {
    $lang=bcst_input('language');
    if (function_exists('pll_languages_list') && function_exists('pll_translate_string') && in_array($lang,pll_languages_list(array('fields'=>'slug','hide_empty'=>false)),true)) return pll_translate_string($text,$lang);
    return bcst_text($text);
}

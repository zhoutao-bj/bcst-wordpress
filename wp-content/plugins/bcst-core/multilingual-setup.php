<?php
/** Legacy demo initializer retired. Preserve existing content; reject stale forms. */
defined('ABSPATH') || exit;

add_action('admin_post_bcst_multilingual_setup', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', '', array('response'=>403));
    check_admin_referer('bcst_multilingual_setup');
    wp_die(
        '旧版多语言示例初始化已停用，不会创建或修改任何内容。请使用“工具 → 多语言翻译中心”。',
        '多语言初始化已停用',
        array('response'=>410,'back_link'=>true)
    );
});

<?php
defined('ABSPATH') || exit;

add_action('admin_init', function () {
    register_setting('bcst_settings', 'bcst_company_logo', array(
        'type' => 'integer', 'default' => 0,
        'sanitize_callback' => function ($value) {
            $id = is_scalar($value) ? absint($value) : 0;
            if (!$id || (get_post_type($id) === 'attachment' && wp_attachment_is_image($id))) return $id;
            add_settings_error('bcst_company_logo', 'invalid_logo', '公司 Logo 必须选择媒体库中的图片。');
            return absint(get_option('bcst_company_logo', 0));
        },
    ));
});
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'settings_page_bcst-settings' || !current_user_can('manage_options')) return;
    wp_enqueue_media();
    wp_enqueue_script('bcst-company-logo', plugins_url('company-logo.js', __FILE__), array('jquery', 'media-views'), '1.0.0', true);
});
function bcst_company_logo_control() {
    $id = absint(get_option('bcst_company_logo', 0));
    echo '<h2>公司 Logo</h2><input type="hidden" id="bcst-company-logo" name="bcst_company_logo" value="' . esc_attr($id) . '">';
    echo '<div id="bcst-logo-preview" aria-live="polite">';
    if ($id) echo wp_get_attachment_image($id, 'medium', false, array('style' => 'max-width:260px;max-height:100px;width:auto;height:auto;'));
    echo '</div><p><button type="button" class="button" id="bcst-logo-select">上传 / 选择公司 Logo</button> <button type="button" class="button" id="bcst-logo-remove">移除 Logo</button></p>';
    echo '<p class="description">支持上传图片或从媒体库选择，建议使用透明背景 PNG 或 WebP。选择后点击下方“保存更改”生效，页头和页脚共用，各语言共用。未设置时兼容“外观 → 自定义”中的 Logo，否则显示 Logo 占位。移除只取消此设置，不删除媒体文件。</p>';
}

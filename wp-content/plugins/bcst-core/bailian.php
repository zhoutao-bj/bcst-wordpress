<?php
/** Server-side Qwen-MT settings and explicit paid connection test. PHP 7.4. */
defined('ABSPATH') || exit;

function bcst_bailian_models() {
    return array('qwen-mt-flash','qwen-mt-plus','qwen-mt-turbo','qwen-mt-lite');
}
function bcst_bailian_regions() {
    return array('cn-beijing'=>'华北2（北京）','ap-southeast-1'=>'新加坡');
}
function bcst_bailian_config() {
    return wp_parse_args(get_option('bcst_bailian', array()), array('model'=>'qwen-mt-flash','region'=>'cn-beijing','workspace'=>'','secret'=>''));
}
function bcst_bailian_crypto_key() {
    return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
}
function bcst_bailian_encrypt($plain) {
    if (!function_exists('openssl_encrypt')) throw new Exception('PHP 缺少 OpenSSL 扩展，不能安全保存密钥。');
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', bcst_bailian_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new Exception('密钥加密失败，未保存。');
    return base64_encode($iv . $tag . $cipher);
}
function bcst_bailian_key($config) {
    if (empty($config['secret'])) throw new Exception('请先保存 API Key。');
    if (!function_exists('openssl_decrypt')) throw new Exception('PHP 缺少 OpenSSL 扩展。');
    $raw = base64_decode($config['secret'], true);
    if ($raw === false || strlen($raw) <= 28) throw new Exception('密钥记录损坏，请重新填写。');
    $key = openssl_decrypt(substr($raw,28), 'aes-256-gcm', bcst_bailian_crypto_key(), OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16));
    if ($key === false) throw new Exception('密钥无法解密。迁移站点或更换 WordPress 安全盐后，请重新填写 API Key。');
    return $key;
}
function bcst_bailian_endpoint($config) {
    if (!isset(bcst_bailian_regions()[$config['region']]) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]{0,62}\z/', $config['workspace'])) throw new Exception('请选择地域，并填写该地域真实业务空间 ID。');
    return 'https://' . $config['workspace'] . '.' . $config['region'] . '.maas.aliyuncs.com/compatible-mode/v1/chat/completions';
}
function bcst_bailian_translate($text, $source, $target) {
    // Only enable the project's languages explicitly listed by the Qwen-MT docs.
    $languages = array('en'=>'English','es'=>'Spanish','ru'=>'Russian');
    if (!isset($languages[$source], $languages[$target])) throw new Exception('Qwen-MT 官方支持列表未包含蒙古语。本接入暂只支持英、西、俄互译，不会自动改用其他模型。');
    if (!is_string($text) || trim($text) === '' || strlen($text) > 6000) throw new Exception('测试文字不能为空，且最多 6000 字节。长文批量翻译需分段处理。');
    $config = bcst_bailian_config();
    if (!in_array($config['model'], bcst_bailian_models(), true)) throw new Exception('模型配置无效。');
    $url = bcst_bailian_endpoint($config);
    $response = wp_safe_remote_post($url, array(
        'timeout'=>60, 'redirection'=>0, 'limit_response_size'=>131072,
        'headers'=>array('Authorization'=>'Bearer ' . bcst_bailian_key($config),'Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('model'=>$config['model'],'stream'=>false,
            'messages'=>array(array('role'=>'user','content'=>$text)),
            'translation_options'=>array('source_lang'=>$languages[$source],'target_lang'=>$languages[$target])))
    ));
    // Never log headers, credentials, request bodies or raw provider error bodies.
    if (is_wp_error($response)) throw new Exception('请求失败或超时，请检查服务器网络及百炼 API Host。不会自动重试，避免重复计费。');
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        $hints = array(400=>'检查业务空间、模型和请求参数',401=>'检查 API Key 与地域是否匹配',403=>'检查模型权限',404=>'检查业务空间 API Host 和模型',429=>'额度不足或触发限流');
        throw new Exception('百炼返回 HTTP ' . (int)$status . '：' . ($hints[$status] ?? '服务异常，请稍后再试') . '。');
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $choice = $data['choices'][0] ?? array();
    if (($choice['finish_reason'] ?? '') !== 'stop' || !isset($choice['message']['content']) || !is_string($choice['message']['content']) || trim($choice['message']['content']) === '') throw new Exception('译文为空、响应格式错误或输出未完整结束，未写入网站内容。');
    return array('text'=>$choice['message']['content'],'tokens'=>absint($data['usage']['total_tokens'] ?? 0));
}

add_action('admin_menu', function () {
    add_options_page('百炼翻译设置','百炼翻译设置','manage_options','bcst-bailian','bcst_bailian_page');
});
function bcst_bailian_page() {
    if (!current_user_can('manage_options')) return;
    $config = bcst_bailian_config();
    echo '<div class="wrap"><h1>阿里云百炼翻译设置</h1><p>配置仅供服务器调用，不在前台输出密钥。本页先验证 Qwen-MT 接口，尚未接通文章/产品勾选批量翻译。</p><div class="notice notice-warning inline"><p>Qwen-MT 尚未列出蒙古语支持。英、西、俄可以测试；蒙古语方案需要单独确认。本页不会自动切换到通用模型。</p></div>';
    $notice = get_transient('bcst_bailian_notice_' . get_current_user_id());
    if ($notice) { echo '<div class="notice notice-info inline"><p>' . esc_html($notice) . '</p></div>'; delete_transient('bcst_bailian_notice_' . get_current_user_id()); }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bcst_bailian_save">';
    wp_nonce_field('bcst_bailian_save');
    echo '<p><label>调用地域 <select name="region">';
    foreach (bcst_bailian_regions() as $value=>$label) echo '<option value="' . esc_attr($value) . '" ' . selected($config['region'],$value,false) . '>' . esc_html($label) . '</option>';
    echo '</select></label></p><p><label>业务空间 ID（WorkspaceId）<br><input class="regular-text" name="workspace" required value="' . esc_attr($config['workspace']) . '"></label><br>从百炼控制台 API Host 中确认，不是业务空间显示名称，也不是阿里云账号 ID。密钥、空间、地域需一致。</p><p><label>翻译模型 <select name="model">';
    foreach (bcst_bailian_models() as $model) echo '<option ' . selected($config['model'],$model,false) . '>' . esc_html($model) . '</option>';
    echo '</select></label></p><p><label>API Key<br><input type="password" name="api_key" value="" autocomplete="new-password" class="regular-text" maxlength="512"></label><br>' . ($config['secret'] ? '已保存密钥；留空保留，填写新值替换。' : '尚未配置密钥。') . '</p><p><label><input type="checkbox" name="clear_key" value="1">清除已保存的密钥</label></p><p>密钥加密存入数据库，不回显、不提交 Git。加密不等于防住服务器管理员；备份仍需保护。更换 WordPress 安全盐后需要重新填写。</p>';
    submit_button('保存配置'); echo '</form><hr><h2>测试翻译</h2><p>先保存上方配置。点击测试将把下面文字发送给阿里云，可能产生按量费用；只显示结果，不发布或修改任何产品、文章。</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bcst_bailian_test">';
    wp_nonce_field('bcst_bailian_test');
    echo '<p>源语言：英语（en）。目标语言：<select name="target"><option value="es">西班牙语</option><option value="ru">俄语</option></select></p><textarea name="text" rows="5" class="large-text" required maxlength="1500">Pneumatic Control Valve. Model: BCST-100. Please confirm the operating pressure and temperature before quotation.</textarea><p><label><input type="checkbox" name="consent" value="1" required>同意发送此测试文本到阿里云并承担可能的接口费用</label></p>';
    submit_button('发送测试（可能计费）','secondary'); echo '</form></div>';
}
function bcst_bailian_finish($message) {
    set_transient('bcst_bailian_notice_' . get_current_user_id(), $message, 300);
    wp_safe_redirect(admin_url('options-general.php?page=bcst-bailian')); exit;
}
function bcst_bailian_input($name) {
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim(wp_unslash($_POST[$name])) : '';
}
add_action('admin_post_bcst_bailian_save', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden','',array('response'=>403));
    check_admin_referer('bcst_bailian_save');
    try {
        $config = bcst_bailian_config();
        $config['region'] = bcst_bailian_input('region');
        $config['workspace'] = bcst_bailian_input('workspace');
        $config['model'] = bcst_bailian_input('model');
        bcst_bailian_endpoint($config);
        if (!in_array($config['model'],bcst_bailian_models(),true)) throw new Exception('请选择列表中的翻译模型。');
        $key = bcst_bailian_input('api_key');
        if (bcst_bailian_input('clear_key') === '1') $config['secret'] = '';
        elseif ($key !== '') {
            if (strlen($key) > 512 || preg_match('/[\s\x00-\x1f\x7f]/', $key)) throw new Exception('密钥格式无效，请勿包含空格或换行。');
            $config['secret'] = bcst_bailian_encrypt($key);
        }
        update_option('bcst_bailian',$config,false);
        $message = '配置已保存。保存不会调用接口或产生翻译费用。';
    } catch (Throwable $e) { $message = $e->getMessage(); }
    bcst_bailian_finish($message);
});
add_action('admin_post_bcst_bailian_test', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden','',array('response'=>403));
    check_admin_referer('bcst_bailian_test');
    try {
        if (bcst_bailian_input('consent') !== '1') throw new Exception('请先勾选发送及计费确认。');
        $rate = 'bcst_bailian_test_' . get_current_user_id();
        if (get_transient($rate)) throw new Exception('请间隔至少 60 秒再测试，避免重复请求。');
        set_transient($rate,1,60);
        $result = bcst_bailian_translate(bcst_bailian_input('text'),'en',bcst_bailian_input('target'));
        $message = '测试成功。Token 用量：' . $result['tokens'] . '。译文：' . $result['text'];
    } catch (Throwable $e) { $message = $e->getMessage(); }
    bcst_bailian_finish($message);
});

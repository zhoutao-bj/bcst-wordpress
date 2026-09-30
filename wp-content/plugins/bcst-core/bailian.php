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
// Public Polylang API: include languages with no published content yet.
function bcst_bailian_languages() {
    if (!function_exists('pll_languages_list')) return array();
    $slugs = array_values(pll_languages_list(array('fields'=>'slug','hide_empty'=>false)));
    $names = array_values(pll_languages_list(array('fields'=>'name','hide_empty'=>false)));
    $locales = array_values(pll_languages_list(array('fields'=>'locale','hide_empty'=>false)));
    $languages = array();
    foreach ($slugs as $index=>$slug) {
        $locale = strtolower(str_replace('-', '_', $locales[$index] ?? $slug));
        $base = explode('_', $locale)[0];
        // API language codes differ from WordPress regional locales and custom URL slugs.
        $api = $base === 'zh' && preg_match('/(?:tw|hk|hant)/', $locale) ? 'zh_tw' : $base;
        $languages[$slug] = array('name'=>$names[$index] ?? $slug,'api'=>$api,'locale'=>$locale);
    }
    return $languages;
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
    // Mongolian is user-requested; let the provider decide availability, never fake success.
    $languages = bcst_bailian_languages();
    if (!isset($languages[$source], $languages[$target])) throw new Exception('源语言或目标语言已不存在，请在 Polylang 配置语言后重新创建任务。');
    if (!is_string($text) || trim($text) === '' || strlen($text) > 6000) throw new Exception('测试文字不能为空，且最多 6000 字节。长文批量翻译需分段处理。');
    $config = bcst_bailian_config();
    if (!in_array($config['model'], bcst_bailian_models(), true)) throw new Exception('模型配置无效。');
    $url = bcst_bailian_endpoint($config);
    $translation_options = array('source_lang'=>$languages[$source]['api'],'target_lang'=>$languages[$target]['api']);
    if ($languages[$source]['api'] === 'mn' || $languages[$target]['api'] === 'mn') $translation_options['domains'] = 'Industrial valves and instrumentation. Mongolian means modern Khalkha Mongolian written in Cyrillic as used in Mongolia, not Russian or traditional Mongolian script. Preserve model numbers, quantities and units.';
    $response = wp_safe_remote_post($url, array(
        'timeout'=>60, 'redirection'=>0, 'limit_response_size'=>131072,
        'headers'=>array('Authorization'=>'Bearer ' . bcst_bailian_key($config),'Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('model'=>$config['model'],'stream'=>false,
            'messages'=>array(array('role'=>'user','content'=>$text)),
            'translation_options'=>$translation_options))
    ));
    // Never log headers, credentials, request bodies or raw provider error bodies.
    if (is_wp_error($response)) throw new Exception('请求失败或超时，请检查服务器网络及百炼 API Host。不会自动重试，避免重复计费。');
    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        $hints = array(400=>'检查业务空间、模型和请求参数',401=>'检查 API Key 与地域是否匹配',403=>'检查模型权限',404=>'检查业务空间 API Host 和模型',429=>'额度不足或触发限流');
        throw new Exception('百炼返回 HTTP ' . (int)$status . '：' . ($hints[$status] ?? '服务异常，请稍后再试') . '。请确认当前模型接受语言代码 ' . $languages[$source]['api'] . ' → ' . $languages[$target]['api'] . '。');
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $choice = $data['choices'][0] ?? array();
    if (($choice['finish_reason'] ?? '') !== 'stop' || !isset($choice['message']['content']) || !is_string($choice['message']['content']) || trim($choice['message']['content']) === '') throw new Exception('译文为空、响应格式错误或输出未完整结束，未写入网站内容。');
    return array('text'=>$choice['message']['content'],'tokens'=>absint($data['usage']['total_tokens'] ?? 0));
}

function bcst_bailian_settings_url() {
    return admin_url('tools.php?page=bcst-batch-translation#bcst-bailian-settings');
}
// Retain old bookmarks without keeping a second Settings menu entry.
add_action('admin_init', function () {
    if (isset($_GET['page']) && $_GET['page'] === 'bcst-bailian' && current_user_can('manage_options')) {
        wp_safe_redirect(bcst_bailian_settings_url()); exit;
    }
});
function bcst_bailian_status() {
    $raw = get_option('bcst_bailian', array());
    $raw = is_array($raw) ? $raw : array();
    $checks = array();
    $checks['翻译模型'] = !empty($raw['model']) && in_array($raw['model'], bcst_bailian_models(), true) ? '已配置：' . $raw['model'] : '未保存或模型无效';
    $model_ok = !empty($raw['model']) && in_array($raw['model'], bcst_bailian_models(), true);
    $key_ok = false; $host_ok = false;
    try { bcst_bailian_key($raw); $key_ok = true; $checks['API Key'] = '已保存，可解密（不显示密钥）'; }
    catch (Throwable $e) { $checks['API Key'] = $e->getMessage(); }
    try { bcst_bailian_endpoint(bcst_bailian_config()); $host_ok = true; $checks['地域 / 业务空间'] = '格式检查通过'; }
    catch (Throwable $e) { $checks['地域 / 业务空间'] = $e->getMessage(); }
    $ready = $model_ok && $key_ok && $host_ok;
    echo '<div class="notice notice-' . ($ready ? 'success' : 'warning') . ' inline"><p><strong>' . ($ready ? '百炼基础配置检查通过' : '百炼配置未完成或无效，请先完善下方设置') . '</strong></p><ul>';
    foreach ($checks as $label=>$message) echo '<li>' . esc_html($label . '：' . $message) . '</li>';
    echo '</ul><p>这里只检查本地配置，不发送文字、不调用付费接口。配置通过不代表密钥权限、额度、网络或目标语言已验证；请使用下方“测试翻译”确认。</p></div>';
    try { bcst_bt_preflight(); }
    catch (Throwable $e) { if ($ready) echo '<div class="notice notice-warning inline"><p>批量翻译暂不可用：' . esc_html($e->getMessage()) . '</p></div>'; }
}
function bcst_bailian_page() {
    if (!current_user_can('manage_options')) return;
    $config = bcst_bailian_config();
    echo '<section id="bcst-bailian-settings" style="background:#fff;border:1px solid #c3c4c7;padding:20px;margin:20px 0;max-width:1160px"><h2>百炼翻译配置</h2><p>配置仅供服务器调用，不在前台输出密钥。原设置已保留，无需重复填写；保存后可从下方各类内容入口进入翻译任务。</p><div class="notice notice-warning inline"><p>蒙古语已开放调用，使用当前模型并要求西里尔蒙古文；官方未列出支持保证，请先测试。接口拒绝时显示失败，不自动换模型。译文须人工审核。</p></div>';
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
    $languages = bcst_bailian_languages();
    foreach (array('source'=>'源语言','target'=>'目标语言') as $field=>$label) {
        echo '<p><label>' . esc_html($label) . ' <select name="' . esc_attr($field) . '">';
        foreach ($languages as $slug=>$language) echo '<option value="' . esc_attr($slug) . '">' . esc_html($language['name'] . ' (' . $slug . ' / API: ' . $language['api'] . ')') . '</option>';
        echo '</select></label></p>';
    }
    echo '<p>选项自动读取 Polylang；请选择与测试文字一致的源语言。</p><textarea name="text" rows="5" class="large-text" required maxlength="1500">Pneumatic Control Valve. Model: BCST-100. Please confirm the operating pressure and temperature before quotation.</textarea><p><label><input type="checkbox" name="consent" value="1" required>同意发送此测试文本到阿里云并承担可能的接口费用</label></p>';
    submit_button('发送测试（可能计费）','secondary'); echo '</form></section>';
}
function bcst_bailian_finish($message) {
    set_transient('bcst_bailian_notice_' . get_current_user_id(), $message, 300);
    wp_safe_redirect(bcst_bailian_settings_url()); exit;
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
        if (bcst_bailian_input('source') === bcst_bailian_input('target')) throw new Exception('测试请选择不同的源语言和目标语言。');
        $result = bcst_bailian_translate(bcst_bailian_input('text'),bcst_bailian_input('source'),bcst_bailian_input('target'));
        $message = '测试成功。Token 用量：' . $result['tokens'] . '。译文：' . $result['text'];
    } catch (Throwable $e) { $message = $e->getMessage(); }
    bcst_bailian_finish($message);
});

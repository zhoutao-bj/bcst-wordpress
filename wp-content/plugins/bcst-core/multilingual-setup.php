<?php
/** Explicit administrator-run demo seeding; never runs on activation or public requests. */
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_management_page('BCST 多语言初始化', 'BCST 多语言初始化', 'manage_options', 'bcst-multilingual', 'bcst_ml_page');
});

function bcst_ml_check() {
    foreach (array('pll_languages_list','pll_is_translated_post_type','pll_is_translated_taxonomy','pll_get_post_translations','pll_get_term_translations','pll_set_post_language','pll_set_term_language','pll_get_post_language','pll_get_term_language','pll_save_post_translations','pll_save_term_translations') as $fn) {
        if (!function_exists($fn)) throw new Exception('请先启用 Polylang。');
    }
    if (array_diff(array('en','es','ru','mn'), pll_languages_list(array('fields'=>'slug')))) throw new Exception('请先添加 en、es、ru、mn 四种语言。');
    if (!pll_is_translated_post_type('bcst_product') || !pll_is_translated_post_type('post') || !pll_is_translated_taxonomy('bcst_category')) throw new Exception('请在 Polylang 设置中开启产品、产品分类和文章的翻译。');
}

function bcst_ml_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>BCST 多语言示例初始化</h1><p>补齐英语、西班牙语、俄语、西里尔蒙古文。仅处理内置示例，不批量翻译任意已有内容，不覆盖已有译文。</p><p><strong>分类立即可见；新建产品和文章均为草稿。</strong>译文为教学初稿，需审核后发布。不会修改首页、菜单文字、询盘或语言 URL 设置。执行前请备份数据库。</p>';
    $key = 'bcst_ml_result_' . get_current_user_id();
    $result = get_transient($key);
    if ($result) { echo '<div class="notice notice-info"><p>' . esc_html($result) . '</p></div>'; delete_transient($key); }
    try { bcst_ml_check(); } catch (Exception $e) { echo '<p>' . esc_html($e->getMessage()) . '</p></div>'; return; }
    echo '<ul><li>分类：Automatic Valves → Control Valves → Pneumatic Control Valves。</li><li>产品：现有 demo-pneumatic-control-valve 的三种语言示例；保留英语原文。</li><li>文章：新建独立的“教学示例：如何提交产品询价”四语言草稿，不改动现有文章。</li></ul>';
    foreach (array('all'=>'一键初始化全部示例','categories'=>'只初始化产品分类','products'=>'初始化演示产品（自动补齐分类）','articles'=>'只初始化教学文章') as $scope=>$label) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="bcst_multilingual_setup"><input type="hidden" name="scope" value="' . esc_attr($scope) . '">';
        wp_nonce_field('bcst_multilingual_setup'); submit_button($label, 'secondary'); echo '</form>';
    }
    echo '<p>重复执行会跳过已有关联。发生中断时保留已完成数据，可再次执行补齐；不会自动回滚或删除内容。</p></div>';
}

function bcst_ml_categories(&$log) {
    $slugs = array('automatic-valves','control-valves','pneumatic-control-valves');
    $names = array(
        'en'=>array('Automatic Valves','Control Valves','Pneumatic Control Valves'),
        'es'=>array('Válvulas automáticas','Válvulas de control','Válvulas de control neumáticas'),
        'ru'=>array('Автоматические клапаны','Регулирующие клапаны','Пневматические регулирующие клапаны'),
        'mn'=>array('Автомат хавхлагууд','Тохируулах хавхлагууд','Хийн хөдөлгүүртэй тохируулах хавхлагууд')
    );
    $parents = array_fill_keys(array_keys($names), 0);
    foreach ($slugs as $level=>$slug) {
        $source = get_term_by('slug', $slug, 'bcst_category');
        $links = $source ? pll_get_term_translations($source->term_id) : array();
        foreach ($names as $lang=>$labels) {
            $target_slug = $lang === 'en' ? $slug : 'bcst-demo-' . $slug . '-' . $lang;
            $term = !empty($links[$lang]) ? get_term($links[$lang], 'bcst_category') : get_term_by('slug', $target_slug, 'bcst_category');
            if (is_wp_error($term)) throw new Exception($term->get_error_message());
            if (!$term) {
                $created = wp_insert_term($labels[$level], 'bcst_category', array('slug'=>$target_slug,'parent'=>$parents[$lang]));
                if (is_wp_error($created)) throw new Exception($created->get_error_message());
                $term = get_term($created['term_id'], 'bcst_category');
                pll_set_term_language($term->term_id, $lang);
                $log['terms']++;
            }
            $assigned = pll_get_term_language($term->term_id);
            if (($assigned && $assigned !== $lang) || (int)$term->parent !== (int)$parents[$lang]) throw new Exception('分类语言或父级冲突：' . $term->name . '。请手动核对，不会覆盖。');
            pll_set_term_language($term->term_id, $lang);
            $links[$lang] = (int)$term->term_id;
            pll_save_term_translations($links);
            $parents[$lang] = (int)$term->term_id;
        }
    }
    return $parents;
}

function bcst_ml_content() {
    return array(
        'en'=>array('Demo — Pneumatic Control Valve','Training example: How to submit a product inquiry','Training example only. This is not a verified product specification.','Please provide the medium, operating pressure, temperature, connection size and required quantity. Our team must confirm product suitability before quotation.','Material | To be confirmed' . "\n" . 'Pressure rating | To be confirmed'),
        'es'=>array('Demostración — Válvula de control neumática','Ejemplo formativo: Cómo enviar una consulta de producto','Ejemplo solo para formación. No es una especificación de producto verificada.','Indique el fluido, la presión de trabajo, la temperatura, el tamaño de conexión y la cantidad requerida. Nuestro equipo debe confirmar la idoneidad del producto antes de preparar una oferta.','Material | Por confirmar' . "\n" . 'Presión nominal | Por confirmar'),
        'ru'=>array('Демонстрация — Пневматический регулирующий клапан','Учебный пример: Как отправить запрос о продукции','Только учебный пример. Это не подтверждённая техническая характеристика изделия.','Укажите рабочую среду, рабочее давление, температуру, размер присоединения и необходимое количество. Перед подготовкой предложения наша команда должна подтвердить пригодность изделия.','Материал | Требует уточнения' . "\n" . 'Номинальное давление | Требует уточнения'),
        'mn'=>array('Жишээ — Хийн хөдөлгүүртэй тохируулах хавхлага','Сургалтын жишээ: Бүтээгдэхүүний үнийн санал хэрхэн авах вэ','Зөвхөн сургалтын жишээ. Энэ нь баталгаажсан бүтээгдэхүүний техникийн үзүүлэлт биш.','Ажлын орчин, ажлын даралт, температур, холболтын хэмжээ болон шаардлагатай тоо хэмжээг мэдээлнэ үү. Үнийн санал өгөхөөс өмнө манай баг бүтээгдэхүүн тохирох эсэхийг баталгаажуулах шаардлагатай.','Материал | Тодруулах шаардлагатай' . "\n" . 'Нэрлэсэн даралт | Тодруулах шаардлагатай')
    );
}

function bcst_ml_posts($type, $categories, &$log) {
    $slug = $type === 'bcst_product' ? 'demo-pneumatic-control-valve' : 'bcst-training-inquiry-guide';
    $source = get_page_by_path($slug, OBJECT, $type);
    $links = $source ? pll_get_post_translations($source->ID) : array();
    foreach (bcst_ml_content() as $lang=>$text) {
        $target_slug = $lang === 'en' ? $slug : $slug . '-' . $lang;
        $post = !empty($links[$lang]) ? get_post($links[$lang]) : get_page_by_path($target_slug, OBJECT, $type);
        $fresh = false;
        if (!$post) {
            $id = wp_insert_post(wp_slash(array('post_type'=>$type,'post_status'=>'draft','post_name'=>$target_slug,'post_title'=>$text[$type === 'bcst_product' ? 0 : 1],'post_content'=>'<p>' . esc_html($text[2]) . '</p><p>' . esc_html($text[3]) . '</p>','post_excerpt'=>$text[2],'comment_status'=>'closed')), true);
            if (is_wp_error($id)) throw new Exception($id->get_error_message());
            $post = get_post($id); $fresh = true; $log['posts']++;
            pll_set_post_language($id, $lang);
            update_post_meta($id, '_bcst_ml_seed', 'v1');
        }
        $assigned = pll_get_post_language($post->ID);
        if ($post->post_type !== $type || $post->post_status === 'trash' || ($assigned && $assigned !== $lang)) throw new Exception('内容类型、语言或回收站状态冲突：' . $post->post_title . '。请手动核对。');
        pll_set_post_language($post->ID, $lang);
        // Initialize only our newly created records, never replace user-edited metadata.
        if ($fresh && $type === 'bcst_product') {
            $set = wp_set_object_terms($post->ID, array((int)$categories[$lang]), 'bcst_category');
            if (is_wp_error($set)) throw new Exception($set->get_error_message());
            update_post_meta($post->ID, '_bcst_model', 'TRAINING-DEMO');
            update_post_meta($post->ID, '_bcst_specs', $text[4]);
        }
        $links[$lang] = (int)$post->ID;
        pll_save_post_translations($links);
        if (!$fresh) $log['skipped']++;
    }
}

add_action('admin_post_bcst_multilingual_setup', function () {
    if (!current_user_can('manage_options')) wp_die('Forbidden', '', array('response'=>403));
    check_admin_referer('bcst_multilingual_setup');
    $scope = isset($_POST['scope']) && is_string($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';
    if (!in_array($scope, array('all','categories','products','articles'), true)) wp_die('Invalid scope');
    $log = array('terms'=>0,'posts'=>0,'skipped'=>0); $locked = false;
    try {
        bcst_ml_check();
        // Atomic lock: concurrent clicks must not create duplicate records.
        if (!add_option('bcst_ml_lock', time(), '', false)) throw new Exception('另一个初始化任务正在运行。若任务异常中断，请管理员核实后清理 bcst_ml_lock 选项再重试。');
        $locked = true;
        $categories = array();
        if (in_array($scope, array('all','categories','products'), true)) $categories = bcst_ml_categories($log);
        if (in_array($scope, array('all','products'), true)) bcst_ml_posts('bcst_product', $categories, $log);
        if (in_array($scope, array('all','articles'), true)) bcst_ml_posts('post', array(), $log);
        $message = '初始化完成。';
    } catch (Throwable $e) { $message = '初始化停止：' . $e->getMessage() . ' 已完成数据保留。';
    } finally { if ($locked) delete_option('bcst_ml_lock'); }
    $message .= sprintf(' 新建分类 %d 个，新建草稿 %d 篇，保留已有内容 %d 篇。请审核译文后发布。', $log['terms'], $log['posts'], $log['skipped']);
    set_transient('bcst_ml_result_' . get_current_user_id(), $message, 300);
    wp_safe_redirect(admin_url('tools.php?page=bcst-multilingual')); exit;
});

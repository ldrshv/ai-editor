<?php
/**
 * Настройки модуля по умолчанию (общие для сайтов E3).
 * Поля ресурса, их описания и формула автоматических title/description — в коде: src/Services/Fields.php.
 * Настройки конкретного сайта — в core/config/ai-editor.php (пример — ai-editor.example.php).
 * Ключ, IP и пользователь — в системных настройках MODX:
 * ai_editor.api_key, ai_editor.allowed_ips, ai_editor.user.
 */
return [
    'version' => '1.5.1',

    // TV блоков страницы (MIGX). Поля и типы берутся из формы MIGX на сайте.
    'blocks_tv' => 97,
    'blocks_editable' => true,

    // Медиаисточник для файлов (op=file): на E3 — 3 «Файлы и изображения», папка assets/mgr/
    'media_source' => 3,

    // Журнал изменений и предпросмотры
    'storage_path' => MODX_CORE_PATH . 'storage/ai-editor/',
    'preview_ttl' => 86400,
    'max_operations' => 200,

    // Какие классы ресурсов можно менять. msProduct — товары miniShop2: пока только поля страницы и TV,
    // поля самого товара (цена, артикул, опции) не пишутся. Создаются по-прежнему только modDocument.
    'allowed_class_keys' => ['modDocument', 'msProduct'],

    // Настройки сайта — задаются в core/config/ai-editor.php
    'notes' => '',
    'page_types' => [],
    'protected' => [],
];

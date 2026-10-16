<?php
/**
 * Настройки модуля по умолчанию (общие для сайтов E3).
 * Настройки конкретного сайта — в core/config/ai-editor.php.
 * Ключ, IP и пользователь — в системных настройках MODX:
 * ai_editor.api_key, ai_editor.allowed_ips, ai_editor.user.
 */
return [
    'version' => '1.2.0',

    // TV редактора блоков: не отдаётся и не меняется на этом этапе
    'blocks_tv' => 97,

    // Журнал изменений и предпросмотры
    'storage_path' => MODX_CORE_PATH . 'storage/ai-editor/',
    'preview_ttl' => 1800,

    'max_operations' => 50,
    'search_default_limit' => 50,
    'search_max_limit' => 200,
    'content_limit' => 20000,

    // Какие классы ресурсов можно менять и создавать
    'allowed_class_keys' => ['modDocument'],

    // Поля, которые можно менять у существующих ресурсов
    'update_fields' => [
        'pagetitle', 'longtitle', 'description', 'introtext', 'content', 'menutitle',
        'alias', 'published', 'pub_date', 'unpub_date', 'hidemenu', 'menuindex', 'searchable',
    ],

    // Поля, которые можно задать при создании (parent и template — отдельно)
    'create_fields' => [
        'pagetitle', 'longtitle', 'description', 'introtext', 'content', 'menutitle',
        'alias', 'published', 'pub_date', 'unpub_date', 'hidemenu', 'menuindex', 'searchable',
    ],

    // Поля, которые можно выбрать в поиске
    'search_fields' => [
        'id', 'pagetitle', 'longtitle', 'description', 'introtext', 'alias', 'uri', 'parent',
        'template', 'class_key', 'context_key', 'published', 'deleted', 'hidemenu', 'menuindex',
        'pub_date', 'unpub_date', 'createdon', 'createdby', 'editedon', 'editedby', 'publishedon',
    ],

    // Описание полей ресурса для ИИ
    'fields' => [
        'pagetitle' => 'H1 страницы и название в дереве',
        'longtitle' => 'Title страницы',
        'description' => 'Meta description',
        'introtext' => 'Анонс',
        'content' => 'Основной текст (HTML)',
        'menutitle' => 'Название в меню',
        'alias' => 'Часть URL',
        'published' => 'Опубликован (1/0)',
        'pub_date' => 'Дата публикации',
        'unpub_date' => 'Дата снятия с публикации',
        'hidemenu' => 'Скрыть из меню (1/0)',
        'menuindex' => 'Порядок в меню',
        'searchable' => 'Участвует в поиске (1/0)',
    ],

    // Значения по умолчанию для стандартов сайта
    'page_types' => [],
    'limits' => [
        'longtitle' => ['max' => 70],
        'description' => ['max' => 155],
    ],

    // ID ресурсов, которые нельзя менять и в которых нельзя создавать страницы.
    // 0 — запретить создание в корне сайта.
    'protected' => [],
];

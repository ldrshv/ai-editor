<?php
/**
 * Настройки сайта для ai-editor.
 * Скопируйте в core/config/ai-editor.php и поправьте под сайт.
 * Значения заменяют одноимённые ключи из config.php модуля.
 */
return [
    // Чем сайт отличается от прототипа E3: свободный текст для ИИ, отдаётся в get_site_info
    'notes' => '',

    // Типы страниц: шаблон, раздел и обязательные поля
    'page_types' => [
        'service' => [
            'title' => 'Услуга',
            'template' => 5,
            'parent' => 12,
            'required' => ['longtitle', 'description', 'introtext', 'content', 'tv:image'],
        ],
        'news' => [
            'title' => 'Новость',
            'template' => 8,
            'parent' => 30,
            'required' => ['longtitle', 'description', 'introtext', 'content'],
        ],
    ],

    // Ресурсы, которые нельзя менять и в которых нельзя создавать страницы. 0 — корень сайта
    'protected' => [1, 16, 123],

    // Выключить правку блоков TV 97 на этом сайте
    // 'blocks_editable' => false,
];

<?php
/**
 * Стандарты сайта для ai-editor.
 * Скопируйте в core/config/ai-editor.php и поправьте под сайт.
 * Значения заменяют одноимённые ключи из config.php модуля.
 */
return [
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

    // Ограничения длины
    'limits' => [
        'longtitle' => ['max' => 70],
        'description' => ['max' => 155],
    ],

    // Ресурсы, которые нельзя менять и в которых нельзя создавать страницы
    'protected' => [1, 16, 123],
];

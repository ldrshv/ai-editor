<?php

namespace AiEditor\Services;

/**
 * Поля ресурса для ИИ: что можно менять и задавать при создании, что значит каждое поле,
 * лимиты длин и как шаблон E3 собирает title и description (parts/00-head.tpl).
 * Одинаково на всех сайтах E3; при необходимости сайт может переопределить ключи в core/config/ai-editor.php.
 */
final class Fields
{
    // Поля, которые можно менять у существующих ресурсов
    public const UPDATE = [
        'pagetitle', 'longtitle', 'description', 'introtext', 'content', 'menutitle',
        'alias', 'published', 'pub_date', 'unpub_date', 'hidemenu', 'menuindex', 'searchable',
    ];

    // Поля, которые можно задать при создании (parent и template — отдельно)
    public const CREATE = self::UPDATE;

    // Описание полей для ИИ
    public const DESCRIPTIONS = [
        'pagetitle' => 'H1 страницы и название в дереве; входит в автоматический Title',
        'longtitle' => 'Свой Title. Пусто — шаблон выводит автоматический (см. meta.title)',
        'description' => 'Свой meta description. Пусто — шаблон выводит автоматический (см. meta.description)',
        'introtext' => 'Анонс',
        'content' => 'Основной текст (HTML); из него собирается автоматический description',
        'menutitle' => 'Название в меню',
        'alias' => 'Часть URL',
        'published' => 'Опубликован (1/0)',
        'pub_date' => 'Дата публикации',
        'unpub_date' => 'Дата снятия с публикации',
        'hidemenu' => 'Скрыть из меню (1/0)',
        'menuindex' => 'Порядок в меню',
        'searchable' => 'Участвует в поиске (1/0)',
    ];

    // Предупреждения о длине в предпросмотре
    public const LIMITS = [
        'longtitle' => ['max' => 70],
        'description' => ['max' => 155],
    ];

    // Как шаблон E3 собирает title и description, если свои не заданы
    public const META = [
        'title' => 'Если longtitle пуст: «{pagetitle} | Компания {site_name} {meta_city}»',
        'description' => 'Если description пуст: «{первые 100 символов content без тегов} | Компания {site_name} {meta_city} | {pagetitle}»; '
            . 'если пуст и content: «Компания {site_name} {meta_city} | {pagetitle}»',
        'source' => 'site_name и meta_city — TV главной страницы (id 1), meta_city добавляется только если заполнен. Шаблон parts/00-head.tpl',
    ];

    /**
     * Значения по умолчанию для Settings.
     */
    public static function defaults(): array
    {
        return [
            'update_fields' => self::UPDATE,
            'create_fields' => self::CREATE,
            'fields' => self::DESCRIPTIONS,
            'limits' => self::LIMITS,
        ];
    }
}

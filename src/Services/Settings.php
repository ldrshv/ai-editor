<?php

namespace AiEditor\Services;

/**
 * Настройки модуля: config.php модуля + core/config/ai-editor.php сайта.
 */
class Settings
{
    /** @var array|null */
    private static $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            $module = require dirname(__DIR__, 2) . '/config.php';
            $siteFile = MODX_CORE_PATH . 'config/ai-editor.php';
            $site = is_file($siteFile) ? require $siteFile : [];

            self::$data = array_replace($module, is_array($site) ? $site : []);
        }

        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        $data = self::all();

        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public static function protectedIds(): array
    {
        return array_map('intval', (array) self::get('protected', []));
    }

    public static function pageTypes(): array
    {
        return (array) self::get('page_types', []);
    }

    /**
     * Тип страницы по ключу или по шаблону.
     */
    public static function pageType(?string $key = null, ?int $template = null): ?array
    {
        foreach (self::pageTypes() as $typeKey => $type) {
            if ($key !== null && (string) $typeKey === $key) {
                return ['key' => (string) $typeKey] + $type;
            }

            if ($key === null && $template !== null && (int) ($type['template'] ?? 0) === $template) {
                return ['key' => (string) $typeKey] + $type;
            }
        }

        return null;
    }
}

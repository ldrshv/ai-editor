<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use RuntimeException;

/**
 * Хранение предпросмотров и журнала изменений в JSON-файлах.
 */
class Storage
{
    /** @var string */
    private $path;

    public function __construct()
    {
        $this->path = rtrim((string) Settings::get('storage_path'), '/\\') . '/';
    }

    public function save(string $type, string $id, array $data): void
    {
        $file = $this->file($type, $id);
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать папку ' . $dir);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать ' . $file);
        }
    }

    public function load(string $type, string $id): ?array
    {
        $file = $this->file($type, $id);

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function delete(string $type, string $id): void
    {
        $file = $this->file($type, $id);

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * ID записей, от новых к старым.
     */
    public function ids(string $type): array
    {
        $files = glob($this->path . $type . '/*.json') ?: [];
        $ids = array_map(static function (string $file): string {
            return basename($file, '.json');
        }, $files);

        rsort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Удаляет просроченные предпросмотры.
     */
    public function cleanupPreviews(): void
    {
        foreach (glob($this->path . 'previews/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
                @unlink($file);
            }
        }
    }

    public static function newId(string $prefix): string
    {
        return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    }

    private function file(string $type, string $id): string
    {
        if (preg_match('/^[a-z]_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $id) !== 1) {
            throw ApiException::validation('Некорректный идентификатор: ' . $id);
        }

        return $this->path . $type . '/' . $id . '.json';
    }
}

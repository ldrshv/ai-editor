<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;

/**
 * Операция op=file: предпросмотр, запись и откат файла в медиаисточнике.
 * SVG — кодом (content) или по ссылке; JPG, PNG, WebP, GIF — только по ссылке (url).
 * Содержимое в предпросмотре и журнале хранится в base64.
 */
class FileOps
{
    public const EXTENSIONS = ['svg', 'jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** @var modX */
    private $modx;

    /** @var Files */
    private $files;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->files = new Files($modx);
    }

    public function path(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        $site = rtrim((string) $this->modx->getOption('site_url'), '/') . '/';
        $prefix = (string) substr($this->files->url(''), strlen($site));

        if ($prefix !== '' && strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }

        $parts = explode('/', $path);

        if (count($parts) < 3 || $parts[0] !== 'images') {
            throw ApiException::validation('path: файл кладётся в images/<alias страницы>/<имя> (папка /' . $prefix . ')', ['field' => 'path']);
        }

        foreach ($parts as $part) {
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $part) !== 1 || strpos($part, '..') !== false) {
                throw ApiException::validation('path: только строчная латиница, цифры, «-», «_» и «.»', ['field' => 'path']);
            }
        }

        if (!in_array(self::ext($path), self::EXTENSIONS, true)) {
            throw ApiException::validation('path: можно .svg, .jpg, .png, .webp, .gif', ['field' => 'path']);
        }

        return $path;
    }

    /**
     * @return array{0: array, 1: array} внутренняя запись и то, что показать пользователю
     */
    public function prepare(array $op): array
    {
        $path = $this->path((string) ($op['path'] ?? ''));
        $url = trim((string) ($op['url'] ?? ''));
        $svg = (string) ($op['content'] ?? '');
        $warnings = [];

        if (($url === '') === (trim($svg) === '')) {
            throw ApiException::validation('op=file: передайте url (ссылка на картинку) или content (код SVG)', ['field' => 'url']);
        }

        if (self::ext($path) === 'svg') {
            [$content, $removed] = $this->files->sanitizeSvg($url !== '' ? (new ImageFetcher())->fetch($url) : $svg);
            $image = ['format' => 'svg'];

            if ($removed !== []) {
                $warnings[] = ['check' => 'svg_cleaned', 'message' => 'Из SVG удалено: ' . implode(', ', $removed)];
            }
        } else {
            if ($url === '') {
                throw ApiException::validation('op=file: JPG, PNG, WebP и GIF передаются ссылкой url', ['field' => 'url']);
            }

            [$content, $image] = (new ImageProcessor())->process((new ImageFetcher())->fetch($url), self::ext($path));

            if ($image['resized']) {
                $warnings[] = ['check' => 'image_resized', 'message' => 'Уменьшена с ' . $image['source_width'] . '×' . $image['source_height'] . ' до ' . $image['width'] . '×' . $image['height']];
            }

            if ($image['format'] !== $image['source_format']) {
                $warnings[] = ['check' => 'image_converted', 'message' => 'Формат изменён: ' . $image['source_format'] . ' → ' . $image['format']];
            }
        }

        $old = $this->files->read($path);

        if ($old !== null && empty($op['overwrite'])) {
            throw new ApiException('FILE_EXISTS', 'Файл ' . $path . ' уже есть. Чтобы заменить, передайте overwrite: true', 409, ['path' => $path, 'url' => $this->files->url($path)]);
        }

        (new ProcessorRunner($this->modx))->assertCan([$old === null ? 'file_upload' : 'file_update'], [], 'запись файла ' . $path);

        $internal = ['op' => 'file', 'path' => $path, 'content_b64' => base64_encode($content), 'old_b64' => $old === null ? null : base64_encode($old)];
        $shown = ['op' => 'file', 'action' => $old === null ? 'create' : 'replace', 'path' => $path, 'url' => $this->files->url($path), 'size' => strlen($content), 'image' => $image];

        if ($url !== '') {
            $shown['source'] = $url;
        }

        if (self::ext($path) === 'svg') {
            $shown['content'] = $content;
        }

        $shown['warnings'] = $warnings;

        return [$internal, $shown];
    }

    /**
     * @return array{0: array, 1: ?array} результат и запись для журнала
     */
    public function apply(array $internal): array
    {
        $path = (string) $internal['path'];
        $content = (string) base64_decode((string) $internal['content_b64']);
        $old = $internal['old_b64'] === null ? null : (string) base64_decode((string) $internal['old_b64']);
        $now = $this->files->read($path);

        if ($now !== $old) {
            return [['status' => 'error', 'code' => 'CONFLICT', 'message' => 'Файл ' . $path . ' изменили после предпросмотра'], null];
        }

        if ($now === $content) {
            return [['status' => 'skipped', 'url' => $this->files->url($path)], null];
        }

        if (!$this->files->write($path, $content)) {
            return [['status' => 'error', 'code' => 'FILE_WRITE_ERROR', 'message' => 'Не удалось записать ' . $path . ': проверьте права на папку'], null];
        }

        return [['status' => 'ok', 'url' => $this->files->url($path)], ['path' => $path, 'old_b64' => $internal['old_b64'], 'new_sha1' => sha1($content)]];
    }

    public function conflict(array $entry): bool
    {
        $now = $this->files->read((string) $entry['path']);

        return $now === null || sha1($now) !== $entry['new_sha1'];
    }

    public function revert(array $entry): bool
    {
        return $entry['old_b64'] === null
            ? $this->files->delete((string) $entry['path'])
            : $this->files->write((string) $entry['path'], (string) base64_decode((string) $entry['old_b64']));
    }

    public static function ext(string $path): string
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }
}

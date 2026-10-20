<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMText;
use modX;
use Throwable;

/**
 * Файлы в медиаисточнике сайта (на E3 — источник 3, папка assets/mgr/).
 * Пока только SVG-иконки: очистка от скриптов и внешних ссылок, запись, чтение, удаление.
 * В полях MODX путь хранится относительно медиаисточника: images/<alias>/<имя>.svg.
 */
class Files
{
    public const MAX_SIZE = 20480;

    private const ELEMENTS = ['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'title'];

    private const ATTRIBUTES = [
        'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'fill-rule', 'clip-rule', 'opacity', 'fill-opacity', 'stroke-opacity',
        'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'transform',
    ];

    /** @var modX */
    private $modx;

    /** @var string|null */
    private $base;

    /** @var array */
    private $removed = [];

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public static function sourceId(): int
    {
        return (int) Settings::get('media_source', 3);
    }

    /**
     * Путь относительно медиаисточника: images/<alias>/<имя>.svg.
     * Принимает и полный вид: /assets/mgr/images/... или assets/mgr/images/...
     */
    public function normalize(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        $prefix = $this->relativeBase();

        if ($prefix !== '' && strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }

        $parts = explode('/', $path);

        if (count($parts) < 3 || $parts[0] !== 'images') {
            throw ApiException::validation('path: файл кладётся в images/<alias страницы>/<имя>.svg внутри медиаисточника (/' . $prefix . ')', ['field' => 'path']);
        }

        foreach ($parts as $part) {
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $part) !== 1 || strpos($part, '..') !== false) {
                throw ApiException::validation('path: в именах папок и файла только строчные латинские буквы, цифры, «-», «_» и «.»', ['field' => 'path']);
            }
        }

        if (substr($path, -4) !== '.svg') {
            throw ApiException::validation('path: пока можно сохранять только .svg', ['field' => 'path']);
        }

        return $path;
    }

    /**
     * Очищенный SVG и список удалённого: только безопасные элементы и атрибуты геометрии и цвета.
     *
     * @return array{0: string, 1: array}
     */
    public function sanitizeSvg(string $svg): array
    {
        $svg = trim($svg);

        if ($svg === '') {
            throw ApiException::validation('content: пустой SVG', ['field' => 'content']);
        }

        if (strlen($svg) > self::MAX_SIZE) {
            throw ApiException::validation('content: SVG больше 20 КБ', ['field' => 'content']);
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) === 1) {
            throw ApiException::validation('content: DOCTYPE и ENTITY в SVG запрещены', ['field' => 'content']);
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $doc->documentElement : null;

        if (!$root || strtolower($root->localName) !== 'svg') {
            throw ApiException::validation('content: это не SVG', ['field' => 'content']);
        }

        $this->removed = [];
        $this->clean($root);

        if (!$root->hasAttribute('viewBox')) {
            throw ApiException::validation('content: у svg нужен viewBox, например 0 0 24 24', ['field' => 'content']);
        }

        $root->removeAttribute('width');
        $root->removeAttribute('height');

        $out = (string) $doc->saveXML($root);

        if (strpos($out, 'xmlns="http://www.w3.org/2000/svg"') === false) {
            $out = (string) preg_replace('/^<svg\b/', '<svg xmlns="http://www.w3.org/2000/svg"', $out, 1);
        }

        return [$out, array_values(array_unique($this->removed))];
    }

    public function exists(string $path): bool
    {
        return is_file($this->full($path));
    }

    public function read(string $path): ?string
    {
        $full = $this->full($path);

        if (!is_file($full)) {
            return null;
        }

        $content = file_get_contents($full);

        return $content === false ? null : $content;
    }

    public function write(string $path, string $content): bool
    {
        $full = $this->full($path);
        $dir = dirname($full);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($full, $content, LOCK_EX) !== false;
    }

    public function delete(string $path): bool
    {
        $full = $this->full($path);

        return !is_file($full) || unlink($full);
    }

    public function url(string $path): string
    {
        return rtrim((string) $this->modx->getOption('site_url'), '/') . '/' . $this->relativeBase() . $path;
    }

    private function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (!in_array($name, self::ATTRIBUTES, true) || preg_match('/url\s*\(|javascript:|data:|expression\s*\(/i', $value) === 1) {
                $element->removeAttributeNode($attribute);
                $this->removed[] = '@' . $name;
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (!in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $element->removeChild($child);
                    $this->removed[] = '<' . strtolower($child->localName) . '>';
                    continue;
                }

                $this->clean($child);
                continue;
            }

            $keep = $child instanceof DOMText && !($child instanceof DOMCdataSection)
                && (strtolower($element->localName) === 'title' || trim((string) $child->nodeValue) === '');

            if (!$keep) {
                $element->removeChild($child);
            }
        }
    }

    private function full(string $path): string
    {
        return $this->base() . $path;
    }

    /**
     * Абсолютный путь к медиаисточнику со слешем в конце. Только внутри папки сайта.
     */
    private function base(): string
    {
        if ($this->base !== null) {
            return $this->base;
        }

        $base = '';

        try {
            /** @var \modMediaSource|null $source */
            $source = $this->modx->getObject('sources.modMediaSource', self::sourceId());

            if ($source) {
                $source->initialize();
                $base = (string) $source->getBasePath();
            }
        } catch (Throwable $exception) {
            $base = '';
        }

        if ($base === '') {
            throw new ApiException('MEDIA_SOURCE', 'Медиаисточник ' . self::sourceId() . ' не найден или не файловый', 500);
        }

        $root = rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') . '/';
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

        if ($base[0] !== '/') {
            $base = $root . $base;
        }

        if (strpos($base, $root) !== 0) {
            throw new ApiException('MEDIA_SOURCE', 'Медиаисточник ' . self::sourceId() . ' вне папки сайта', 500);
        }

        return $this->base = $base;
    }

    /**
     * Путь медиаисточника от корня сайта, например assets/mgr/.
     */
    private function relativeBase(): string
    {
        $root = rtrim(str_replace('\\', '/', MODX_BASE_PATH), '/') . '/';

        return (string) substr($this->base(), strlen($root));
    }
}

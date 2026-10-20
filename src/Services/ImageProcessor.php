<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;

/**
 * Проверка и пересохранение картинки через GD: тип по содержимому, без метаданных,
 * не больше MAX_SIDE по длинной стороне. Формат результата — по расширению (jpg, png, webp, gif).
 */
class ImageProcessor
{
    public const MAX_SIDE = 2560;
    public const MAX_PIXELS = 40000000;

    /**
     * @return array{0: string, 1: array}
     */
    public function process(string $bytes, string $format): array
    {
        if (!extension_loaded('gd')) {
            throw new ApiException('NO_GD', 'На сервере нет PHP-расширения GD', 500);
        }

        $format = $format === 'jpeg' ? 'jpg' : $format;

        if ($format === 'webp' && !function_exists('imagewebp')) {
            throw new ApiException('NO_WEBP', 'GD на сервере не умеет WebP — сохраните в .jpg или .png', 500);
        }

        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

        if ($info === false || !isset($types[(int) $info[2]])) {
            throw ApiException::validation('url: это не картинка JPG, PNG, WebP или GIF', ['field' => 'url']);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw ApiException::validation('url: слишком большое разрешение картинки', ['field' => 'url']);
        }

        $src = @imagecreatefromstring($bytes);

        if (!$src) {
            throw ApiException::validation('url: не удалось прочитать картинку', ['field' => 'url']);
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $img = imagecreatetruecolor($w, $h);

        if ($format === 'jpg') {
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        } else {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagealphablending($img, true);
        }

        imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, $width, $height);
        imagedestroy($src);
        imagesavealpha($img, true);

        ob_start();

        if ($format === 'jpg') {
            imagejpeg($img, null, 85);
        } elseif ($format === 'png') {
            imagepng($img, null, 9);
        } elseif ($format === 'webp') {
            imagewebp($img, null, 85);
        } else {
            imagegif($img);
        }

        $data = (string) ob_get_clean();
        imagedestroy($img);

        if ($data === '') {
            throw new ApiException('IMAGE_ENCODE', 'Не удалось сохранить картинку', 500);
        }

        return [$data, [
            'format' => $format,
            'source_format' => $types[(int) $info[2]],
            'width' => $w,
            'height' => $h,
            'source_width' => $width,
            'source_height' => $height,
            'resized' => $scale < 1,
        ]];
    }
}

<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;

/**
 * Скачивание файла по ссылке http(s): до 10 МБ, только внешние адреса, перенаправления проверяются.
 */
class ImageFetcher
{
    public const MAX_BYTES = 10485760;

    public function fetch(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('NO_CURL', 'На сервере нет PHP-расширения cURL', 500);
        }

        for ($i = 0; $i < 5; $i++) {
            [$host, $ip, $port] = $this->check($url);
            $body = '';
            $tooBig = false;
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ai-editor)',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooBig): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $tooBig = true;

                        return 0;
                    }

                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);

            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($tooBig) {
                throw ApiException::validation('url: файл больше 10 МБ', ['field' => 'url']);
            }

            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $location;
                continue;
            }

            if ($status !== 200 || $body === '') {
                throw new ApiException('DOWNLOAD_FAILED', 'Не удалось скачать файл (HTTP ' . $status . ')', 422, ['url' => $url]);
            }

            return $body;
        }

        throw new ApiException('DOWNLOAD_FAILED', 'Слишком много перенаправлений', 422, ['url' => $url]);
    }

    private function check(string $url): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'])) {
            throw ApiException::validation('url: нужна ссылка http или https', ['field' => 'url']);
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw ApiException::validation('url: не найден сервер ' . $host, ['field' => 'url']);
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ApiException::validation('url: внутренние адреса запрещены', ['field' => 'url']);
            }
        }

        return [$host, $ips[0], $port];
    }
}

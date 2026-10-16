<?php

namespace AiEditor\Controllers;

use AiEditor\Exceptions\ApiException;
use Throwable;

/**
 * Базовый контроллер: проверка доступа, разбор JSON, единый формат ответов.
 */
abstract class Controller extends \Zoomx\Controllers\Controller
{
    /** @var array|null */
    private $body = null;

    /**
     * Выполняет действие с проверкой доступа и обработкой ошибок.
     */
    protected function handle(callable $action)
    {
        zoomx()->autoloadResource(false);

        try {
            $this->authorize();
            $result = $action();

            $data = is_array($result) && array_key_exists('data', $result)
                ? $result
                : ['data' => $result];

            return $this->json(['ok' => true] + $data);
        } catch (ApiException $exception) {
            return $this->json([
                'ok' => false,
                'error' => array_filter([
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'details' => $exception->details() ?: null,
                ], static function ($value) {
                    return $value !== null;
                }),
            ], $exception->status());
        } catch (Throwable $exception) {
            $this->modx->log(
                \modX::LOG_LEVEL_ERROR,
                '[ai-editor] ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine()
            );

            return $this->json([
                'ok' => false,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Внутренняя ошибка, подробности в журнале ошибок MODX',
                ],
            ], 500);
        }
    }

    /**
     * Тело запроса JSON.
     */
    protected function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $raw = (string) file_get_contents('php://input');

        if (trim($raw) === '') {
            return $this->body = [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw ApiException::validation('Тело запроса должно быть JSON-объектом');
        }

        return $this->body = $data;
    }

    protected function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    private function authorize(): void
    {
        $expected = trim((string) $this->modx->getOption('ai_editor.api_key', null, ''));

        if ($expected === '') {
            throw new ApiException('UNAUTHORIZED', 'Ключ ai_editor.api_key не настроен', 401);
        }

        $token = $this->bearerToken();

        if ($token === null || !hash_equals($expected, $token)) {
            throw new ApiException('UNAUTHORIZED', 'Неверный ключ доступа', 401);
        }

        $allowed = array_filter(array_map(
            'trim',
            explode(',', (string) $this->modx->getOption('ai_editor.allowed_ips', null, ''))
        ));

        if ($allowed !== [] && !in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $allowed, true)) {
            throw new ApiException('FORBIDDEN_IP', 'Доступ с этого IP запрещён', 403);
        }
    }

    private function bearerToken(): ?string
    {
        $header = '';

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                $header = (string) $_SERVER[$key];
                break;
            }
        }

        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function json(array $data, int $status = 200)
    {
        $headers = [];

        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            $headers['X-Request-Id'] = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string) $_SERVER['HTTP_X_REQUEST_ID']), 0, 64);
        }

        return jsonx($data, $headers, $status);
    }
}

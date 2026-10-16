<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;

/**
 * Запуск процессоров MODX от имени пользователя ai_editor.user в контексте mgr.
 */
class ProcessorRunner
{
    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * @return array{ok: bool, message: string, errors: array, object: array}
     */
    public function run(string $action, array $data): array
    {
        $username = trim((string) $this->modx->getOption('ai_editor.user', null, ''));

        if ($username === '') {
            throw new ApiException('CONFIG_ERROR', 'Не настроен пользователь ai_editor.user', 500);
        }

        /** @var \modUser|null $user */
        $user = $this->modx->getObject('modUser', ['username' => $username, 'active' => 1]);

        if (!$user) {
            throw new ApiException('CONFIG_ERROR', 'Пользователь ' . $username . ' не найден или не активен', 500);
        }

        $previousUser = $this->modx->user;
        $previousContext = $this->modx->context ? $this->modx->context->get('key') : 'web';

        $this->modx->user = $user;
        $this->modx->switchContext('mgr');

        try {
            $response = $this->modx->runProcessor($action, $data);
        } finally {
            $this->modx->user = $previousUser;
            $this->modx->switchContext($previousContext);
        }

        if (!$response) {
            return ['ok' => false, 'message' => 'Процессор ' . $action . ' не найден', 'errors' => [], 'object' => []];
        }

        $errors = [];

        foreach ((array) $response->getFieldErrors() as $error) {
            $errors[] = ['field' => (string) $error->field, 'message' => (string) $error->message];
        }

        return [
            'ok' => !$response->isError(),
            'message' => (string) $response->getMessage(),
            'errors' => $errors,
            'object' => (array) $response->getObject(),
        ];
    }
}

<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;

/**
 * Процессоры и проверка прав MODX от имени пользователя ai_editor.user в контексте mgr.
 */
class ProcessorRunner
{
    /** Права, которые показывает диагностика в /ping */
    public const DIAGNOSTIC_PERMISSIONS = [
        'new_document',
        'new_document_in_root',
        'save_document',
        'publish_document',
        'unpublish_document',
        'delete_document',
    ];

    /** @var modX */
    private $modx;

    /** @var \modUser|null */
    private $user;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Пользователь из системной настройки ai_editor.user.
     */
    public function user(): \modUser
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $username = trim((string) $this->modx->getOption('ai_editor.user', null, ''));

        if ($username === '') {
            throw new ApiException('CONFIG_ERROR', 'Не настроен пользователь ai_editor.user', 500);
        }

        /** @var \modUser|null $user */
        $user = $this->modx->getObject('modUser', ['username' => $username, 'active' => 1]);

        if (!$user) {
            throw new ApiException('CONFIG_ERROR', 'Пользователь ' . $username . ' не найден или не активен', 500);
        }

        return $this->user = $user;
    }

    public function username(): string
    {
        return (string) $this->user()->get('username');
    }

    /**
     * Выполнить $fn от имени ai_editor.user в контексте mgr.
     * Пользователь и контекст запроса возвращаются в любом случае.
     *
     * @return mixed результат $fn
     */
    public function asUser(callable $fn)
    {
        $user = $this->user();
        $previousUser = $this->modx->user;
        $previousContext = $this->modx->context ? (string) $this->modx->context->get('key') : 'web';

        if ($previousContext !== 'mgr') {
            $this->modx->switchContext('mgr');
        }

        // switchContext() может заново загрузить пользователя сессии (анонима),
        // поэтому ai_editor.user подставляем уже после переключения контекста
        $this->modx->user = $user;

        try {
            $context = $this->modx->context ? (string) $this->modx->context->get('key') : '';

            // Не в mgr процессор проверит права в контексте сайта и откажет
            if ($context !== 'mgr') {
                throw new ApiException(
                    'CONTEXT_ERROR',
                    'Не удалось переключиться в контекст mgr, текущий: ' . ($context !== '' ? $context : 'нет'),
                    500,
                    ['user' => (string) $user->get('username'), 'context' => $context]
                );
            }

            $current = $this->modx->user;

            if (!$current || (int) $current->get('id') !== (int) $user->get('id')) {
                throw new ApiException(
                    'USER_ERROR',
                    'Не удалось работать от пользователя ' . $user->get('username')
                        . ', текущий: ' . ($current ? (string) $current->get('username') : 'нет'),
                    500,
                    ['user' => (string) $user->get('username'), 'current_id' => $current ? (int) $current->get('id') : 0]
                );
            }

            $this->loadLexicon();

            return $fn($this->modx, $user);
        } finally {
            // Сначала контекст, потом пользователь: switchContext() может снова его заменить
            if (!$this->modx->context || (string) $this->modx->context->get('key') !== $previousContext) {
                $this->modx->switchContext($previousContext);
            }

            $this->modx->user = $previousUser;
        }
    }

    /**
     * @return array{ok: bool, message: string, errors: array, object: array}
     */
    public function run(string $action, array $data): array
    {
        return $this->asUser(function (modX $modx) use ($action, $data): array {
            $response = $modx->runProcessor($action, $data);

            if (!$response) {
                return ['ok' => false, 'message' => 'Процессор ' . $action . ' не найден', 'errors' => [], 'object' => []];
            }

            $errors = [];

            foreach ((array) $response->getFieldErrors() as $error) {
                $errors[] = ['field' => (string) $error->field, 'message' => $this->translate((string) $error->message)];
            }

            return [
                'ok' => !$response->isError(),
                'message' => $this->translate((string) $response->getMessage()),
                'errors' => $errors,
                'object' => (array) $response->getObject(),
            ];
        });
    }

    /**
     * Каких прав не хватает пользователю ai_editor.user.
     *
     * @param string[] $permissions права контекста mgr: new_document, save_document...
     * @param array<int, string[]> $policies ID ресурса => политики ресурса: add_children, save...
     * @return string[] например ['new_document', 'add_children:1']
     */
    public function missing(array $permissions, array $policies = []): array
    {
        return $this->asUser(static function (modX $modx) use ($permissions, $policies): array {
            $missing = [];

            foreach (array_unique($permissions) as $permission) {
                if (!$modx->hasPermission((string) $permission)) {
                    $missing[] = (string) $permission;
                }
            }

            foreach ($policies as $id => $list) {
                /** @var \modResource|null $resource */
                $resource = $modx->getObject('modResource', (int) $id);

                if (!$resource) {
                    continue;
                }

                foreach (array_unique((array) $list) as $policy) {
                    if (!$resource->checkPolicy((string) $policy)) {
                        $missing[] = $policy . ':' . (int) $id;
                    }
                }
            }

            return $missing;
        });
    }

    /**
     * Ошибка PERMISSION_DENIED, если каких-то прав нет.
     */
    public function assertCan(array $permissions, array $policies = [], string $action = ''): void
    {
        $missing = $this->missing($permissions, $policies);

        if ($missing !== []) {
            throw new ApiException(
                'PERMISSION_DENIED',
                'У пользователя ' . $this->username() . ' в контексте mgr нет прав'
                    . ($action !== '' ? ' на ' . $action : '') . ': ' . implode(', ', $missing),
                403,
                ['user' => $this->username(), 'missing' => $missing]
            );
        }
    }

    /**
     * Сводка для /ping: от кого пишем, от кого реально шла проверка и какие права есть в mgr.
     */
    public function diagnostics(): array
    {
        try {
            $user = $this->user();
            $checked = $this->asUser(static function (modX $modx): array {
                $permissions = [];

                foreach (self::DIAGNOSTIC_PERMISSIONS as $permission) {
                    $permissions[$permission] = (bool) $modx->hasPermission($permission);
                }

                return [
                    'permissions' => $permissions,
                    'checked_as' => [
                        'id' => $modx->user ? (int) $modx->user->get('id') : 0,
                        'username' => $modx->user ? (string) $modx->user->get('username') : '',
                        'sudo' => $modx->user ? (bool) $modx->user->get('sudo') : false,
                    ],
                    'context' => $modx->context ? (string) $modx->context->get('key') : '',
                    'session_state' => (int) $modx->getSessionState(),
                ];
            });

            return [
                'username' => (string) $user->get('username'),
                'id' => (int) $user->get('id'),
                'sudo' => (bool) $user->get('sudo'),
                'context' => $checked['context'],
                'checked_as' => $checked['checked_as'],
                'session_state' => $checked['session_state'],
                'permissions' => $checked['permissions'],
                'missing' => array_keys(array_filter($checked['permissions'], static function (bool $allowed): bool {
                    return !$allowed;
                })),
            ];
        } catch (ApiException $exception) {
            return ['error' => ['code' => $exception->errorCode(), 'message' => $exception->getMessage()]];
        }
    }

    private function loadLexicon(): void
    {
        if (!$this->modx->lexicon) {
            $this->modx->getService('lexicon', 'modLexicon');
        }

        if ($this->modx->lexicon) {
            $this->modx->lexicon->load('core:default', 'core:resource');
        }
    }

    /**
     * Без загруженного лексикона процессор отдаёт ключ, например permission_denied_processor.
     */
    private function translate(string $message): string
    {
        if ($message !== '' && $this->modx->lexicon && $this->modx->lexicon->exists($message)) {
            return (string) $this->modx->lexicon($message);
        }

        return $message;
    }
}

<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;
use Throwable;

/**
 * Изменения ресурсов: предпросмотр, применение, журнал, откат.
 */
class ChangeService
{
    private const DISPLAY_LIMIT = 3000;
    private const DATE_FIELDS = ['pub_date', 'unpub_date', 'publishedon'];

    /** @var modX */
    private $modx;

    /** @var Storage */
    private $storage;

    /** @var ResourceReader */
    private $reader;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->storage = new Storage();
        $this->reader = new ResourceReader($modx);
    }

    /* ---------------------------------------------------------------------
     * Предпросмотр
     * ------------------------------------------------------------------- */

    public function preview(array $payload): array
    {
        $this->storage->cleanupPreviews();

        $operations = $payload['operations'] ?? null;
        $max = (int) Settings::get('max_operations', 50);

        if (!is_array($operations) || $operations === []) {
            throw ApiException::validation('Передайте operations — непустой список операций');
        }

        if (count($operations) > $max) {
            throw ApiException::validation('Не больше ' . $max . ' операций за раз');
        }

        $prepared = [];
        $display = [];
        $errors = [];

        // ref создаваемых страниц => context_key, чтобы следующие операции могли ссылаться на них в parent
        $refs = [];

        foreach (array_values($operations) as $index => $operation) {
            try {
                if (!is_array($operation)) {
                    throw ApiException::validation('Операция должна быть объектом');
                }

                $op = (string) ($operation['op'] ?? '');

                if ($op === 'update') {
                    [$internal, $shown] = $this->prepareUpdate($index, $operation);
                } elseif ($op === 'create') {
                    [$internal, $shown] = $this->prepareCreate($index, $operation, $refs);
                    $refs[$internal['ref']] = $internal['context_key'];
                } else {
                    throw ApiException::validation('op должен быть update или create');
                }

                $prepared[] = $internal;
                $display[] = $shown;
            } catch (ApiException $exception) {
                $errors[] = array_filter([
                    'index' => $index,
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'details' => $exception->details() ?: null,
                ], static function ($value) {
                    return $value !== null;
                });
            }
        }

        if ($errors !== []) {
            return ['preview_id' => null, 'operations' => $display, 'errors' => $errors];
        }

        $id = Storage::newId('p');
        $expires = time() + (int) Settings::get('preview_ttl', 1800);

        $this->storage->save('previews', $id, [
            'id' => $id,
            'created' => time(),
            'expires' => $expires,
            'comment' => mb_substr(trim((string) ($payload['comment'] ?? '')), 0, 500, 'UTF-8'),
            'actor' => mb_substr(trim((string) ($payload['actor'] ?? 'mcp')), 0, 100, 'UTF-8'),
            'operations' => $prepared,
        ]);

        return [
            'preview_id' => $id,
            'expires_at' => date('c', $expires),
            'operations' => $display,
            'errors' => [],
        ];
    }

    private function prepareUpdate(int $index, array $operation): array
    {
        $id = (int) ($operation['id'] ?? 0);

        if ($id <= 0) {
            throw ApiException::validation('Не указан id ресурса');
        }

        /** @var \modResource|null $resource */
        $resource = $this->modx->getObject('modResource', $id);

        if (!$resource) {
            throw ApiException::notFound('Ресурс ' . $id . ' не найден');
        }

        $this->assertEditable($resource);

        $fieldsInput = $this->arrayParam($operation, 'fields');
        $tvsInput = $this->arrayParam($operation, 'tvs');

        if ($fieldsInput === [] && $tvsInput === []) {
            throw ApiException::validation('Передайте fields или tvs');
        }

        $this->assertAllowedFields(array_keys($fieldsInput), (array) Settings::get('update_fields', []));

        $fields = [];
        $final = $resource->toArray();

        foreach ($fieldsInput as $field => $value) {
            $value = Format::input($field, $value);

            if ($field === 'alias' && $value !== '') {
                $value = (string) $resource->cleanAlias($value);
                $this->assertAliasFree($value, (int) $resource->get('parent'), (string) $resource->get('context_key'), $id);
            }

            $old = $this->fieldValue($resource, $field);

            if (!Format::same($old, $value)) {
                $fields[$field] = ['old' => $old, 'new' => $value];
            }

            $final[$field] = $value;
        }

        $templateId = (int) $resource->get('template');
        $currentTvs = $this->reader->tvValues($id, $templateId);
        $tvs = [];
        $finalTvs = array_map(static function (array $tv) {
            return $tv['value'];
        }, $currentTvs);

        foreach ($tvsInput as $name => $value) {
            $tv = $this->assertTv((string) $name, $currentTvs);
            $value = $this->tvInput($name, $value);

            if (!Format::same($tv['value'], $value)) {
                $tvs[$name] = ['id' => $tv['id'], 'old' => $tv['value'], 'new' => $value];
            }

            $finalTvs[$name] = $value;
        }

        $warnings = [];

        if ($fields === [] && $tvs === []) {
            $warnings[] = ['check' => 'no_changes', 'message' => 'Значения совпадают с текущими, изменений нет'];
        }

        $warnings = array_merge(
            $warnings,
            $this->standardWarnings($templateId, $final, $finalTvs, array_keys($fields))
        );

        $internal = [
            'op' => 'update',
            'id' => $id,
            'editedon' => Format::timestamp($resource->get('editedon')),
            'fields' => $fields,
            'tvs' => $tvs,
        ];

        $changes = [];

        foreach ($fields as $field => $change) {
            $changes[] = $this->displayChange($field, $change['old'], $change['new']);
        }

        foreach ($tvs as $name => $change) {
            $changes[] = $this->displayChange('tv:' . $name, $change['old'], $change['new']);
        }

        $shown = [
            'index' => $index,
            'op' => 'update',
            'id' => $id,
            'pagetitle' => (string) $resource->get('pagetitle'),
            'uri' => (string) $resource->get('uri'),
            'changes' => $changes,
            'warnings' => $warnings,
        ];

        return [$internal, $shown];
    }

    /**
     * @param array $refs ref страниц, созданных раньше в этом предпросмотре => context_key
     */
    private function prepareCreate(int $index, array $operation, array $refs = []): array
    {
        $pageType = null;

        if (!empty($operation['page_type'])) {
            $pageType = Settings::pageType((string) $operation['page_type']);

            if ($pageType === null) {
                throw ApiException::validation('Неизвестный page_type: ' . $operation['page_type']);
            }
        }

        $ref = trim((string) ($operation['ref'] ?? '')) ?: 'new' . ($index + 1);

        if (ctype_digit($ref)) {
            throw ApiException::validation('ref не может быть числом, например new1', ['field' => 'ref']);
        }

        if (isset($refs[$ref])) {
            throw ApiException::validation('ref ' . $ref . ' уже используется в этом предпросмотре', ['field' => 'ref']);
        }

        // parent: ID раздела, 0 — корень контекста, строка — ref страницы из этого же предпросмотра
        $parentRaw = array_key_exists('parent', $operation) ? $operation['parent'] : ($pageType['parent'] ?? null);
        $parentRef = null;
        $parentId = 0;

        if ($parentRaw === null || $parentRaw === '' || is_bool($parentRaw) || is_array($parentRaw)) {
            throw ApiException::validation(
                'Не указан parent: ID раздела, 0 — корень сайта, или ref страницы, создаваемой раньше в этом предпросмотре',
                ['field' => 'parent']
            );
        }

        if (is_string($parentRaw) && !ctype_digit(trim($parentRaw))) {
            $parentRef = trim($parentRaw);

            if (!isset($refs[$parentRef])) {
                throw ApiException::validation(
                    'parent ' . $parentRef . ': нет такой ref среди предыдущих операций create этого предпросмотра',
                    ['field' => 'parent', 'refs' => array_keys($refs)]
                );
            }

            $context = (string) $refs[$parentRef];
        } else {
            $parentId = (int) $parentRaw;

            if ($parentId < 0) {
                throw ApiException::validation('parent не может быть отрицательным', ['field' => 'parent']);
            }

            if (in_array($parentId, Settings::protectedIds(), true)) {
                throw new ApiException(
                    'PROTECTED_RESOURCE',
                    $parentId === 0 ? 'В корне сайта нельзя создавать страницы' : 'В ресурсе ' . $parentId . ' нельзя создавать страницы',
                    403
                );
            }

            if ($parentId === 0) {
                $context = trim((string) ($operation['context'] ?? '')) ?: 'web';

                if (!$this->modx->getCount('modContext', ['key' => $context])) {
                    throw ApiException::validation('Контекст ' . $context . ' не найден', ['field' => 'context']);
                }
            } else {
                /** @var \modResource|null $parent */
                $parent = $this->modx->getObject('modResource', $parentId);

                if (!$parent) {
                    throw ApiException::notFound('Родитель ' . $parentId . ' не найден');
                }

                $context = (string) $parent->get('context_key');
            }
        }

        $templateId = (int) ($operation['template'] ?? ($pageType['template'] ?? $this->modx->getOption('default_template')));

        if ($templateId <= 0 || !$this->modx->getCount('modTemplate', ['id' => $templateId])) {
            throw ApiException::validation('Шаблон ' . $templateId . ' не найден');
        }

        $fieldsInput = $this->arrayParam($operation, 'fields');
        $tvsInput = $this->arrayParam($operation, 'tvs');

        $this->assertAllowedFields(array_keys($fieldsInput), (array) Settings::get('create_fields', []));

        $fields = [];

        foreach ($fieldsInput as $field => $value) {
            $fields[$field] = Format::input($field, $value);
        }

        if (trim((string) ($fields['pagetitle'] ?? '')) === '') {
            throw ApiException::validation('Не заполнен pagetitle', ['field' => 'pagetitle']);
        }

        if (!array_key_exists('published', $fields)) {
            $fields['published'] = 0;
        }

        /** @var \modResource $draft */
        $draft = $this->modx->newObject('modResource');
        $aliasSource = (string) ($fields['alias'] ?? '') !== '' ? (string) $fields['alias'] : (string) $fields['pagetitle'];
        $fields['alias'] = (string) $draft->cleanAlias($aliasSource);

        // У родителя, который ещё не создан, детей нет — проверять alias в базе не нужно
        if ($parentRef === null) {
            $this->assertAliasFree($fields['alias'], $parentId, $context, 0);
        }

        $templateTvs = $this->reader->tvValues(0, $templateId);
        $tvs = [];
        $finalTvs = array_map(static function (array $tv) {
            return $tv['value'];
        }, $templateTvs);

        foreach ($tvsInput as $name => $value) {
            $tv = $this->assertTv((string) $name, $templateTvs);
            $value = $this->tvInput($name, $value);
            $tvs[$name] = ['id' => $tv['id'], 'value' => $value];
            $finalTvs[$name] = $value;
        }

        $internal = [
            'op' => 'create',
            'ref' => $ref,
            'parent' => $parentId,
            'parent_ref' => $parentRef,
            'template' => $templateId,
            'context_key' => $context,
            'fields' => $fields,
            'tvs' => $tvs,
        ];

        $shownFields = [];

        foreach ($fields as $field => $value) {
            $shownFields[$field] = $this->displayValue($field, $value);
        }

        $shownTvs = [];

        foreach ($tvs as $name => $tv) {
            $shownTvs[$name] = $this->displayValue('tv:' . $name, $tv['value']);
        }

        if ($parentRef !== null) {
            $shownParent = ['ref' => $parentRef, 'pagetitle' => 'Создаётся в этом предпросмотре'];
        } elseif ($parentId === 0) {
            $shownParent = ['id' => 0, 'pagetitle' => 'Корень сайта', 'context_key' => $context];
        } else {
            $shownParent = $this->reader->shortResource($parentId);
        }

        $shown = [
            'index' => $index,
            'op' => 'create',
            'ref' => $ref,
            'parent' => $shownParent,
            'template' => $this->reader->shortTemplate($templateId),
            'alias' => $fields['alias'],
            'published' => (bool) $fields['published'],
            'fields' => $shownFields,
            'tvs' => $shownTvs,
            'warnings' => $this->standardWarnings($templateId, $fields, $finalTvs, array_keys($fields)),
        ];

        return [$internal, $shown];
    }

    /* ---------------------------------------------------------------------
     * Применение
     * ------------------------------------------------------------------- */

    public function apply(string $previewId): array
    {
        if ($previewId === '') {
            throw ApiException::validation('Не указан preview_id');
        }

        $preview = $this->storage->load('previews', $previewId);

        if ($preview === null || (int) ($preview['expires'] ?? 0) < time()) {
            $this->storage->delete('previews', $previewId);

            throw new ApiException('PREVIEW_EXPIRED', 'Предпросмотр не найден или устарел, сделайте новый', 410);
        }

        // Предпросмотр одноразовый
        $this->storage->delete('previews', $previewId);

        $changeId = Storage::newId('c');
        $results = [];
        $journal = [];

        // ref => ID созданных в этом применении страниц
        $created = [];

        foreach ((array) $preview['operations'] as $index => $operation) {
            try {
                if ($operation['op'] === 'update') {
                    [$result, $entry] = $this->applyUpdate($operation);
                } else {
                    if (!empty($operation['parent_ref'])) {
                        $parentId = (int) ($created[$operation['parent_ref']] ?? 0);

                        if ($parentId <= 0) {
                            throw new ApiException(
                                'PARENT_NOT_CREATED',
                                'Родительская страница ' . $operation['parent_ref'] . ' не создана, эта страница пропущена',
                                409
                            );
                        }

                        $operation['parent'] = $parentId;
                    }

                    [$result, $entry] = $this->applyCreate($operation);

                    if ($entry !== null) {
                        $created[(string) $operation['ref']] = (int) $entry['id'];
                    }
                }

                if ($entry !== null) {
                    $journal[] = $entry;
                }
            } catch (ApiException $exception) {
                $result = ['status' => 'error', 'code' => $exception->errorCode(), 'message' => $exception->getMessage()];
            } catch (Throwable $exception) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[ai-editor] apply: ' . $exception->getMessage());
                $result = ['status' => 'error', 'code' => 'INTERNAL_ERROR', 'message' => 'Ошибка применения, подробности в журнале MODX'];
            }

            $results[] = ['index' => $index, 'op' => $operation['op']]
                + (isset($operation['id']) ? ['id' => $operation['id']] : [])
                + (isset($operation['ref']) ? ['ref' => $operation['ref']] : [])
                + $result;
        }

        if ($journal !== []) {
            $resources = [];

            foreach ($journal as $entry) {
                $resources[] = (int) $entry['id'];
            }

            $this->storage->save('changes', $changeId, [
                'change_id' => $changeId,
                'created' => time(),
                'preview_id' => $previewId,
                'actor' => (string) ($preview['actor'] ?? ''),
                'comment' => (string) ($preview['comment'] ?? ''),
                'resources' => array_values(array_unique($resources)),
                'operations' => $journal,
                'rolled_back' => false,
            ]);
        }

        return [
            'change_id' => $journal !== [] ? $changeId : null,
            'results' => $results,
        ];
    }

    private function applyUpdate(array $operation): array
    {
        $id = (int) $operation['id'];
        /** @var \modResource|null $resource */
        $resource = $this->modx->getObject('modResource', $id);

        if (!$resource) {
            return [['status' => 'error', 'code' => 'NOT_FOUND', 'message' => 'Ресурс не найден'], null];
        }

        if (Format::timestamp($resource->get('editedon')) !== (int) $operation['editedon']) {
            return [[
                'status' => 'error',
                'code' => 'CONFLICT',
                'message' => 'Ресурс изменили после предпросмотра, сделайте новый предпросмотр',
            ], null];
        }

        $fields = [];

        foreach ((array) $operation['fields'] as $field => $change) {
            $fields[$field] = $change['new'];
        }

        $tvs = [];

        foreach ((array) $operation['tvs'] as $change) {
            $tvs[(int) $change['id']] = $change['new'];
        }

        if ($fields === [] && $tvs === []) {
            return [['status' => 'skipped', 'message' => 'Изменений нет'], null];
        }

        $response = (new ProcessorRunner($this->modx))->run('resource/update', $this->updateData($resource, $fields, $tvs));

        if (!$response['ok']) {
            return [$this->processorError($response), null];
        }

        return [
            ['status' => 'ok'],
            ['op' => 'update', 'id' => $id, 'fields' => $operation['fields'], 'tvs' => $operation['tvs']],
        ];
    }

    private function applyCreate(array $operation): array
    {
        $data = $operation['fields'];
        $data['parent'] = (int) $operation['parent'];
        $data['template'] = (int) $operation['template'];
        $data['context_key'] = (string) $operation['context_key'];
        $data['class_key'] = 'modDocument';
        $data['tvs'] = true;
        $data['syncsite'] = true;
        $data['clearCache'] = true;

        foreach ((array) $operation['tvs'] as $tv) {
            $data['tv' . (int) $tv['id']] = $tv['value'];
        }

        $response = (new ProcessorRunner($this->modx))->run('resource/create', $this->formatDates($data));

        if (!$response['ok']) {
            return [$this->processorError($response), null];
        }

        $id = (int) ($response['object']['id'] ?? 0);
        $resource = $id > 0 ? $this->modx->getObject('modResource', $id) : null;

        return [
            [
                'status' => 'ok',
                'id' => $id,
                'parent' => (int) $operation['parent'],
                'uri' => $resource ? (string) $resource->get('uri') : null,
                'url' => $id > 0 ? $this->modx->makeUrl($id, $operation['context_key'], '', 'full') : null,
            ],
            ['op' => 'create', 'id' => $id, 'ref' => $operation['ref']],
        ];
    }

    /* ---------------------------------------------------------------------
     * Журнал и откат
     * ------------------------------------------------------------------- */

    public function history(array $query): array
    {
        $resource = (int) ($query['resource'] ?? 0);
        $from = !empty($query['from']) ? strtotime((string) $query['from']) : 0;
        $to = !empty($query['to']) ? strtotime((string) $query['to']) : 0;

        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $query['to']) === 1) {
            $to += 86399;
        }

        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $items = [];

        foreach ($this->storage->ids('changes') as $id) {
            $change = $this->storage->load('changes', $id);

            if ($change === null) {
                continue;
            }

            $created = (int) $change['created'];

            if (($from && $created < $from) || ($to && $created > $to)) {
                continue;
            }

            if ($resource && !in_array($resource, (array) $change['resources'], true)) {
                continue;
            }

            $items[] = [
                'change_id' => $change['change_id'],
                'created' => date('c', $created),
                'actor' => $change['actor'],
                'comment' => $change['comment'],
                'operations' => count((array) $change['operations']),
                'resources' => $change['resources'],
                'rolled_back' => (bool) $change['rolled_back'],
            ];
        }

        return [
            'data' => array_slice($items, $offset, $limit),
            'meta' => ['total' => count($items), 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function rollback(string $changeId, bool $dryRun, bool $force): array
    {
        $change = $this->storage->load('changes', $changeId);

        if ($change === null) {
            throw ApiException::notFound('Изменение ' . $changeId . ' не найдено');
        }

        if (!empty($change['rolled_back'])) {
            throw new ApiException('ALREADY_ROLLED_BACK', 'Это изменение уже откачено', 409);
        }

        $plan = [];
        $conflicts = [];

        foreach (array_reverse((array) $change['operations']) as $entry) {
            $id = (int) $entry['id'];
            /** @var \modResource|null $resource */
            $resource = $this->modx->getObject('modResource', $id);

            if (!$resource) {
                $conflicts[] = ['id' => $id, 'message' => 'Ресурс не найден'];
                continue;
            }

            if ($entry['op'] === 'create') {
                if ($resource->get('deleted')) {
                    continue;
                }

                $plan[] = ['op' => 'delete', 'id' => $id, 'pagetitle' => (string) $resource->get('pagetitle')];

                if (Format::timestamp($resource->get('editedon')) > (int) $change['created'] + 5) {
                    $conflicts[] = ['id' => $id, 'message' => 'Созданный ресурс правили после создания'];
                }

                continue;
            }

            $fields = [];
            $tvs = [];
            $shown = [];

            foreach ((array) $entry['fields'] as $field => $values) {
                $now = $this->fieldValue($resource, $field);

                if (!Format::same($now, $values['new'])) {
                    $conflicts[] = ['id' => $id, 'field' => $field, 'message' => 'Поле изменили после правки'];
                }

                $fields[$field] = $values['old'];
                $shown[] = $this->displayChange($field, $now, $values['old']);
            }

            $current = $this->reader->tvValues($id, (int) $resource->get('template'));

            foreach ((array) $entry['tvs'] as $name => $values) {
                $now = $current[$name]['value'] ?? null;

                if (!Format::same($now, $values['new'])) {
                    $conflicts[] = ['id' => $id, 'field' => 'tv:' . $name, 'message' => 'TV изменили после правки'];
                }

                $tvs[(int) $values['id']] = $values['old'];
                $shown[] = $this->displayChange('tv:' . $name, $now, $values['old']);
            }

            $plan[] = ['op' => 'revert', 'id' => $id, 'pagetitle' => (string) $resource->get('pagetitle'), 'changes' => $shown,
                '_fields' => $fields, '_tvs' => $tvs];
        }

        $publicPlan = array_map(static function (array $item): array {
            unset($item['_fields'], $item['_tvs']);

            return $item;
        }, $plan);

        if ($dryRun) {
            return ['change_id' => $changeId, 'dry_run' => true, 'plan' => $publicPlan, 'conflicts' => $conflicts];
        }

        if ($conflicts !== [] && !$force) {
            throw new ApiException(
                'CONFLICT',
                'После изменения ресурсы правили ещё раз. Проверьте план (dry_run) и повторите с force: true',
                409,
                ['conflicts' => $conflicts]
            );
        }

        $runner = new ProcessorRunner($this->modx);
        $results = [];

        foreach ($plan as $item) {
            if ($item['op'] === 'delete') {
                $response = $runner->run('resource/delete', ['id' => $item['id']]);
            } else {
                $resource = $this->modx->getObject('modResource', $item['id']);
                $response = $resource
                    ? $runner->run('resource/update', $this->updateData($resource, $item['_fields'], $item['_tvs']))
                    : ['ok' => false, 'message' => 'Ресурс не найден', 'errors' => [], 'object' => []];
            }

            $results[] = ['op' => $item['op'], 'id' => $item['id']]
                + ($response['ok'] ? ['status' => 'ok'] : $this->processorError($response));
        }

        $change['rolled_back'] = true;
        $change['rolled_back_at'] = time();
        $change['rollback_results'] = $results;
        $this->storage->save('changes', $changeId, $change);

        return ['change_id' => $changeId, 'dry_run' => false, 'results' => $results];
    }

    /* ---------------------------------------------------------------------
     * Вспомогательные методы
     * ------------------------------------------------------------------- */

    /**
     * Полный набор данных для resource/update.
     * Процессор сбрасывает непереданные поля и TV, поэтому передаём всё.
     */
    private function updateData(\modResource $resource, array $fields, array $tvsById): array
    {
        $data = $resource->toArray();
        unset($data['editedon'], $data['editedby']);

        foreach ($fields as $field => $value) {
            $data[$field] = $value;
        }

        foreach ($this->reader->tvValues((int) $resource->get('id'), (int) $resource->get('template')) as $tv) {
            $data['tv' . $tv['id']] = $tv['value'];
        }

        foreach ($tvsById as $tvId => $value) {
            $data['tv' . (int) $tvId] = $value;
        }

        $data['tvs'] = true;
        $data['syncsite'] = true;
        $data['clearCache'] = true;

        return $this->formatDates($data);
    }

    /**
     * Процессоры ожидают даты строкой, как из формы админки.
     * Значение может быть timestamp (из запроса) или строкой (из toArray()).
     */
    private function formatDates(array $data): array
    {
        foreach (self::DATE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = Format::timestamp($data[$field]);
            $data[$field] = $value > 0 ? date('Y-m-d H:i:s', $value) : '';
        }

        return $data;
    }

    /**
     * Текущее значение поля ресурса: даты — timestamp, как в Format::input().
     */
    private function fieldValue(\modResource $resource, string $field)
    {
        $value = $resource->get($field);

        return in_array($field, self::DATE_FIELDS, true) ? Format::timestamp($value) : $value;
    }

    private function processorError(array $response): array
    {
        return array_filter([
            'status' => 'error',
            'code' => 'PROCESSOR_ERROR',
            'message' => $response['message'] !== '' ? $response['message'] : 'Процессор вернул ошибку',
            'errors' => $response['errors'] ?: null,
        ], static function ($value) {
            return $value !== null;
        });
    }

    private function assertEditable(\modResource $resource): void
    {
        $id = (int) $resource->get('id');

        if (in_array($id, Settings::protectedIds(), true)) {
            throw new ApiException('PROTECTED_RESOURCE', 'Ресурс ' . $id . ' защищён от изменений', 403);
        }

        $allowed = (array) Settings::get('allowed_class_keys', []);

        if (!in_array((string) $resource->get('class_key'), $allowed, true)) {
            throw new ApiException(
                'CLASS_NOT_ALLOWED',
                'Ресурсы класса ' . $resource->get('class_key') . ' пока нельзя менять',
                403,
                ['allowed' => array_values($allowed)]
            );
        }
    }

    private function assertAllowedFields(array $fields, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', $fields), $allowed));

        if ($unknown !== []) {
            throw new ApiException(
                'FIELD_NOT_ALLOWED',
                'Эти поля нельзя менять: ' . implode(', ', $unknown),
                400,
                ['fields' => $unknown, 'allowed' => array_values($allowed)]
            );
        }
    }

    private function assertTv(string $name, array $tvs): array
    {
        if (!isset($tvs[$name])) {
            throw new ApiException(
                'FIELD_NOT_ALLOWED',
                'TV ' . $name . ' не привязан к шаблону ресурса',
                400,
                ['allowed' => array_keys(array_filter($tvs, static function (array $tv) {
                    return $tv['editable'];
                }))]
            );
        }

        if (!$tvs[$name]['editable']) {
            throw new ApiException('FIELD_NOT_ALLOWED', 'TV ' . $name . ' нельзя менять', 400);
        }

        return $tvs[$name];
    }

    private function assertAliasFree(string $alias, int $parent, string $context, int $exceptId): void
    {
        if ($alias === '') {
            return;
        }

        $criteria = ['parent' => $parent, 'alias' => $alias, 'context_key' => $context, 'deleted' => 0];

        if ($exceptId > 0) {
            $criteria['id:!='] = $exceptId;
        }

        if ($this->modx->getCount('modResource', $criteria) > 0) {
            throw new ApiException('ALIAS_EXISTS', 'В этом разделе уже есть страница с alias ' . $alias, 409, ['field' => 'alias']);
        }
    }

    private function arrayParam(array $operation, string $key): array
    {
        $value = $operation[$key] ?? [];

        if (!is_array($value)) {
            throw ApiException::validation($key . ' должен быть объектом', ['field' => $key]);
        }

        return $value;
    }

    private function tvInput($name, $value): string
    {
        if (is_array($value)) {
            return implode('||', array_map('strval', $value));
        }

        if (is_object($value)) {
            throw ApiException::validation('Некорректное значение TV ' . $name, ['field' => 'tv:' . $name]);
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Предупреждения по стандартам: обязательные поля и длины.
     */
    private function standardWarnings(int $templateId, array $fields, array $tvs, array $changedFields): array
    {
        $warnings = [];
        $pageType = Settings::pageType(null, $templateId);

        foreach ((array) ($pageType['required'] ?? []) as $required) {
            $required = (string) $required;

            if (strpos($required, 'tv:') === 0) {
                $name = substr($required, 3);
                $empty = trim((string) ($tvs[$name] ?? '')) === '';
            } else {
                $empty = trim((string) ($fields[$required] ?? '')) === '';
            }

            if ($empty) {
                $warnings[] = ['check' => 'required', 'field' => $required, 'message' => 'Не заполнено'];
            }
        }

        foreach ((array) Settings::get('limits', []) as $field => $limit) {
            if (!in_array($field, $changedFields, true)) {
                continue;
            }

            $length = mb_strlen(trim(strip_tags((string) ($fields[$field] ?? ''))), 'UTF-8');

            if ($length === 0) {
                continue;
            }

            if (isset($limit['max']) && $length > (int) $limit['max']) {
                $warnings[] = ['check' => 'lengths', 'field' => $field, 'message' => 'Длина ' . $length . ', максимум ' . (int) $limit['max']];
            }

            if (isset($limit['min']) && $length < (int) $limit['min']) {
                $warnings[] = ['check' => 'lengths', 'field' => $field, 'message' => 'Длина ' . $length . ', минимум ' . (int) $limit['min']];
            }
        }

        return $warnings;
    }

    private function displayChange(string $field, $old, $new): array
    {
        return ['field' => $field, 'old' => $this->displayValue($field, $old), 'new' => $this->displayValue($field, $new)];
    }

    private function displayValue(string $field, $value)
    {
        $value = Format::display($field, $value);

        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::DISPLAY_LIMIT) {
            return mb_substr($value, 0, self::DISPLAY_LIMIT, 'UTF-8') . '… [обрезано, всего ' . mb_strlen($value, 'UTF-8') . ' символов]';
        }

        return $value;
    }
}

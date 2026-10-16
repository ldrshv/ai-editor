<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;
use PDO;

/**
 * Чтение ресурсов и их TV.
 */
class ResourceReader
{
    private const RESOURCE_FIELDS = [
        'id', 'context_key', 'class_key', 'pagetitle', 'longtitle', 'description', 'introtext',
        'menutitle', 'alias', 'uri', 'parent', 'template', 'published', 'deleted', 'hidemenu',
        'searchable', 'isfolder', 'menuindex', 'pub_date', 'unpub_date', 'publishedon',
        'createdon', 'createdby', 'editedon', 'editedby',
    ];

    private const SORT_FIELDS = ['id', 'pagetitle', 'menuindex', 'createdon', 'editedon', 'publishedon', 'pub_date', 'parent', 'template'];

    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function get(int $id, array $include = ['tvs', 'content'], int $contentLimit = 0): array
    {
        /** @var \modResource|null $resource */
        $resource = $this->modx->getObject('modResource', $id);

        if (!$resource) {
            throw ApiException::notFound('Ресурс не найден');
        }

        $data = [];

        foreach (self::RESOURCE_FIELDS as $field) {
            $data[$field] = Format::field($field, $resource->get($field));
        }

        $data['url'] = $this->modx->makeUrl($id, $resource->get('context_key'), '', 'full');
        $data['parent'] = $this->shortResource((int) $resource->get('parent'));
        $data['template'] = $this->shortTemplate((int) $resource->get('template'));

        $users = $this->users([(int) $resource->get('createdby'), (int) $resource->get('editedby')]);
        $data['createdby'] = $users[(int) $resource->get('createdby')] ?? null;
        $data['editedby'] = $users[(int) $resource->get('editedby')] ?? null;

        if (in_array('content', $include, true)) {
            $content = (string) $resource->get('content');
            $limit = $contentLimit > 0 ? $contentLimit : (int) Settings::get('content_limit', 20000);
            $length = mb_strlen($content, 'UTF-8');

            $data['content'] = $length > $limit ? mb_substr($content, 0, $limit, 'UTF-8') : $content;
            $data['content_length'] = $length;
            $data['content_truncated'] = $length > $limit;
        }

        if (in_array('tvs', $include, true)) {
            $tvs = [];

            foreach ($this->tvValues($id, (int) $resource->get('template')) as $name => $tv) {
                if (!$tv['editable']) {
                    continue;
                }

                $tvs[$name] = [
                    'value' => $tv['value'],
                    'caption' => $tv['caption'],
                    'type' => $tv['type'],
                ];
            }

            $data['tvs'] = $tvs;
        }

        $data['protected'] = in_array($id, Settings::protectedIds(), true);

        return $data;
    }

    /**
     * Значения TV ресурса: name => [id, value, caption, type, editable].
     */
    public function tvValues(int $resourceId, int $templateId): array
    {
        $tvs = [];

        foreach ((new SiteSchema($this->modx))->templateTvs($templateId) as $tv) {
            $tvs[$tv['name']] = [
                'id' => $tv['id'],
                'value' => $tv['default'],
                'caption' => $tv['caption'],
                'type' => $tv['type'],
                'editable' => $tv['editable'],
            ];
        }

        if ($tvs === [] || $resourceId <= 0) {
            return $tvs;
        }

        $byId = [];

        foreach ($tvs as $name => $tv) {
            $byId[$tv['id']] = $name;
        }

        $query = $this->modx->newQuery('modTemplateVarResource', [
            'contentid' => $resourceId,
            'tmplvarid:IN' => array_keys($byId),
        ]);
        $query->select(['tmplvarid', 'value']);

        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = $byId[(int) $row['tmplvarid']] ?? null;

                if ($name !== null) {
                    $tvs[$name]['value'] = (string) $row['value'];
                }
            }
        }

        return $tvs;
    }

    public function search(array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? Settings::get('search_default_limit', 50));
        $maxLimit = (int) Settings::get('search_max_limit', 200);

        if ($limit < 1 || $limit > $maxLimit) {
            throw ApiException::validation('limit должен быть от 1 до ' . $maxLimit, ['field' => 'limit']);
        }

        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $fields = $this->searchFields($filters['fields'] ?? null);

        $where = $this->buildWhere($filters);

        if ($where === null) {
            return ['data' => [], 'meta' => ['total' => 0, 'limit' => $limit, 'offset' => $offset]];
        }

        $total = (int) $this->modx->getCount('modResource', $this->modx->newQuery('modResource', $where));

        $query = $this->modx->newQuery('modResource', $where);
        $query->select(array_map(static function (string $field): string {
            return 'modResource.' . $field;
        }, $fields));

        [$sortField, $sortDir] = $this->sort((string) ($filters['sort'] ?? '-editedon'));
        $query->sortby('modResource.' . $sortField, $sortDir);
        $query->sortby('modResource.id', 'ASC');
        $query->limit($limit, $offset);

        $rows = [];

        if ($query->prepare() && $query->stmt->execute()) {
            $rows = $query->stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $userIds = [];

        foreach ($rows as $row) {
            foreach (['createdby', 'editedby'] as $field) {
                if (isset($row[$field])) {
                    $userIds[] = (int) $row[$field];
                }
            }
        }

        $users = $this->users($userIds);
        $result = [];

        foreach ($rows as $row) {
            $item = [];

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if ($field === 'createdby' || $field === 'editedby') {
                    $item[$field] = $users[(int) $value] ?? null;
                    continue;
                }

                $item[$field] = Format::field($field, $value);
            }

            $result[] = $item;
        }

        return [
            'data' => $result,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /**
     * Условия поиска. null — заведомо пустой результат.
     */
    private function buildWhere(array $filters): ?array
    {
        $where = [];

        $context = (string) ($filters['context'] ?? 'web');
        $where['context_key'] = $context;

        if (!empty($filters['ids'])) {
            $where['id:IN'] = array_map('intval', (array) $filters['ids']);
        }

        if (isset($filters['parent']) && $filters['parent'] !== null && $filters['parent'] !== '') {
            $parent = (int) $filters['parent'];
            $depth = (int) ($filters['depth'] ?? 1);
            $depth = $depth <= 0 ? 100 : min($depth, 100);

            $children = $this->modx->getChildIds($parent, $depth, ['context' => $context]);

            if ($children === []) {
                return null;
            }

            $where[] = ['id:IN' => array_map('intval', $children)];
        }

        if (isset($filters['template']) && $filters['template'] !== null && $filters['template'] !== '') {
            $where['template:IN'] = array_map('intval', (array) $filters['template']);
        }

        if (isset($filters['published']) && $filters['published'] !== null) {
            $where['published'] = !empty($filters['published']) ? 1 : 0;
        }

        $where['deleted'] = !empty($filters['deleted']) ? 1 : 0;

        if (!empty($filters['class_key'])) {
            $where['class_key:IN'] = (array) $filters['class_key'];
        }

        foreach (['createdby', 'editedby'] as $field) {
            if (!empty($filters[$field])) {
                $where[$field . ':IN'] = array_map('intval', (array) $filters[$field]);
            }
        }

        foreach (['created' => 'createdon', 'edited' => 'editedon'] as $prefix => $field) {
            if (!empty($filters[$prefix . '_from'])) {
                $where[$field . ':>='] = $this->timestamp((string) $filters[$prefix . '_from'], false);
            }

            if (!empty($filters[$prefix . '_to'])) {
                $where[$field . ':<='] = $this->timestamp((string) $filters[$prefix . '_to'], true);
            }
        }

        $text = trim((string) ($filters['query'] ?? ''));

        if ($text !== '') {
            $like = '%' . $text . '%';
            $where[] = [
                'pagetitle:LIKE' => $like,
                'OR:longtitle:LIKE' => $like,
                'OR:alias:LIKE' => $like,
                'OR:uri:LIKE' => $like,
            ];
        }

        return $where;
    }

    private function searchFields($fields): array
    {
        $allowed = (array) Settings::get('search_fields', []);

        if (empty($fields)) {
            return ['id', 'pagetitle', 'uri', 'parent', 'template', 'published', 'editedon', 'editedby'];
        }

        $fields = array_values(array_unique(array_map('strval', (array) $fields)));
        $unknown = array_diff($fields, $allowed);

        if ($unknown !== []) {
            throw ApiException::validation(
                'Недопустимые поля: ' . implode(', ', $unknown),
                ['allowed' => array_values($allowed)]
            );
        }

        if (!in_array('id', $fields, true)) {
            array_unshift($fields, 'id');
        }

        return $fields;
    }

    private function sort(string $sort): array
    {
        $direction = strpos($sort, '-') === 0 ? 'DESC' : 'ASC';
        $field = ltrim($sort, '-');

        if (!in_array($field, self::SORT_FIELDS, true)) {
            throw ApiException::validation(
                'Недопустимая сортировка: ' . $field,
                ['allowed' => self::SORT_FIELDS]
            );
        }

        return [$field, $direction];
    }

    private function timestamp(string $value, bool $endOfDay): int
    {
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw ApiException::validation('Некорректная дата: ' . $value);
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $timestamp += 86399;
        }

        return $timestamp;
    }

    public function shortResource(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $resource = $this->modx->getObject('modResource', $id);

        return $resource
            ? ['id' => $id, 'pagetitle' => (string) $resource->get('pagetitle'), 'uri' => (string) $resource->get('uri')]
            : ['id' => $id, 'pagetitle' => null, 'uri' => null];
    }

    public function shortTemplate(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $template = $this->modx->getObject('modTemplate', $id);

        return ['id' => $id, 'name' => $template ? (string) $template->get('templatename') : null];
    }

    /**
     * Пользователи: id => [id, username, fullname].
     */
    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $query = $this->modx->newQuery('modUser', ['modUser.id:IN' => $ids]);
        $query->leftJoin('modUserProfile', 'Profile', 'Profile.internalKey = modUser.id');
        $query->select(['modUser.id', 'modUser.username', 'Profile.fullname']);

        $result = [];

        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    'fullname' => (string) ($row['fullname'] ?? ''),
                ];
            }
        }

        return $result;
    }
}

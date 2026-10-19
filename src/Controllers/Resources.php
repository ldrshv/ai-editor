<?php

namespace AiEditor\Controllers;

use AiEditor\Services\Blocks;
use Zoomx\Controllers\Controller;

/**
 * Ресурсы для MCP:
 *   POST /api/ai-editor/resources/search
 *   GET  /api/ai-editor/resources/{id}
 *
 * Ответы через jsonx(): успех — {success: true, data}, ошибка — {success: false, errors: {code, message}}.
 */
class Resources extends Controller
{
    private const MAX_LIMIT = 100;

    public function search()
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $input = $this->input();
        $query = trim((string) ($input['query'] ?? ''));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? 20)));
        $offset = max(0, (int) ($input['offset'] ?? 0));

        $c = $this->modx->newQuery('modResource');
        $c->where(['deleted' => false]);

        if ($query !== '') {
            $like = '%' . $query . '%';
            $or = [
                'pagetitle:LIKE' => $like,
                'OR:longtitle:LIKE' => $like,
                'OR:menutitle:LIKE' => $like,
                'OR:alias:LIKE' => $like,
                'OR:uri:LIKE' => $like,
            ];
            if (ctype_digit($query)) {
                $or['OR:id:='] = (int) $query;
            }
            $c->where($or);
        }

        if (isset($input['parent']) && $input['parent'] !== '' && $input['parent'] !== null) {
            $c->where(['parent' => (int) $input['parent']]);
        }

        if (!empty($input['template'])) {
            $c->where(['template' => (int) $input['template']]);
        }

        if (isset($input['published']) && $input['published'] !== '' && $input['published'] !== null) {
            $c->where(['published' => filter_var($input['published'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0]);
        }

        if (!empty($input['context'])) {
            $c->where(['context_key' => (string) $input['context']]);
        }

        $total = (int) $this->modx->getCount('modResource', $c);

        $c->sortby('parent', 'ASC');
        $c->sortby('menuindex', 'ASC');
        $c->sortby('id', 'ASC');
        $c->limit($limit, $offset);

        $templates = $this->templateNames();
        $items = [];

        foreach ($this->modx->getIterator('modResource', $c) as $r) {
            $id = (int) $r->get('id');
            $tpl = (int) $r->get('template');

            $items[] = [
                'id' => $id,
                'pagetitle' => (string) $r->get('pagetitle'),
                'longtitle' => (string) $r->get('longtitle'),
                'menutitle' => (string) $r->get('menutitle'),
                'alias' => (string) $r->get('alias'),
                'uri' => (string) $r->get('uri'),
                'url' => $this->modx->makeUrl($id, $r->get('context_key'), '', 'full'),
                'parent' => (int) $r->get('parent'),
                'context_key' => (string) $r->get('context_key'),
                'template' => ['id' => $tpl, 'name' => $templates[$tpl] ?? null],
                'published' => (bool) $r->get('published'),
                'hidemenu' => (bool) $r->get('hidemenu'),
                'isfolder' => (bool) $r->get('isfolder'),
                'menuindex' => (int) $r->get('menuindex'),
                'editedon' => $this->iso($r->get('editedon')),
            ];
        }

        return jsonx([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => $offset + count($items) < $total,
            'items' => $items,
        ]);
    }

    public function get($id)
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $id = (int) $id;

        /** @var \modResource|null $r */
        $r = $this->modx->getObject('modResource', $id);

        if (!$r) {
            return $this->fail(404, 'NOT_FOUND', 'Ресурс ' . $id . ' не найден');
        }

        $content = (string) $r->get('content');
        $length = mb_strlen($content);
        $max = (int) $this->modx->getOption('ai_editor.max_content', null, 100000);
        $truncated = $max > 0 && $length > $max;

        $parentId = (int) $r->get('parent');
        $parent = null;
        if ($parentId > 0 && ($p = $this->modx->getObject('modResource', $parentId))) {
            $parent = ['id' => $parentId, 'pagetitle' => (string) $p->get('pagetitle')];
        }

        $tplId = (int) $r->get('template');
        $tplName = null;
        if ($tplId > 0 && ($t = $this->modx->getObject('modTemplate', $tplId))) {
            $tplName = (string) $t->get('templatename');
        }

        $tvs = [];
        $blocks = null;
        $blocksTv = Blocks::tvId();
        $collection = $r->getTemplateVars();
        if (is_array($collection) || $collection instanceof \Traversable) {
            foreach ($collection as $tv) {
                if ($blocksTv > 0 && (int) $tv->get('id') === $blocksTv) {
                    $blocks = (new Blocks($this->modx))->parse((string) $tv->get('value'));
                    continue;
                }
                $tvs[$tv->get('name')] = [
                    'value' => $tv->get('value'),
                    'caption' => (string) $tv->get('caption'),
                    'type' => (string) $tv->get('type'),
                ];
            }
        }

        return jsonx([
            'id' => $id,
            'context_key' => (string) $r->get('context_key'),
            'class_key' => (string) $r->get('class_key'),
            'pagetitle' => (string) $r->get('pagetitle'),
            'longtitle' => (string) $r->get('longtitle'),
            'description' => (string) $r->get('description'),
            'introtext' => (string) $r->get('introtext'),
            'menutitle' => (string) $r->get('menutitle'),
            'alias' => (string) $r->get('alias'),
            'uri' => (string) $r->get('uri'),
            'parent' => $parent,
            'template' => $tplId > 0 ? ['id' => $tplId, 'name' => $tplName] : null,
            'published' => (bool) $r->get('published'),
            'deleted' => (bool) $r->get('deleted'),
            'hidemenu' => (bool) $r->get('hidemenu'),
            'searchable' => (bool) $r->get('searchable'),
            'isfolder' => (bool) $r->get('isfolder'),
            'menuindex' => (int) $r->get('menuindex'),
            'pub_date' => $this->iso($r->get('pub_date')),
            'unpub_date' => $this->iso($r->get('unpub_date')),
            'publishedon' => $this->iso($r->get('publishedon')),
            'createdon' => $this->iso($r->get('createdon')),
            'createdby' => $this->user((int) $r->get('createdby')),
            'editedon' => $this->iso($r->get('editedon')),
            'editedby' => $this->user((int) $r->get('editedby')),
            'url' => $this->modx->makeUrl($id, $r->get('context_key'), '', 'full'),
            'content' => $truncated ? mb_substr($content, 0, $max) : $content,
            'content_length' => $length,
            'content_truncated' => $truncated,
            'tvs' => $tvs,
            'blocks' => $blocks,
            'protected' => $this->modx->getCount('modResourceGroupResource', ['document' => $id]) > 0,
        ]);
    }

    /**
     * Проверка IP и ключа. Если в модуле уже есть общая проверка
     * (базовый контроллер или middleware), замените этот метод на неё.
     */
    private function guard()
    {
        $ips = trim((string) $this->modx->getOption('ai_editor.allowed_ips', null, ''));
        if ($ips !== '') {
            $allowed = array_filter(array_map('trim', preg_split('/[\s,;]+/', $ips)));
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!in_array($ip, $allowed, true)) {
                return $this->fail(403, 'FORBIDDEN_IP', 'Доступ с IP ' . $ip . ' запрещён');
            }
        }

        $expected = trim((string) $this->modx->getOption('ai_editor.api_key', null, ''));
        if ($expected === '') {
            return $this->fail(401, 'UNAUTHORIZED', 'Ключ ai_editor.api_key не настроен');
        }

        $provided = $this->bearer();
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->fail(401, 'UNAUTHORIZED', 'Неверный ключ доступа');
        }

        return null;
    }

    private function bearer(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strtolower((string) $name) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) ? $m[1] : '';
    }

    /**
     * Единая точка ответа об ошибке. Если в проекте есть errorx(),
     * подставьте вызов здесь — остальной код менять не нужно.
     */
    private function fail(int $status, string $code, string $message)
    {
        return jsonx(['code' => $code, 'message' => $message], [], $status);
    }

    private function input(): array
    {
        if (!empty($_POST) && is_array($_POST)) {
            return $_POST;
        }

        $data = json_decode((string) file_get_contents('php://input'), true);

        return is_array($data) ? $data : [];
    }

    private function templateNames(): array
    {
        $names = [0 => null];

        foreach ($this->modx->getIterator('modTemplate') as $t) {
            $names[(int) $t->get('id')] = (string) $t->get('templatename');
        }

        return $names;
    }

    private function user(int $id): ?array
    {
        if ($id < 1 || !($u = $this->modx->getObject('modUser', $id))) {
            return null;
        }

        $profile = $u->getOne('Profile');

        return [
            'id' => $id,
            'username' => (string) $u->get('username'),
            'fullname' => $profile ? (string) $profile->get('fullname') : '',
        ];
    }

    /**
     * MODX 2 отдаёт даты строкой «Y-m-d H:i:s», а не timestamp.
     * Приведение (int) оставляло от строки год — отсюда 1970-01-01.
     */
    private function iso($value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $ts ? date('c', $ts) : null;
    }
}

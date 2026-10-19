<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;

/**
 * Блоки страницы (MIGX-TV): чтение и правка по операциям.
 * Поля и типы — из формы MIGX на сайте. MIGX хранит массив записей,
 * значения — строки, вложенные MIGX-поля — JSON строкой. Порядок — порядок в массиве.
 */
class Blocks
{
    public const ACTIONS = ['add', 'update', 'remove', 'move'];

    private const KEYS = [
        'add' => ['action', 'after', 'fields'],
        'update' => ['action', 'id', 'fields'],
        'remove' => ['action', 'id'],
        'move' => ['action', 'id', 'after'],
    ];

    private const DISPLAY_LIMIT = 1000;
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var modX */
    private $modx;

    /** @var array|null */
    private $form;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public static function tvId(): int
    {
        return (int) Settings::get('blocks_tv', 0);
    }

    public static function editable(): bool
    {
        return (bool) Settings::get('blocks_editable', true);
    }

    public static function hash(string $raw): string
    {
        return sha1(trim($raw));
    }

    public function form(): array
    {
        if ($this->form === null) {
            $this->form = (new MigxForm($this->modx))->load(self::tvId());
        }

        return $this->form;
    }

    public function parse(string $raw): array
    {
        $result = [
            'tv' => self::tvId(),
            'editable' => self::editable(),
            'hash' => self::hash($raw),
            'form' => null,
            'blocks' => [],
            'errors' => [],
        ];

        try {
            $fields = $this->form()['fields'];
            $rows = $this->decode($raw);
        } catch (ApiException $exception) {
            $result['editable'] = false;
            $result['errors'][] = ['code' => $exception->errorCode(), 'message' => $exception->getMessage()];

            return $result;
        }

        $result['form'] = $fields;

        foreach ($rows as $i => $row) {
            $block = ['id' => (int) ($row['MIGX_id'] ?? 0), 'position' => $i + 1, 'fields' => []];

            foreach ($fields as $name => $def) {
                $value = (string) ($row[$name] ?? '');
                $block['fields'][$name] = ($def['type'] ?? '') === 'migx' ? $this->readList($value) : $value;
            }

            $extra = array_diff_key($row, $fields, ['MIGX_id' => 1]);

            if ($extra !== []) {
                $block['extra'] = $extra;
            }

            $result['blocks'][] = $block;
        }

        return $result;
    }

    private function readList(string $json): array
    {
        try {
            $items = $this->decodeList($json, 'list', -1);
        } catch (ApiException $exception) {
            return [];
        }

        return array_map(static function (array $item): array {
            $id = (int) ($item['MIGX_id'] ?? 0);
            unset($item['MIGX_id']);

            return ['id' => $id] + array_map(static function ($value) {
                return is_scalar($value) ? (string) $value : $value;
            }, $item);
        }, $items);
    }

    /**
     * @return array{raw: string, changes: array, warnings: array}
     */
    public function change(string $raw, array $ops): array
    {
        if (!self::editable()) {
            throw new ApiException('FIELD_NOT_ALLOWED', 'Правка блоков выключена (blocks_editable)', 400);
        }

        $defs = $this->form()['fields'];
        $original = $this->decode($raw);
        $rows = $original;
        $changes = [];

        foreach (array_values($ops) as $n => $op) {
            if (!is_array($op)) {
                throw $this->error($n, 'операция должна быть объектом');
            }

            $action = (string) ($op['action'] ?? '');

            if (!isset(self::KEYS[$action])) {
                throw $this->error($n, 'action: ' . implode(', ', self::ACTIONS));
            }

            $unknown = array_values(array_diff(array_keys($op), self::KEYS[$action]));

            if ($unknown !== []) {
                throw $this->error($n, $action . ': лишние ключи ' . implode(', ', $unknown), 'FIELD_NOT_ALLOWED', 400, ['allowed' => self::KEYS[$action]]);
            }

            if ($action === 'add') {
                $row = ['MIGX_id' => (string) ($this->maxId($rows) + 1)];

                foreach (array_keys($defs) as $name) {
                    $row[$name] = '';
                }

                $diff = $this->setFields($row, $op['fields'] ?? [], $defs, $n);
                $position = array_key_exists('after', $op) ? $this->after($rows, $op['after'], $n) : count($rows);
                array_splice($rows, $position, 0, [$row]);
                $changes[] = ['action' => 'add', 'id' => (int) $row['MIGX_id'], 'position' => $position + 1] + $diff;
            } elseif ($action === 'update') {
                $i = $this->index($rows, $op['id'] ?? null, $n);
                $diff = $this->setFields($rows[$i], $op['fields'] ?? [], $defs, $n);
                $changes[] = ['action' => 'update', 'id' => (int) $rows[$i]['MIGX_id']]
                    + ($diff !== [] ? $diff : ['message' => 'Изменений нет']);
            } elseif ($action === 'remove') {
                $i = $this->index($rows, $op['id'] ?? null, $n);
                $id = (int) $rows[$i]['MIGX_id'];
                array_splice($rows, $i, 1);
                $changes[] = ['action' => 'remove', 'id' => $id, 'position' => $i + 1];
            } else {
                if (!array_key_exists('after', $op)) {
                    throw $this->error($n, 'для move нужен after (MIGX_id блока или 0 — в начало)');
                }

                $i = $this->index($rows, $op['id'] ?? null, $n);
                $row = $rows[$i];

                if ((int) $op['after'] === (int) $row['MIGX_id']) {
                    throw $this->error($n, 'after не может совпадать с id');
                }

                array_splice($rows, $i, 1);
                $position = $this->after($rows, $op['after'], $n);
                array_splice($rows, $position, 0, [$row]);
                $changes[] = ['action' => 'move', 'id' => (int) $row['MIGX_id'], 'from' => $i + 1, 'to' => $position + 1];
            }
        }

        return [
            'raw' => $rows == $original ? $raw : $this->json(array_values($rows)),
            'changes' => $changes,
            'warnings' => [],
        ];
    }

    private function setFields(array &$row, $input, array $defs, int $n): array
    {
        if (!is_array($input) || ($input !== [] && array_keys($input) === range(0, count($input) - 1))) {
            throw $this->error($n, 'fields — объект {поле: значение}');
        }

        $diff = [];

        foreach ($input as $name => $value) {
            $name = (string) $name;

            if (!isset($defs[$name])) {
                throw $this->error($n, 'поля ' . $name . ' нет в форме MIGX', 'FIELD_NOT_ALLOWED', 400, ['allowed' => array_keys($defs)]);
            }

            if (($defs[$name]['type'] ?? '') === 'migx') {
                $sub = $this->setList($row, $name, $value, (array) ($defs[$name]['fields'] ?? []), $n);

                if ($sub !== []) {
                    $diff['fields'][$name] = $sub;
                }

                continue;
            }

            $new = $this->scalar($value, $defs[$name], $n, $name);
            $old = (string) ($row[$name] ?? '');

            if ($old !== $new) {
                $diff['fields'][$name] = ['old' => $this->short($old), 'new' => $this->short($new)];
            }

            $row[$name] = $new;
        }

        return $diff;
    }

    private function setList(array &$row, string $name, $list, array $defs, int $n): array
    {
        if (!is_array($list) || ($list !== [] && array_keys($list) !== range(0, count($list) - 1))) {
            throw $this->error($n, $name . ' — список объектов');
        }

        $byId = [];
        $max = 0;

        foreach ($this->decodeList((string) ($row[$name] ?? ''), $name, $n) as $item) {
            $id = (int) ($item['MIGX_id'] ?? 0);
            $byId[$id] = $item;
            $max = max($max, $id);
        }

        $new = [];
        $seen = [];
        $diff = ['added' => [], 'changed' => [], 'removed' => []];

        foreach ($list as $k => $input) {
            $path = $name . '[' . $k . ']';

            if (!is_array($input)) {
                throw $this->error($n, $path . ' должен быть объектом');
            }

            $unknown = array_values(array_diff(array_keys($input), array_merge(['id'], array_keys($defs))));

            if ($unknown !== []) {
                throw $this->error($n, $path . ': полей нет в форме: ' . implode(', ', $unknown), 'FIELD_NOT_ALLOWED', 400, ['allowed' => array_keys($defs)]);
            }

            $id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : 0;
            $isNew = $id === 0;

            if ($isNew) {
                $id = ++$max;
                $item = ['MIGX_id' => (string) $id];

                foreach (array_keys($defs) as $field) {
                    $item[$field] = '';
                }
            } else {
                if (!isset($byId[$id])) {
                    throw $this->error($n, $path . ': элемента ' . $id . ' нет');
                }

                if (isset($seen[$id])) {
                    throw $this->error($n, $path . ': элемент ' . $id . ' указан дважды');
                }

                $seen[$id] = true;
                $item = $byId[$id];
            }

            unset($input['id']);

            foreach ($input as $field => $value) {
                if (($defs[$field]['type'] ?? '') === 'migx') {
                    throw $this->error($n, $path . '.' . $field . ': вложенный MIGX второго уровня не поддерживается');
                }

                $value = $this->scalar($value, $defs[$field], $n, $path . '.' . $field);
                $was = (string) ($item[$field] ?? '');

                if (!$isNew && $was !== $value) {
                    $diff['changed'][] = ['id' => $id, 'field' => $field, 'old' => $this->short($was), 'new' => $this->short($value)];
                }

                $item[$field] = $value;
            }

            if ($isNew) {
                $diff['added'][] = ['id' => $id] + array_map([$this, 'short'], array_filter(array_diff_key($item, ['MIGX_id' => 1]), 'strlen'));
            }

            $new[] = $item;
        }

        foreach (array_keys($byId) as $id) {
            if (!isset($seen[$id])) {
                $diff['removed'][] = $id;
            }
        }

        $oldOrder = array_values(array_intersect(array_keys($byId), array_keys($seen)));
        $newOrder = [];

        foreach ($new as $item) {
            if (isset($byId[(int) $item['MIGX_id']])) {
                $newOrder[] = (int) $item['MIGX_id'];
            }
        }

        if ($oldOrder !== $newOrder) {
            $diff['reordered'] = true;
        }

        $diff = array_filter($diff);

        if ($diff !== []) {
            $row[$name] = $new === [] ? '' : $this->json($new);
        }

        return $diff;
    }

    private function scalar($value, array $def, int $n, string $name): string
    {
        if (is_array($value) || is_object($value)) {
            throw $this->error($n, $name . ' должно быть строкой');
        }

        $type = (string) ($def['type'] ?? 'text');
        $options = $def['options'] ?? null;

        if ($type === 'checkbox') {
            $on = (string) ($options[0]['value'] ?? '1');

            return $this->flag($value) || ($on !== '' && (string) $value === $on) ? $on : '';
        }

        $value = $value === null ? '' : (string) $value;

        if (($type === 'listbox' || $type === 'option') && is_array($options)) {
            $allowed = array_column($options, 'value');

            if (!in_array($value, $allowed, true)) {
                throw $this->error($n, $name . ': значение не из списка формы', 'VALIDATION_ERROR', 400, ['allowed' => $allowed]);
            }
        }

        if ($type === 'number' && $value !== '' && !is_numeric($value)) {
            throw $this->error($n, $name . ' должно быть числом');
        }

        return $value;
    }

    private function decode(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $rows = json_decode($raw, true);

        if (!is_array($rows)) {
            throw new ApiException('BLOCKS_JSON', 'Некорректный JSON блоков: ' . json_last_error_msg(), 409);
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function decodeList(string $json, string $name, int $n): array
    {
        if (trim($json) === '') {
            return [];
        }

        $list = json_decode($json, true);

        if (!is_array($list)) {
            throw new ApiException('BLOCKS_JSON', ($n >= 0 ? 'blocks[' . $n . ']: ' : '') . $name . ' содержит некорректный JSON', 409);
        }

        return array_values(array_filter($list, 'is_array'));
    }

    private function json(array $data): string
    {
        $json = json_encode($data, self::JSON);

        if ($json === false) {
            throw new ApiException('BLOCKS_JSON', 'Не удалось собрать JSON блоков: ' . json_last_error_msg(), 500);
        }

        return $json;
    }

    private function index(array $rows, $id, int $n): int
    {
        if ($id === null || $id === '' || !is_scalar($id)) {
            throw $this->error($n, 'не указан id блока (MIGX_id)');
        }

        foreach ($rows as $i => $row) {
            if ((int) ($row['MIGX_id'] ?? 0) === (int) $id) {
                return $i;
            }
        }

        throw $this->error($n, 'блок ' . $id . ' не найден', 'NOT_FOUND', 404);
    }

    private function after(array $rows, $after, int $n): int
    {
        return (int) $after === 0 ? 0 : $this->index($rows, $after, $n) + 1;
    }

    private function maxId(array $rows): int
    {
        $max = 0;

        foreach ($rows as $row) {
            $max = max($max, (int) ($row['MIGX_id'] ?? 0));
        }

        return $max;
    }

    private function flag($value): bool
    {
        return in_array(is_string($value) ? strtolower(trim($value)) : $value, [true, 1, '1', 'true', 'yes', 'да'], true);
    }

    private function short(string $value): string
    {
        return mb_strlen($value, 'UTF-8') > self::DISPLAY_LIMIT
            ? mb_substr($value, 0, self::DISPLAY_LIMIT, 'UTF-8') . '… [' . mb_strlen($value, 'UTF-8') . ' симв.]'
            : $value;
    }

    private function error(int $n, string $message, string $code = 'VALIDATION_ERROR', int $status = 400, array $details = []): ApiException
    {
        return new ApiException($code, 'blocks[' . $n . ']: ' . $message, $status, ['block_op' => $n] + $details);
    }
}

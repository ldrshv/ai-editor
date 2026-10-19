<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;
use PDO;
use Throwable;

/**
 * Форма MIGX-TV с сайта: поля, типы, варианты, вложенные MIGX.
 * Порядок поиска как в MIGX: formtabs в свойствах TV → migxConfig по имени → configs/{name}.config.js.
 */
class MigxForm
{
    private const MAX_DEPTH = 2;

    /** @var array */
    private static $cache = [];

    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * @param int|string $tv ID или имя TV
     */
    public function load($tv, int $depth = 0): array
    {
        $key = (string) $tv;

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        /** @var \modTemplateVar|null $object */
        $object = $this->modx->getObject('modTemplateVar', is_numeric($tv) ? (int) $tv : ['name' => $key]);

        if (!$object || $object->get('type') !== 'migx') {
            throw new ApiException('BLOCKS_FORM', 'TV ' . $key . ' не найден или не MIGX', 500);
        }

        [$formtabs, $from] = $this->formtabs((array) $object->get('input_properties'));
        $fields = [];

        foreach ($formtabs as $tab) {
            foreach ((array) ($tab['fields'] ?? []) as $field) {
                $name = (string) ($field['field'] ?? '');

                if ($name !== '') {
                    $fields[$name] = $this->field((array) $field, $depth);
                }
            }
        }

        if ($fields === []) {
            throw new ApiException('BLOCKS_FORM', 'Не найдены поля формы MIGX у TV ' . $key, 500);
        }

        return self::$cache[$key] = [
            'tv' => (int) $object->get('id'),
            'name' => (string) $object->get('name'),
            'from' => $from,
            'fields' => $fields,
        ];
    }

    private function formtabs(array $props): array
    {
        $inline = trim((string) ($props['formtabs'] ?? ''));

        if ($inline !== '' && $inline !== '[]') {
            return [$this->json($inline), 'tv'];
        }

        $path = (string) $this->modx->getOption('migx.core_path', null, MODX_CORE_PATH . 'components/migx/');
        $this->modx->addPackage('migx', $path . 'model/');

        foreach (array_filter(array_map('trim', explode(',', (string) ($props['configs'] ?? '')))) as $name) {
            $name = explode(':', $name)[0];

            if ($config = $this->modx->getObject('migxConfig', ['name' => $name, 'deleted' => 0])) {
                $tabs = $config->get('formtabs');

                return [is_array($tabs) ? $tabs : $this->json((string) $tabs), 'migxConfig:' . $name];
            }

            $file = $path . 'configs/' . $name . '.config.js';

            if (is_file($file)) {
                $data = $this->json((string) file_get_contents($file));

                return [(array) ($data['formtabs'] ?? []), 'file:' . $name];
            }
        }

        return [[], null];
    }

    private function field(array $field, int $depth): array
    {
        $type = (string) ($field['inputTVtype'] ?? '');
        $inputTv = (string) ($field['inputTV'] ?? '');
        $result = ['caption' => (string) ($field['caption'] ?? '')];

        if ((string) ($field['description'] ?? '') !== '') {
            $result['description'] = (string) $field['description'];
        }

        if ($inputTv !== '' && ($tv = $this->modx->getObject('modTemplateVar', ['name' => $inputTv]))) {
            if ($tv->get('type') === 'migx' && $depth < self::MAX_DEPTH) {
                return $result + ['type' => 'migx', 'fields' => $this->load($inputTv, $depth + 1)['fields']];
            }

            $type = $type !== '' ? $type : (string) $tv->get('type');
        }

        $result['type'] = $type !== '' ? $type : 'text';
        $options = $this->options((string) ($field['inputOptionValues'] ?? ''));

        if ($options !== null) {
            $result['options'] = $options;
        }

        return $result;
    }

    private function options(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (stripos($raw, '@SELECT') === 0) {
            $sql = str_replace('[[+PREFIX]]', (string) $this->modx->getOption('table_prefix'), substr($raw, 1));

            try {
                $stmt = $this->modx->query($sql);
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
            } catch (Throwable $exception) {
                return null;
            }

            return array_map(static function (array $row): array {
                return ['label' => (string) $row[0], 'value' => (string) ($row[1] ?? $row[0])];
            }, $rows);
        }

        if ($raw[0] === '@') {
            return null;
        }

        $options = [];

        foreach (explode('||', $raw) as $item) {
            $parts = explode('==', $item, 2);
            $options[] = ['label' => $parts[0], 'value' => $parts[1] ?? $parts[0]];
        }

        return $options;
    }

    private function json(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ApiException('BLOCKS_FORM', 'Некорректный JSON конфига MIGX', 500);
        }

        return $data;
    }
}

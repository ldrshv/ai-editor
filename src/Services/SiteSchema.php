<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;
use PDO;
use Throwable;

/**
 * Описание сайта для ИИ: шаблоны, TV, разделы, стандарты.
 */
class SiteSchema
{
    private const PACKAGES = ['zoomx', 'minishop2', 'migx', 'pdotools', 'formit', 'ajaxform', 'clientconfig', 'seosuite', 'seopro'];

    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function site(): array
    {
        $version = $this->modx->getVersionData();

        return [
            'site' => [
                'name' => (string) $this->modx->getOption('site_name'),
                'url' => (string) $this->modx->getOption('site_url'),
                'modx' => (string) ($version['full_version'] ?? ''),
                'php' => PHP_VERSION,
                'packages' => $this->packages(),
            ],
            'contexts' => $this->contexts(),
            'fields' => (array) Settings::get('fields', []),
            'editable' => [
                'update_fields' => array_values((array) Settings::get('update_fields', [])),
                'create_fields' => array_values((array) Settings::get('create_fields', [])),
                'class_keys' => array_values((array) Settings::get('allowed_class_keys', [])),
                'tvs' => 'Все TV шаблона, кроме TV блоков',
            ],
            'page_types' => $this->pageTypes(),
            'limits' => (array) Settings::get('limits', []),
            'sections' => $this->sections(),
            'templates' => $this->templates(),
            'media_sources' => $this->mediaSources(),
            'protected' => Settings::protectedIds(),
            'blocks' => [
                'tv' => (int) Settings::get('blocks_tv', 0),
                'editable' => false,
            ],
        ];
    }

    public function template(int $id): array
    {
        /** @var \modTemplate|null $template */
        $template = $this->modx->getObject('modTemplate', $id);

        if (!$template) {
            throw ApiException::notFound('Шаблон не найден');
        }

        $pageType = Settings::pageType(null, $id);

        return [
            'id' => $id,
            'name' => (string) $template->get('templatename'),
            'description' => (string) $template->get('description'),
            'page_type' => $pageType['key'] ?? null,
            'required' => $pageType['required'] ?? [],
            'tvs' => $this->templateTvs($id),
        ];
    }

    /**
     * TV шаблона с описаниями и вариантами значений.
     */
    public function templateTvs(int $templateId): array
    {
        $blocksTv = (int) Settings::get('blocks_tv', 0);

        $query = $this->modx->newQuery('modTemplateVar');
        $query->innerJoin('modTemplateVarTemplate', 'TVT', 'TVT.tmplvarid = modTemplateVar.id');
        $query->leftJoin('modCategory', 'Category', 'Category.id = modTemplateVar.category');
        $query->where(['TVT.templateid' => $templateId]);
        $query->select([
            'modTemplateVar.id', 'modTemplateVar.name', 'modTemplateVar.caption',
            'modTemplateVar.description', 'modTemplateVar.type', 'modTemplateVar.default_text',
            'modTemplateVar.elements', 'modTemplateVar.source',
            'TVT.rank AS tv_rank', 'Category.category AS category_name',
        ]);
        $query->sortby('TVT.rank', 'ASC');
        $query->sortby('modTemplateVar.id', 'ASC');

        $result = [];

        if (!$query->prepare() || !$query->stmt->execute()) {
            return $result;
        }

        foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];

            $result[] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'caption' => (string) $row['caption'],
                'description' => (string) $row['description'],
                'type' => (string) $row['type'],
                'default' => (string) $row['default_text'],
                'options' => $this->tvOptions($id, (string) $row['elements']),
                'source' => (int) $row['source'],
                'category' => $row['category_name'] !== null ? (string) $row['category_name'] : null,
                'rank' => (int) $row['tv_rank'],
                'editable' => $id !== $blocksTv,
            ];
        }

        return $result;
    }

    private function tvOptions(int $tvId, string $elements): ?array
    {
        $elements = trim($elements);

        if ($elements === '') {
            return null;
        }

        if ($elements[0] === '@') {
            try {
                /** @var \modTemplateVar|null $tv */
                $tv = $this->modx->getObject('modTemplateVar', $tvId);
                $elements = $tv ? (string) $tv->processBindings($elements, 0) : '';
            } catch (Throwable $exception) {
                return null;
            }
        }

        $options = [];

        foreach (explode('||', $elements) as $item) {
            if ($item === '') {
                continue;
            }

            $parts = explode('==', $item, 2);
            $options[] = [
                'label' => $parts[0],
                'value' => $parts[1] ?? $parts[0],
            ];
        }

        return $options ?: null;
    }

    private function packages(): array
    {
        $result = [];

        try {
            $query = $this->modx->newQuery('transport.modTransportPackage');
            $query->where(['installed:!=' => null]);
            $query->select(['signature', 'package_name']);
            $query->sortby('installed', 'ASC');

            if ($query->prepare() && $query->stmt->execute()) {
                foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $name = strtolower((string) $row['package_name']);

                    if (in_array($name, self::PACKAGES, true)) {
                        $result[$name] = (string) $row['signature'];
                    }
                }
            }
        } catch (Throwable $exception) {
            return $result;
        }

        return $result;
    }

    private function contexts(): array
    {
        $result = [];

        foreach ($this->modx->getIterator('modContext', ['key:!=' => 'mgr']) as $context) {
            $result[] = [
                'key' => (string) $context->get('key'),
                'name' => (string) ($context->get('name') ?: $context->get('key')),
            ];
        }

        return $result;
    }

    private function pageTypes(): array
    {
        $result = [];

        foreach (Settings::pageTypes() as $key => $type) {
            $result[] = ['key' => (string) $key] + $type;
        }

        return $result;
    }

    /**
     * Разделы первого уровня и родители из типов страниц.
     */
    private function sections(): array
    {
        $ids = [];

        $query = $this->modx->newQuery('modResource', ['parent' => 0, 'deleted' => 0, 'context_key' => 'web']);
        $query->select('id');
        $query->sortby('menuindex', 'ASC');

        if ($query->prepare() && $query->stmt->execute()) {
            $ids = array_map('intval', $query->stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        foreach (Settings::pageTypes() as $type) {
            if (!empty($type['parent'])) {
                $ids[] = (int) $type['parent'];
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $children = [];
        $query = $this->modx->newQuery('modResource', ['parent:IN' => $ids, 'deleted' => 0]);
        $query->select(['parent', 'template', 'COUNT(id) AS cnt']);
        $query->groupby('parent');
        $query->groupby('template');

        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $children[(int) $row['parent']][(int) $row['template']] = (int) $row['cnt'];
            }
        }

        $result = [];
        $query = $this->modx->newQuery('modResource', ['id:IN' => $ids]);
        $query->select(['id', 'pagetitle', 'uri', 'template', 'published']);

        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $templates = $children[$id] ?? [];
                arsort($templates);

                $result[] = [
                    'id' => $id,
                    'pagetitle' => (string) $row['pagetitle'],
                    'uri' => (string) $row['uri'],
                    'template' => (int) $row['template'],
                    'published' => (bool) $row['published'],
                    'children' => array_sum($templates),
                    'child_template' => $templates ? (int) array_key_first($templates) : null,
                ];
            }
        }

        return $result;
    }

    private function templates(): array
    {
        $counts = [];
        $query = $this->modx->newQuery('modResource', ['deleted' => 0]);
        $query->select(['template', 'COUNT(id) AS cnt']);
        $query->groupby('template');

        if ($query->prepare() && $query->stmt->execute()) {
            foreach ($query->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(int) $row['template']] = (int) $row['cnt'];
            }
        }

        $result = [];
        $query = $this->modx->newQuery('modTemplate');
        $query->sortby('templatename', 'ASC');

        foreach ($this->modx->getIterator('modTemplate', $query) as $template) {
            $id = (int) $template->get('id');
            $pageType = Settings::pageType(null, $id);

            $result[] = [
                'id' => $id,
                'name' => (string) $template->get('templatename'),
                'description' => (string) $template->get('description'),
                'page_type' => $pageType['key'] ?? null,
                'resources' => $counts[$id] ?? 0,
            ];
        }

        return $result;
    }

    private function mediaSources(): array
    {
        $result = [];

        foreach ($this->modx->getIterator('sources.modMediaSource') as $source) {
            $properties = (array) $source->get('properties');

            $result[] = [
                'id' => (int) $source->get('id'),
                'name' => (string) $source->get('name'),
                'base_path' => (string) ($properties['basePath']['value'] ?? ''),
            ];
        }

        return $result;
    }
}

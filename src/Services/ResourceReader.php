<?php

namespace AiEditor\Services;

use modX;
use PDO;

/**
 * TV ресурса и краткие сведения о ресурсах и шаблонах для предпросмотра и отката.
 * Сам get_resource и поиск ресурсов — в Controllers\Resources.
 */
class ResourceReader
{
    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
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
}

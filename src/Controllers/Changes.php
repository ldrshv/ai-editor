<?php

namespace AiEditor\Controllers;

use AiEditor\Services\ChangePipeline;

class Changes extends Controller
{
    /** POST /api/ai-editor/changes/preview */
    public function preview()
    {
        return $this->handle(function () {
            return (new ChangePipeline($this->modx))->preview($this->body());
        });
    }

    /** POST /api/ai-editor/changes/apply */
    public function apply()
    {
        return $this->handle(function () {
            return (new ChangePipeline($this->modx))->apply((string) ($this->body()['preview_id'] ?? ''));
        });
    }

    /** GET /api/ai-editor/changes?resource=&from=&to=&limit=&offset= */
    public function history()
    {
        return $this->handle(function () {
            return (new ChangePipeline($this->modx))->history($_GET);
        });
    }

    /** POST /api/ai-editor/changes/{id}/rollback */
    public function rollback($id)
    {
        return $this->handle(function () use ($id) {
            $body = $this->body();

            return (new ChangePipeline($this->modx))->rollback(
                (string) $id,
                !array_key_exists('dry_run', $body) || !empty($body['dry_run']),
                !empty($body['force'])
            );
        });
    }
}

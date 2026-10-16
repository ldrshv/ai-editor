<?php

namespace AiEditor\Controllers;

use AiEditor\Services\Settings;
use AiEditor\Services\SiteSchema;

class Site extends Controller
{
    /** GET /api/ai-editor/ping */
    public function ping()
    {
        return $this->handle(function () {
            return [
                'module' => 'ai-editor',
                'version' => (string) Settings::get('version', '1.0.0'),
                'time' => date('c'),
            ];
        });
    }

    /** GET /api/ai-editor/site */
    public function site()
    {
        return $this->handle(function () {
            return (new SiteSchema($this->modx))->site();
        });
    }

    /** GET /api/ai-editor/templates/{id} */
    public function template($id)
    {
        return $this->handle(function () use ($id) {
            return (new SiteSchema($this->modx))->template((int) $id);
        });
    }
}

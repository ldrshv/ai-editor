<?php
/**
 * Маршруты модуля ai-editor.
 * Подключается в core/config/routes.php.
 *
 * @var FastRoute\RouteCollector $router
 */
$router->addGroup('api/ai-editor', function (FastRoute\RouteCollector $r) {
    $r->get('/ping', ['\AiEditor\Controllers\Site', 'ping']);
    $r->get('/site', ['\AiEditor\Controllers\Site', 'site']);
    $r->get('/templates/{id:\d+}', ['\AiEditor\Controllers\Site', 'template']);

    $r->post('/resources/search', ['\AiEditor\Controllers\Resources', 'search']);
    $r->get('/resources/{id:\d+}', ['\AiEditor\Controllers\Resources', 'get']);

    $r->post('/changes/preview', ['\AiEditor\Controllers\Changes', 'preview']);
    $r->post('/changes/apply', ['\AiEditor\Controllers\Changes', 'apply']);
    $r->get('/changes', ['\AiEditor\Controllers\Changes', 'history']);
    $r->post('/changes/{id:[a-z0-9_]+}/rollback', ['\AiEditor\Controllers\Changes', 'rollback']);
});

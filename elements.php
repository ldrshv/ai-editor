<?php
/**
 * Модуль ai-editor: API для правки сайта ИИ через MCP-коннектор E3 Editor.
 * Подключается в core/config/elements.php.
 */
zoomx()->getLoader()->addPsr4('AiEditor\\', __DIR__ . '/src/');

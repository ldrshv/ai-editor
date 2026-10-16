# ai-editor

API для правки сайта E3 искусственным интеллектом через MCP-коннектор **E3 Editor**.
Интерфейса нет: модулем пользуется MCP-сервер `e3-editor` на внутреннем сайте.

Этап 1: чтение сайта и ресурсов, правка полей и TV, создание страниц, журнал и откат.
Блоки (TV 97), товары и шаблоны `.tpl` не меняются.

## Установка

1. Скопируйте папку в `core/app/modules/ai-editor/`.

2. Подключите модуль — по строке в двух файлах сайта:

```php
// core/config/elements.php
require_once MODX_CORE_PATH . 'app/modules/ai-editor/elements.php';

// core/config/routes.php
require_once MODX_CORE_PATH . 'app/modules/ai-editor/routes.php';
```

3. Создайте системные настройки (namespace `core`, область `ai_editor`):

| Ключ | Значение |
|---|---|
| `ai_editor.api_key` | Случайная строка от 32 символов. Без неё API закрыт |
| `ai_editor.allowed_ips` | IP внутреннего сайта через запятую. Пусто — любой IP (только для отладки) |
| `ai_editor.user` | Имя пользователя MODX, от которого вносятся изменения, например `ai-editor` |

4. Создайте пользователя `ai-editor`:
   - группа с доступом к контексту `mgr` и политикой уровня Content Editor:
     `view_document`, `save_document`, `new_document`, `publish_document`,
     `unpublish_document`, `delete_document`, `edit_document`;
   - доступ к контексту `web` (Load, List and View).

   Все правки ИИ в `editedby` будут видны от этого пользователя.

5. Папка журнала `core/storage/ai-editor/` должна быть доступна PHP на запись.
   Добавьте её в `.gitignore`.

6. При необходимости создайте `core/config/ai-editor.php` со стандартами сайта
   (пример — `ai-editor.example.php` рядом с этим файлом).

## Проверка

```bash
curl -H "Authorization: Bearer KEY" https://site.ru/api/ai-editor/ping
curl -H "Authorization: Bearer KEY" https://site.ru/api/ai-editor/site
curl -H "Authorization: Bearer KEY" https://site.ru/api/ai-editor/resources/1

curl -X POST -H "Authorization: Bearer KEY" -H "Content-Type: application/json" \
  -d '{"published": false, "sort": "-editedon", "limit": 10}' \
  https://site.ru/api/ai-editor/resources/search
```

Если всегда приходит `401`, сервер не передаёт заголовок `Authorization` в PHP.
Для Apache проверьте `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` в `.htaccess`.

## Эндпоинты

| Метод и адрес | Назначение |
|---|---|
| `GET /api/ai-editor/ping` | Проверка связи |
| `GET /api/ai-editor/site` | Сводка: шаблоны, разделы, стандарты, медиаисточники |
| `GET /api/ai-editor/templates/{id}` | Шаблон и его TV |
| `POST /api/ai-editor/resources/search` | Поиск ресурсов |
| `GET /api/ai-editor/resources/{id}` | Поля и TV ресурса (`?include=tvs,content`) |
| `POST /api/ai-editor/changes/preview` | Подготовить изменения, показать разницу |
| `POST /api/ai-editor/changes/apply` | Применить по `preview_id` |
| `GET /api/ai-editor/changes` | Журнал изменений |
| `POST /api/ai-editor/changes/{id}/rollback` | Откат. По умолчанию `dry_run: true` |

Формат ответа: `{"ok": true, "data": ...}` или `{"ok": false, "error": {"code", "message"}}`.

## Пример изменения

```json
POST /api/ai-editor/changes/preview
{
  "comment": "Метатеги услуги",
  "operations": [
    {"op": "update", "id": 245, "fields": {"longtitle": "Монтаж вентиляции — цены", "description": "..."}},
    {"op": "create", "parent": 12, "template": 5,
     "fields": {"pagetitle": "Ремонт насосов", "introtext": "...", "content": "<p>...</p>"},
     "tvs": {"image": "uslugi/nasos.jpg"}}
  ]
}

POST /api/ai-editor/changes/apply
{"preview_id": "p_20261015_160100_a1b2c3"}
```

Новые страницы создаются неопубликованными, если явно не передан `published: 1`.

## Как устроено

```
ai-editor/
├── elements.php            регистрация namespace AiEditor\
├── routes.php              маршруты api/ai-editor/...
├── config.php              настройки по умолчанию
└── src/
    ├── Controllers/        принимают запрос, отдают JSON
    ├── Services/
    │   ├── Settings.php        config.php + core/config/ai-editor.php
    │   ├── SiteSchema.php      шаблоны, TV, разделы
    │   ├── ResourceReader.php  чтение ресурсов и TV
    │   ├── ChangeService.php   предпросмотр, применение, журнал, откат
    │   ├── ProcessorRunner.php процессоры MODX от ai_editor.user
    │   ├── Storage.php         JSON-файлы журнала
    │   └── Format.php          приведение значений
    └── Exceptions/ApiException.php
```

Изменения идут через процессоры `resource/create`, `resource/update`, `resource/delete`,
поэтому срабатывают плагины, alias и очистка кеша. Процессор обновления сбрасывает
непереданные поля и TV, поэтому `ChangeService` всегда передаёт полный набор значений.

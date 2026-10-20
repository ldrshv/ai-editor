<?php

namespace AiEditor\Services;

use AiEditor\Exceptions\ApiException;
use modX;

/**
 * Предпросмотр, применение и откат с файлами: op=file — через FileOps, остальное — через ChangeService.
 * Файлы хранятся отдельно: file_previews/<preview_id>, file_changes/<change_id>; ID совпадают с ID ChangeService.
 */
class ChangePipeline
{
    /** @var modX */
    private $modx;

    /** @var Storage */
    private $storage;

    /** @var ChangeService */
    private $changes;

    /** @var FileOps */
    private $files;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $this->storage = new Storage();
        $this->changes = new ChangeService($modx);
        $this->files = new FileOps($modx);
    }

    public function preview(array $payload): array
    {
        $ops = is_array($payload['operations'] ?? null) ? array_values($payload['operations']) : [];
        $fileOps = [];
        $other = [];
        $map = [];

        foreach ($ops as $i => $op) {
            if (is_array($op) && ($op['op'] ?? '') === 'file') {
                $fileOps[$i] = $op;
            } else {
                $map[count($other)] = $i;
                $other[] = $op;
            }
        }

        if ($fileOps === []) {
            return $this->changes->preview($payload);
        }

        $display = [];
        $errors = [];
        $prepared = [];

        foreach ($fileOps as $i => $op) {
            try {
                [$internal, $shown] = $this->files->prepare($op);
                $prepared[] = $internal;
                $display[$i] = ['index' => $i] + $shown;
            } catch (ApiException $e) {
                $errors[] = ['index' => $i, 'code' => $e->errorCode(), 'message' => $e->getMessage(), 'details' => $e->details()];
            }
        }

        $inner = null;

        if ($other !== []) {
            $result = $this->changes->preview(['comment' => $payload['comment'] ?? '', 'actor' => $payload['actor'] ?? 'mcp', 'operations' => $other]);

            foreach ((array) $result['operations'] as $shown) {
                $shown['index'] = $map[$shown['index']] ?? $shown['index'];
                $display[$shown['index']] = $shown;
            }

            foreach ((array) $result['errors'] as $error) {
                $error['index'] = $map[$error['index']] ?? $error['index'];
                $errors[] = $error;
            }

            $inner = $result['preview_id'];
        }

        ksort($display);

        if ($errors !== []) {
            return ['preview_id' => null, 'operations' => array_values($display), 'errors' => $errors];
        }

        $id = $inner ?? Storage::newId('p');
        $expires = time() + (int) Settings::get('preview_ttl', 1800);

        $this->storage->save('file_previews', $id, [
            'id' => $id,
            'expires' => $expires,
            'inner' => $inner,
            'comment' => mb_substr(trim((string) ($payload['comment'] ?? '')), 0, 500, 'UTF-8'),
            'actor' => mb_substr(trim((string) ($payload['actor'] ?? 'mcp')), 0, 100, 'UTF-8'),
            'files' => $prepared,
        ]);

        return ['preview_id' => $id, 'expires_at' => date('c', $expires), 'operations' => array_values($display), 'errors' => []];
    }

    public function apply(string $id): array
    {
        $record = $this->validId($id) ? $this->storage->load('file_previews', $id) : null;

        if ($record === null) {
            return $this->changes->apply($id);
        }

        $this->storage->delete('file_previews', $id);

        if ((int) $record['expires'] < time()) {
            throw new ApiException('PREVIEW_EXPIRED', 'Предпросмотр устарел, сделайте новый', 410);
        }

        $results = [];
        $entries = [];
        $ok = true;

        foreach ((array) $record['files'] as $file) {
            [$result, $entry] = $this->files->apply($file);
            $results[] = ['op' => 'file', 'path' => $file['path']] + $result;
            $ok = $ok && $result['status'] !== 'error';

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $changeId = null;

        if (!empty($record['inner'])) {
            if ($ok) {
                $inner = $this->changes->apply((string) $record['inner']);
                $changeId = $inner['change_id'];
                $results = array_merge($results, (array) $inner['results']);
            } else {
                $results[] = ['status' => 'error', 'code' => 'FILE_NOT_WRITTEN', 'message' => 'Файл не записан, остальные изменения не применены'];
            }
        }

        if ($entries !== []) {
            if ($changeId === null) {
                $changeId = Storage::newId('c');
                $this->storage->save('changes', $changeId, [
                    'change_id' => $changeId,
                    'created' => time(),
                    'preview_id' => $id,
                    'actor' => (string) ($record['actor'] ?? ''),
                    'comment' => (string) ($record['comment'] ?? ''),
                    'resources' => [],
                    'operations' => [],
                    'rolled_back' => false,
                ]);
            }

            $this->storage->save('file_changes', $changeId, ['change_id' => $changeId, 'files' => $entries]);
        }

        return ['change_id' => $changeId, 'results' => $results];
    }

    public function history(array $query): array
    {
        return $this->changes->history($query);
    }

    public function rollback(string $id, bool $dryRun, bool $force): array
    {
        $record = $this->validId($id) ? $this->storage->load('file_changes', $id) : null;

        if ($record === null) {
            return $this->changes->rollback($id, $dryRun, $force);
        }

        $check = $this->changes->rollback($id, true, $force);
        $plan = (array) $check['plan'];
        $conflicts = (array) $check['conflicts'];
        $permissions = [];

        foreach ((array) $record['files'] as $entry) {
            $plan[] = ['op' => $entry['old_b64'] === null ? 'delete_file' : 'restore_file', 'path' => $entry['path']];
            $permissions[] = $entry['old_b64'] === null ? 'file_remove' : 'file_update';

            if ($this->files->conflict($entry)) {
                $conflicts[] = ['path' => $entry['path'], 'message' => 'Файл изменили или удалили после правки'];
            }
        }

        $runner = new ProcessorRunner($this->modx);
        $missing = array_values(array_unique(array_merge((array) $check['missing_permissions'], $runner->missing(array_values(array_unique($permissions))))));

        if ($dryRun) {
            return ['change_id' => $id, 'dry_run' => true, 'plan' => $plan, 'conflicts' => $conflicts, 'missing_permissions' => $missing];
        }

        if ($missing !== []) {
            throw new ApiException('PERMISSION_DENIED', 'У пользователя ' . $runner->username() . ' нет прав на откат: ' . implode(', ', $missing), 403, ['missing' => $missing]);
        }

        if ($conflicts !== [] && !$force) {
            throw new ApiException('CONFLICT', 'После изменения ресурсы или файлы правили ещё раз. Проверьте план (dry_run) и повторите с force: true', 409, ['conflicts' => $conflicts]);
        }

        $results = [];

        foreach ((array) $record['files'] as $entry) {
            $done = $this->files->revert($entry);
            $results[] = ['op' => $entry['old_b64'] === null ? 'delete_file' : 'restore_file', 'path' => $entry['path'], 'status' => $done ? 'ok' : 'error'];
        }

        $inner = $this->changes->rollback($id, false, true);

        return ['change_id' => $id, 'dry_run' => false, 'results' => array_merge($results, (array) $inner['results'])];
    }

    private function validId(string $id): bool
    {
        return preg_match('/^[a-z]_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $id) === 1;
    }
}

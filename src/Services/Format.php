<?php

namespace AiEditor\Services;

/**
 * Приведение значений к формату API.
 */
class Format
{
    public const BOOL_FIELDS = ['published', 'deleted', 'hidemenu', 'searchable', 'isfolder'];
    public const INT_FIELDS = ['id', 'parent', 'template', 'menuindex', 'createdby', 'editedby'];
    public const DATE_FIELDS = ['pub_date', 'unpub_date', 'createdon', 'editedon', 'publishedon', 'deletedon'];

    /**
     * Timestamp из значения MODX: число, строка «Y-m-d H:i:s» или пусто.
     *
     * MODX 2 отдаёт даты через get() и toArray() строкой, поэтому (int)
     * оставлял от неё только год — отсюда даты 1970-01-01.
     */
    public static function timestamp($value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === '0000-00-00 00:00:00') {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? 0 : $timestamp;
    }

    public static function date($timestamp): ?string
    {
        $timestamp = self::timestamp($timestamp);

        return $timestamp > 0 ? date('c', $timestamp) : null;
    }

    /**
     * Значение поля ресурса для ответа.
     */
    public static function field(string $field, $value)
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return (bool) $value;
        }

        if (in_array($field, self::INT_FIELDS, true)) {
            return (int) $value;
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return self::date($value);
        }

        return $value;
    }

    /**
     * Значение поля из запроса к формату хранения в MODX.
     */
    public static function input(string $field, $value)
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return !empty($value) && $value !== 'false' ? 1 : 0;
        }

        if ($field === 'menuindex') {
            return (int) $value;
        }

        if (in_array($field, ['pub_date', 'unpub_date'], true)) {
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                return 0;
            }

            $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

            if ($timestamp === false || $timestamp <= 0) {
                throw \AiEditor\Exceptions\ApiException::validation(
                    'Некорректная дата в поле ' . $field,
                    ['field' => $field]
                );
            }

            return $timestamp;
        }

        if (is_array($value) || is_object($value)) {
            throw \AiEditor\Exceptions\ApiException::validation(
                'Поле ' . $field . ' должно быть строкой',
                ['field' => $field]
            );
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Значение для показа в предпросмотре.
     */
    public static function display(string $field, $value)
    {
        if (in_array($field, ['pub_date', 'unpub_date'], true)) {
            return self::date($value);
        }

        if (in_array($field, self::BOOL_FIELDS, true)) {
            return (bool) $value;
        }

        return $value;
    }

    public static function same($a, $b): bool
    {
        return self::scalar($a) === self::scalar($b);
    }

    private static function scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value === null ? '' : (string) $value;
    }
}

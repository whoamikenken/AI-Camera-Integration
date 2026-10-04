<?php

namespace App\Support;

class CsvSanitizer
{
    /**
     * Sanitize a single CSV cell value against CSV / formula injection (DDE).
     * Prepends single quote if string starts with '=', '+', '-', '@', "\t", or "\r".
     */
    public static function sanitize(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $firstChar = $value[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Sanitize an entire row of values before passing to fputcsv.
     */
    public static function sanitizeRow(array $row): array
    {
        return array_map([self::class, 'sanitize'], $row);
    }
}

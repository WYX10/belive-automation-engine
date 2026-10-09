<?php

declare(strict_types=1);

namespace App\Core;

/** PostgreSQL equivalents of the bounded set of MySQL queries in this application. */
final class PostgresSql
{
    private const CONFLICT_KEYS = [
        'app_settings' => 'setting_key',
        'ai_model_config' => 'phase',
        'ai_feedback' => 'feedback_hash',
        'staff_shifts' => 'staff_id, weekday, starts_at',
        'electric_meters' => 'room_id',
        'content_post_metrics' => 'content_post_id, captured_on',
        'properties' => 'owner_name, name, location',
        'property_units' => 'property_id, name',
        'rooms' => 'room_code',
        'room_pricing' => 'room_id, tenure',
    ];

    public static function convert(string $sql): string
    {
        // Mask quoted content before rewriting so captions, SQL literals and comments
        // containing words like INTERVAL or INSERT IGNORE remain byte-for-byte intact.
        $quoted = [];
        $sql = preg_replace_callback(
            '~\'(?:\'\'|\\\\.|[^\'\\\\])*\'|`(?:``|[^`])*`|"(?:""|[^"])*"|--[^\n]*(?:\n|$)|/\*.*?\*/~s',
            static function (array $m) use (&$quoted): string {
                $key = '__belive_sql_quote_' . count($quoted) . '__';
                $value = $m[0];
                if ($value[0] === '`') {
                    $value = '"' . str_replace('`', '', substr($value, 1, -1)) . '"';
                }
                $quoted[$key] = $value;
                return $key;
            },
            $sql
        );

        $sql = preg_replace_callback('/\bDATE_FORMAT\(CURDATE\(\),\s*(__belive_sql_quote_\d+__)\)/i', static function (array $m) use ($quoted): string {
            if (($quoted[$m[1]] ?? '') !== "'%Y-%m-01'") {
                throw new \LogicException('Unsupported PostgreSQL date format.');
            }
            return "date_trunc('month', CURRENT_DATE)";
        }, $sql);
        $sql = preg_replace('/\bTIMESTAMPDIFF\(MINUTE,\s*MAX\(([a-z_][a-z0-9_.]*)\),\s*NOW\(\)\)/i', 'TRUNC(EXTRACT(EPOCH FROM (NOW() - MAX($1))) / 60)', $sql);
        $sql = preg_replace('/\bDAYOFWEEK\(([a-z_][a-z0-9_.]*)\)/i', '(CAST(EXTRACT(DOW FROM $1) AS integer) + 1)', $sql);
        $sql = preg_replace('/\bHOUR\(([a-z_][a-z0-9_.]*)\)/i', 'CAST(EXTRACT(HOUR FROM $1) AS integer)', $sql);
        $sql = preg_replace('/\bJSON_CONTAINS\(COALESCE\((\w+),\s*(__belive_sql_quote_\d+__)\),\s*\?\)/i', '(COALESCE($1, $2) @> CAST(? AS jsonb))', $sql);
        $sql = preg_replace_callback('/\bFIELD\(([a-z_][a-z0-9_.]*),\s*((?:__belive_sql_quote_\d+__\s*,\s*)*__belive_sql_quote_\d+__)\)/i', static function (array $m): string {
            $case = 'CASE ' . $m[1];
            foreach (preg_split('/\s*,\s*/', $m[2]) as $i => $value) {
                $case .= ' WHEN ' . $value . ' THEN ' . ($i + 1);
            }
            return $case . ' ELSE 0 END';
        }, $sql);
        $sql = preg_replace('/\bUTC_TIMESTAMP\(\)/i', "(statement_timestamp() AT TIME ZONE 'UTC')", $sql);
        $sql = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $sql);
        $sql = preg_replace('/\bDATABASE\(\)/i', 'current_database()', $sql);
        $sql = preg_replace('/\b([a-z_][a-z0-9_.]*)\s+REGEXP\b/i', '$1 COLLATE "default" ~', $sql);
        // A timestamp placeholder needs an explicit type before interval arithmetic.
        $sql = preg_replace('/\?(?=\s*[+-]\s*INTERVAL\b)/i', 'CAST(? AS timestamp)', $sql);
        $sql = preg_replace_callback('/\bINTERVAL\s+(\?|[0-9]+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/i', static fn (array $m): string => "(CAST(" . $m[1] . " AS integer) * INTERVAL '1 " . strtolower($m[2]) . "')", $sql);
        // MySQL's CI room/text search is retained; deterministic collation is
        // required for pattern matching on PostgreSQL 17 ICU CI text columns.
        $sql = preg_replace('/\b([a-z_][a-z0-9_.]*)\s+(NOT\s+)?LIKE\b/i', '$1 COLLATE "default" $2ILIKE', $sql);

        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO\b/i', $sql)) {
            $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql, 1);
            $sql .= ' ON CONFLICT DO NOTHING';
        } elseif (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
            preg_match('/^\s*INSERT\s+INTO\s+([a-z_][a-z0-9_]*)/i', $sql, $tableMatch);
            $table = strtolower($tableMatch[1] ?? '');
            $target = self::CONFLICT_KEYS[$table] ?? throw new \LogicException('Missing PostgreSQL conflict target for ' . $table);
            [$insert, $update] = preg_split('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql, 2);
            $update = preg_replace('/\bVALUES\(([a-z_][a-z0-9_]*)\)/i', 'EXCLUDED.$1', $update);
            $update = preg_replace('/\bLAST_INSERT_ID\(id\)/i', $table . '.id', $update);
            $update = preg_replace('/\b([a-z_][a-z0-9_]*)\s*=\s*\1\b/i', '$1 = ' . $table . '.$1', $update);
            $sql = $insert . ' ON CONFLICT (' . $target . ') DO UPDATE SET ' . $update;
        }

        return strtr($sql, $quoted);
    }
}

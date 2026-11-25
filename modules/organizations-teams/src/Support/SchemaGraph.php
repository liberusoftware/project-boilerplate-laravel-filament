<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Introspects single-column foreign-key edges across a set of tables.
 *
 * Returns a map of `table => [column => referenced_table]` using Laravel's
 * native schema introspection so backup imports and team cloning can remap
 * cross-table references without hardcoding domain schemas.
 */
class SchemaGraph
{
    /**
     * @param  list<string>  $tables
     * @return array<string, array<string, string>>
     */
    public static function edges(array $tables): array
    {
        $map = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $fk) {
                $columns = $fk['columns'] ?? [];
                $referenced = $fk['foreign_table'] ?? null;

                if ($referenced !== null && count($columns) === 1) {
                    $map[$table][$columns[0]] = $referenced;
                }
            }
        }

        return $map;
    }
}

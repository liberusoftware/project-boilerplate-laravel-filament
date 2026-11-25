<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\Organizations\Support\SchemaGraph;

/**
 * Clones a team's configured template data into a brand-new team.
 *
 * Host applications declare which team-scoped models or tables belong to a
 * team template via `config('organizations-teams.clone.models')` (or pass them
 * directly to {@see clone()}). Intra-template foreign keys are remapped using
 * both declared database foreign keys (via {@see SchemaGraph}) and any explicit
 * `fk_edges` configured in `config('organizations-teams.clone.fk_edges')`.
 */
class TeamCloneService
{
    /**
     * @param  list<string>|null  $models  Model class-strings or table names to clone (defaults to config)
     * @param  array<string, array<string, string>>|null  $fkEdges  Explicit FK edges: model/table => [column => referenced model/table]
     */
    public function clone(
        Team $source,
        string $name,
        User $owner,
        ?array $models = null,
        ?array $fkEdges = null,
    ): Team {
        $tables = $this->resolveTables($models ?? $this->configuredModels());
        $explicitEdges = $this->normalizeEdges($fkEdges ?? $this->configuredFkEdges());

        $team = new Team();
        $team->name = $name;
        $team->user_id = $owner->id;
        $team->personal_team = false;
        $team->save();

        // FK checks OFF must wrap (not nest inside) the transaction — sqlite
        // ignores PRAGMA foreign_keys once a transaction is open. With FKs off
        // we can insert rows keeping their old cross-refs, then patch to the
        // new ids; this needs no dependency ordering and handles self-refs.
        Schema::withoutForeignKeyConstraints(function () use ($source, $team, $tables, $explicitEdges): void {
            DB::transaction(function () use ($source, $team, $tables, $explicitEdges): void {
                $idMap = $this->copyRows($source, $team, $tables);
                $this->patchForeignKeys($team, $idMap, $explicitEdges);
            });
        });

        return $team;
    }

    /**
     * @return list<string>
     */
    private function configuredModels(): array
    {
        $configured = config('organizations-teams.clone.models', []);

        return is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function configuredFkEdges(): array
    {
        $configured = config('organizations-teams.clone.fk_edges', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * Resolve a list of Eloquent model class-strings or raw table names into
     * unique table names, skipping any model classes that are not installed.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    private function resolveTables(array $entries): array
    {
        $tables = [];

        foreach ($entries as $entry) {
            $table = $this->resolveTableName($entry);
            if ($table !== null && ! in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function resolveTableName(string $modelOrTable): ?string
    {
        if (str_contains($modelOrTable, '\\')) {
            if (! class_exists($modelOrTable) || ! is_subclass_of($modelOrTable, Model::class)) {
                return null;
            }

            return (new $modelOrTable())->getTable();
        }

        return $modelOrTable !== '' ? $modelOrTable : null;
    }

    /**
     * Normalize explicit FK edges from model class-strings or table names to
     * `table => [column => referenced_table]`.
     *
     * @param  array<string, array<string, string>>  $edges
     * @return array<string, array<string, string>>
     */
    private function normalizeEdges(array $edges): array
    {
        $normalized = [];

        foreach ($edges as $source => $columns) {
            $sourceTable = $this->resolveTableName($source);
            if ($sourceTable === null || ! is_array($columns)) {
                continue;
            }

            foreach ($columns as $column => $referenced) {
                $referencedTable = is_string($referenced) ? $this->resolveTableName($referenced) : null;
                if ($referencedTable !== null) {
                    $normalized[$sourceTable][$column] = $referencedTable;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, array<int, int>> per table: old id => new id
     */
    private function copyRows(Team $source, Team $team, array $tables): array
    {
        $idMap = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            $map = [];
            foreach (DB::table($table)->where('team_id', $source->id)->get() as $row) {
                $data = (array) $row;
                $oldId = (int) $data['id'];
                unset($data['id']);
                $data['team_id'] = $team->id;
                // Edge FK columns keep their old values here; patched in pass 2.
                $map[$oldId] = (int) DB::table($table)->insertGetId($data);
            }

            $idMap[$table] = $map;
        }

        return $idMap;
    }

    /**
     * @param  array<string, array<int, int>>  $idMap
     * @param  array<string, array<string, string>>  $explicitEdges
     */
    private function patchForeignKeys(Team $team, array $idMap, array $explicitEdges): void
    {
        $clonedTables = array_keys($idMap);
        $introspected = SchemaGraph::edges($clonedTables);

        foreach ($clonedTables as $table) {
            if (empty($idMap[$table])) {
                continue;
            }

            $edges = array_merge($introspected[$table] ?? [], $explicitEdges[$table] ?? []);
            unset($edges['team_id']);

            // Only remap columns that exist on this table and reference another cloned table.
            $edges = array_filter(
                $edges,
                fn (string $referenced, string $column): bool => isset($idMap[$referenced]) && Schema::hasColumn($table, $column),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($edges === []) {
                continue;
            }

            foreach (DB::table($table)->where('team_id', $team->id)->get() as $row) {
                $updates = [];
                foreach ($edges as $column => $referenced) {
                    $old = $row->{$column};
                    if ($old === null) {
                        continue;
                    }
                    // Unmapped ref -> null rather than dangle to the source team.
                    $updates[$column] = $idMap[$referenced][(int) $old] ?? null;
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        }
    }
}

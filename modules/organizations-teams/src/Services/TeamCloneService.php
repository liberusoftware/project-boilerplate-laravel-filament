<?php

declare(strict_types=1);

namespace Liberu\Foundation\Organizations\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * Clones a team's configured structural models into a brand-new team (template).
 * Copies registered models with fresh PKs and remaps their intra-config foreign
 * keys without copying transactional data or members.
 */
class TeamCloneService
{
    /**
     * @param  list<class-string>|null  $cloneableModels
     * @param  array<class-string, array<string, class-string>>|null  $fkEdges
     */
    public function __construct(
        private readonly ?array $cloneableModels = null,
        private readonly ?array $fkEdges = null,
    ) {}

    public function clone(Team $source, string $name, Model $owner): Team
    {
        $team = new Team();
        $team->name = $name;
        $team->user_id = $owner->getKey();
        $team->personal_team = false;
        $team->save();

        Schema::withoutForeignKeyConstraints(function () use ($source, $team): void {
            DB::transaction(function () use ($source, $team): void {
                $idMap = $this->copyRows($source, $team);
                $this->patchForeignKeys($team, $idMap);
            });
        });

        return $team;
    }

    /**
     * @return list<class-string>
     */
    private function resolveCloneableModels(): array
    {
        $models = $this->cloneableModels ?? config('organizations-teams.cloneable_models', []);

        return array_values(array_filter($models, fn (mixed $class): bool => is_string($class) && class_exists($class)));
    }

    /**
     * @return array<class-string, array<string, class-string>>
     */
    private function resolveFkEdges(): array
    {
        $edges = $this->fkEdges ?? config('organizations-teams.clone_fk_edges', []);

        return is_array($edges) ? $edges : [];
    }

    /**
     * @return array<class-string, array<int, int>> per model: old id => new id
     */
    private function copyRows(Team $source, Team $team): array
    {
        $idMap = [];

        foreach ($this->resolveCloneableModels() as $class) {
            $table = (new $class())->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }

            $map = [];
            foreach (DB::table($table)->where('team_id', $source->id)->get() as $row) {
                $data = (array) $row;
                $oldId = (int) $data['id'];
                unset($data['id']);
                $data['team_id'] = $team->id;
                $map[$oldId] = (int) DB::table($table)->insertGetId($data);
            }

            $idMap[$class] = $map;
        }

        return $idMap;
    }

    /**
     * @param  array<class-string, array<int, int>>  $idMap
     */
    private function patchForeignKeys(Team $team, array $idMap): void
    {
        foreach ($this->resolveFkEdges() as $class => $edges) {
            if (! is_string($class) || ! class_exists($class) || empty($idMap[$class])) {
                continue;
            }

            $table = (new $class())->getTable();

            $edges = array_filter(
                $edges,
                fn (string $column): bool => Schema::hasColumn($table, $column),
                ARRAY_FILTER_USE_KEY,
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
                    $updates[$column] = $idMap[$referenced][(int) $old] ?? null;
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime_relation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 36)->unique();
            $table->timestamps();
        });

        Schema::create('anime_relation_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')
                ->constrained('anime_relation_groups')
                ->cascadeOnDelete();
            $table->foreignId('anime_id')
                ->constrained('anime')
                ->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One anime belongs to at most one relation group.
            $table->unique('anime_id', 'anime_relation_group_items_anime_unique');
            $table->unique(['group_id', 'anime_id'], 'anime_relation_group_items_group_anime_unique');
            $table->index(['group_id', 'sort_order'], 'anime_relation_group_items_group_sort_idx');
        });

        $this->migrateLegacyRelationsToGroups();
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_relation_group_items');
        Schema::dropIfExists('anime_relation_groups');
    }

    private function migrateLegacyRelationsToGroups(): void
    {
        if (!Schema::hasTable('anime_relations')) {
            return;
        }

        $legacyRows = DB::table('anime_relations')
            ->select(['anime_id', 'related_anime_id', 'sort_order'])
            ->get();

        if ($legacyRows->isEmpty()) {
            return;
        }

        $adjacency = [];
        $nodeSortHints = [];

        foreach ($legacyRows as $row) {
            $fromId = (int) ($row->anime_id ?? 0);
            $toId = (int) ($row->related_anime_id ?? 0);
            $sortOrder = (int) ($row->sort_order ?? 0);

            if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
                continue;
            }

            $adjacency[$fromId][$toId] = true;
            $adjacency[$toId][$fromId] = true;

            $hint = $sortOrder > 0 ? $sortOrder : PHP_INT_MAX;
            if (!array_key_exists($toId, $nodeSortHints) || $hint < $nodeSortHints[$toId]) {
                $nodeSortHints[$toId] = $hint;
            }
        }

        if (empty($adjacency)) {
            return;
        }

        $visited = [];
        $now = now();

        foreach (array_keys($adjacency) as $startNodeId) {
            if (isset($visited[$startNodeId])) {
                continue;
            }

            $queue = [$startNodeId];
            $visited[$startNodeId] = true;
            $component = [];

            while (!empty($queue)) {
                $currentId = array_shift($queue);
                $component[] = $currentId;

                $neighbors = array_keys($adjacency[$currentId] ?? []);
                foreach ($neighbors as $neighborId) {
                    if (isset($visited[$neighborId])) {
                        continue;
                    }

                    $visited[$neighborId] = true;
                    $queue[] = $neighborId;
                }
            }

            if (count($component) < 2) {
                continue;
            }

            usort($component, function (int $leftId, int $rightId) use ($nodeSortHints) {
                $leftHint = $nodeSortHints[$leftId] ?? PHP_INT_MAX;
                $rightHint = $nodeSortHints[$rightId] ?? PHP_INT_MAX;

                if ($leftHint !== $rightHint) {
                    return $leftHint <=> $rightHint;
                }

                return $leftId <=> $rightId;
            });

            $groupId = DB::table('anime_relation_groups')->insertGetId([
                'group_key' => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $items = [];
            foreach (array_values($component) as $index => $animeId) {
                $items[] = [
                    'group_id' => $groupId,
                    'anime_id' => $animeId,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('anime_relation_group_items')->insert($items);
        }
    }
};


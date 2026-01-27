<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filter;
use App\Models\FilterGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FilterGroupAdminController extends Controller
{
    public function index()
    {
        $groups = FilterGroup::query()
            ->with([
                'filters' => fn($q) => $q
                    ->select('id', 'title', 'visible', 'filter_group_id')
                    ->orderBy('title'),
            ])
            ->orderBy('id', 'desc')
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(fn($group) => $this->mapGroupWithFilters($group));

        return response()->json([
            'filterGroups' => $groups,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'filters' => ['nullable', 'array'],
            'filters.*.title' => ['required', 'string', 'min:1', 'max:120'],
            'filters.*.visible' => ['nullable', 'boolean'],
        ]);

        $filtersPayload = $validated['filters'] ?? [];
        unset($validated['filters']);

        $group = DB::transaction(function () use ($validated, $filtersPayload) {
            $group = FilterGroup::create($validated);
            $this->syncFilters($group, $filtersPayload);
            $group->load([
                'filters' => fn($q) => $q
                    ->select('id', 'title', 'visible', 'filter_group_id')
                    ->orderBy('title'),
            ]);

            return $group;
        });

        return response()->json([
            'message' => 'Filter group created successfully',
            'filterGroup' => $this->mapGroupWithFilters($group),
        ], 201);
    }

    public function update(Request $request, FilterGroup $filterGroup)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'filters' => ['nullable', 'array'],
            'filters.*.id' => ['nullable', 'integer', 'exists:filters,id'],
            'filters.*.title' => ['required', 'string', 'min:1', 'max:120'],
            'filters.*.visible' => ['nullable', 'boolean'],
        ]);

        $filtersPayload = $validated['filters'] ?? [];
        unset($validated['filters']);

        $filterGroup = DB::transaction(function () use ($filterGroup, $validated, $filtersPayload) {
            $filterGroup->update($validated);
            if (is_array($filtersPayload)) {
                $this->syncFilters($filterGroup, $filtersPayload);
            }
            $filterGroup->load([
                'filters' => fn($q) => $q
                    ->select('id', 'title', 'visible', 'filter_group_id')
                    ->orderBy('title'),
            ]);

            return $filterGroup;
        });

        return response()->json([
            'message' => 'Filter group updated successfully',
            'filterGroup' => $this->mapGroupWithFilters($filterGroup),
        ]);
    }

    public function destroy(FilterGroup $filterGroup)
    {
        $filterIds = Filter::query()
            ->where('filter_group_id', $filterGroup->id)
            ->pluck('id');

        if ($filterIds->isNotEmpty()) {
            DB::table('filter_anime')->whereIn('filter_id', $filterIds)->delete();
            Filter::query()->whereIn('id', $filterIds)->delete();
        }

        $filterGroup->delete();

        return response()->json([
            'message' => 'Filter group deleted successfully',
        ]);
    }

    private function mapGroupWithFilters(FilterGroup $group): array
    {
        return [
            'id' => (int) $group->id,
            'title' => $group->title,
            'filters' => $group->filters->map(fn($filter) => [
                'id' => (int) $filter->id,
                'title' => $filter->title,
                'visible' => (bool) $filter->visible,
                'visible_label' => (bool) $filter->visible ? 'Visible' : 'Hidden',
                'filter_group_id' => (int) $filter->filter_group_id,
            ])->values(),
            'created_at' => $group->created_at?->format('d.m.Y H:i'),
            'updated_at' => $group->updated_at?->format('d.m.Y H:i'),
        ];
    }

    private function syncFilters(FilterGroup $group, array $filtersPayload): void
    {
        $existingIds = $group->filters()->pluck('filters.id')->all();
        $existingIdSet = collect($existingIds)->mapWithKeys(fn($id) => [$id => true]);
        $keptIds = [];

        foreach ($filtersPayload as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $visible = array_key_exists('visible', $item) ? (bool) $item['visible'] : true;
            $id = isset($item['id']) ? (int) $item['id'] : null;

            if ($id && $existingIdSet->has($id)) {
                $filter = Filter::query()->where('id', $id)->first();
                if ($filter) {
                    $filter->update([
                        'title' => $title,
                        'visible' => $visible,
                    ]);
                    $keptIds[] = $id;
                }
                continue;
            }

            $created = Filter::create([
                'title' => $title,
                'visible' => $visible,
                'filter_group_id' => $group->id,
            ]);
            $keptIds[] = $created->id;
        }

        $idsToDelete = array_values(array_diff($existingIds, $keptIds));
        if (empty($idsToDelete)) {
            return;
        }

        DB::table('filter_anime')->whereIn('filter_id', $idsToDelete)->delete();
        Filter::query()->whereIn('id', $idsToDelete)->delete();
    }
}

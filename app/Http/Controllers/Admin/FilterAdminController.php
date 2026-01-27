<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filter;
use App\Models\FilterGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FilterAdminController extends Controller
{
    public function index(Request $request)
    {
        $filters = Filter::query()
            ->join('filter_groups', 'filters.filter_group_id', '=', 'filter_groups.id')
            ->select(
                'filters.id',
                'filters.title',
                'filters.visible',
                'filters.filter_group_id',
                'filter_groups.title as group_title',
                'filters.created_at',
                'filters.updated_at',
            )
            ->orderBy('filter_groups.title')
            ->orderBy('filters.title')
            ->get()
            ->map(fn($row) => $this->mapFilterRow($row));

        $filterGroups = FilterGroup::query()
            ->orderBy('title')
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(fn($group) => $this->mapGroupRow($group));

        $filtersList = Filter::groupedList();

        return response()->json([
            'filters' => $filters,
            'filterGroups' => $filterGroups,
            'filtersList' => $filtersList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'filter_group_id' => ['required', 'integer', 'exists:filter_groups,id'],
            'visible' => ['nullable', 'boolean'],
        ]);

        if (!array_key_exists('visible', $validated)) {
            $validated['visible'] = true;
        }

        $filter = Filter::create($validated);
        $filter->load('group:id,title');

        return response()->json([
            'message' => 'Filter created successfully',
            'filter' => $this->mapFilterModel($filter),
        ], 201);
    }

    public function update(Request $request, Filter $filter)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'filter_group_id' => ['required', 'integer', 'exists:filter_groups,id'],
            'visible' => ['nullable', 'boolean'],
        ]);

        if (!array_key_exists('visible', $validated)) {
            $validated['visible'] = $filter->visible ?? true;
        }

        $filter->update($validated);
        $filter->load('group:id,title');

        return response()->json([
            'message' => 'Filter updated successfully',
            'filter' => $this->mapFilterModel($filter),
        ]);
    }

    public function destroy(Filter $filter)
    {
        DB::table('filter_anime')->where('filter_id', $filter->id)->delete();
        $filter->delete();

        return response()->json([
            'message' => 'Filter deleted successfully',
        ]);
    }

    private function mapFilterRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => $row->title,
            'visible' => (bool) $row->visible,
            'visible_label' => (bool) $row->visible ? 'Visible' : 'Hidden',
            'filter_group_id' => (int) $row->filter_group_id,
            'group_title' => $row->group_title,
            'created_at' => $row->created_at ? $row->created_at->format('d.m.Y H:i') : null,
            'updated_at' => $row->updated_at ? $row->updated_at->format('d.m.Y H:i') : null,
        ];
    }

    private function mapFilterModel(Filter $filter): array
    {
        return [
            'id' => (int) $filter->id,
            'title' => $filter->title,
            'visible' => (bool) $filter->visible,
            'visible_label' => (bool) $filter->visible ? 'Visible' : 'Hidden',
            'filter_group_id' => (int) $filter->filter_group_id,
            'group_title' => $filter->group?->title,
            'created_at' => $filter->created_at?->format('d.m.Y H:i'),
            'updated_at' => $filter->updated_at?->format('d.m.Y H:i'),
        ];
    }

    private function mapGroupRow(object $group): array
    {
        return [
            'id' => (int) $group->id,
            'title' => $group->title,
            'created_at' => $group->created_at ? $group->created_at->format('d.m.Y H:i') : null,
            'updated_at' => $group->updated_at ? $group->updated_at->format('d.m.Y H:i') : null,
        ];
    }
}

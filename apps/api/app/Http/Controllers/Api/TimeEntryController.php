<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Request;

class TimeEntryController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->endOfMonth()->toDateString());

        $entries = TimeEntry::where('organization_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to.' 23:59:59'])
            ->with(['project:id,name,scope', 'site:id,name', 'user:id,name'])
            ->latest('occurred_at')
            ->limit(200)
            ->get();

        $byProject = Project::where('organization_id', $orgId)->get()->map(function (Project $p) use ($entries) {
            $used = $entries->where('project_id', $p->id)->sum('minutes');
            $included = (float) (($p->scope['hours_included'] ?? 0));

            return [
                'project_id' => $p->id,
                'name' => $p->name,
                'included_hours' => $included,
                'used_hours' => round($used / 60, 2),
                'overage_hours' => max(0, round($used / 60 - $included, 2)),
            ];
        });

        return response()->json(['data' => $entries, 'usage' => $byProject]);
    }

    public function store(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'project_id' => 'nullable|uuid',
            'site_id' => 'nullable|uuid',
            'minutes' => 'required|integer|min:5|max:1440',
            'description' => 'nullable|string|max:240',
            'billable' => 'sometimes|boolean',
            'occurred_at' => 'nullable|date',
        ]);

        $entry = TimeEntry::create([
            'organization_id' => $orgId,
            'project_id' => $data['project_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'user_id' => $request->user()->id,
            'minutes' => $data['minutes'],
            'description' => $data['description'] ?? null,
            'billable' => $data['billable'] ?? true,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        return response()->json(['data' => $entry->load(['project:id,name', 'site:id,name'])], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        TimeEntry::where('organization_id', $orgId)->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}

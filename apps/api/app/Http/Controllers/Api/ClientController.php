<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => Client::where('organization_id', $orgId)->withCount('projects')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate($this->rules());

        $client = Client::create([...$data, 'organization_id' => $orgId]);

        return response()->json(['data' => $client], 201);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $client = Client::where('organization_id', $orgId)->with(['projects.sites'])->findOrFail($id);

        return response()->json(['data' => $client]);
    }

    public function update(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $client = Client::where('organization_id', $orgId)->findOrFail($id);
        $client->update($request->validate($this->rules(false)));

        return response()->json(['data' => $client->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $client = Client::where('organization_id', $orgId)->findOrFail($id);
        if ($client->projects()->exists()) {
            return response()->json(['message' => 'Kunde hat noch Projekte.'], 422);
        }
        $client->delete();

        return response()->json(['ok' => true]);
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:160',
            'email' => 'nullable|email',
            'company' => 'nullable|string|max:160',
            'address' => 'nullable|string',
            'vat_id' => 'nullable|string|max:64',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:4000',
            'contract_until' => 'nullable|date',
            'sla_response_hours' => 'nullable|integer|min:1|max:168',
        ];
    }
}

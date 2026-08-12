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
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'email' => 'nullable|email',
            'company' => 'nullable|string|max:160',
            'address' => 'nullable|string',
            'vat_id' => 'nullable|string|max:64',
        ]);

        $client = Client::create([...$data, 'organization_id' => $orgId]);

        return response()->json(['data' => $client], 201);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $client = Client::where('organization_id', $orgId)->with('projects')->findOrFail($id);

        return response()->json(['data' => $client]);
    }
}

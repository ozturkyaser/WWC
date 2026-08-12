<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\CatalogService;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request, CatalogService $catalog)
    {
        $orgId = $request->attributes->get('organization_id');
        $org = Organization::findOrFail($orgId);

        return response()->json([
            'data' => $org,
            'catalog' => [
                'hour_packages' => $catalog->hourPackages($org),
                'maintenance_tiers' => $catalog->maintenanceTiers($org),
            ],
        ]);
    }

    public function update(Request $request, CatalogService $catalog)
    {
        $orgId = $request->attributes->get('organization_id');
        $org = Organization::findOrFail($orgId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:160',
            'billing_profile' => 'sometimes|array',
            'patchstack_api_key' => 'nullable|string',
            'billing_day' => 'sometimes|integer|min:1|max:28',
            'hour_packages' => 'sometimes|array',
            'hour_packages.*.id' => 'nullable|string|max:64',
            'hour_packages.*.name' => 'required_with:hour_packages|string|max:120',
            'hour_packages.*.hours' => 'required_with:hour_packages|numeric|min:0|max:1000',
            'hour_packages.*.price_cents' => 'required_with:hour_packages|integer|min:0',
            'hour_packages.*.billing' => 'nullable|in:once,monthly',
            'hour_packages.*.active' => 'nullable|boolean',
            'hour_packages.*.description' => 'nullable|string|max:500',
            'maintenance_tiers' => 'sometimes|array',
        ]);

        if (isset($data['hour_packages'])) {
            $data['hour_packages'] = $catalog->sanitizeHourPackages($data['hour_packages']);
        }
        if (isset($data['maintenance_tiers'])) {
            $data['maintenance_tiers'] = $catalog->sanitizeMaintenanceTierOverrides($data['maintenance_tiers']);
        }

        $org->update($data);
        $org = $org->fresh();

        return response()->json([
            'data' => $org,
            'catalog' => [
                'hour_packages' => $catalog->hourPackages($org),
                'maintenance_tiers' => $catalog->maintenanceTiers($org),
            ],
        ]);
    }
}

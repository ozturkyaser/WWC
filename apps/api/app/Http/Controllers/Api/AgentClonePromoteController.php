<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\DevCloneService;
use Illuminate\Http\Request;

class AgentClonePromoteController extends Controller
{
    public function download(Request $request, DevCloneService $clones)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $path = $clones->promotePackagePath($site);
        if (! is_file($path)) {
            return response()->json(['message' => 'Promote-Paket nicht vorhanden'], 404);
        }

        return response()->download($path, 'wwc-promote.zip', [
            'Content-Type' => 'application/zip',
            'X-WWC-Promote-Sha256' => (string) hash_file('sha256', $path),
        ]);
    }
}

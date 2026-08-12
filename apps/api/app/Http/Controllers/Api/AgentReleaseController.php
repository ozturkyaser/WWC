<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PluginPackager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentReleaseController extends Controller
{
    /**
     * Public metadata for WordPress plugin self-update.
     */
    public function latest(PluginPackager $packager)
    {
        return response()->json($packager->releaseMeta());
    }

    /**
     * Public ZIP download used by WP Plugin_Upgrader (no auth).
     */
    public function download(PluginPackager $packager): BinaryFileResponse
    {
        $zip = $packager->buildZip();
        $version = $packager->version();

        return response()->download($zip, 'wwc-agent-'.$version.'.zip', [
            'Content-Type' => 'application/zip',
            'X-WWC-Agent-Version' => $version,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}

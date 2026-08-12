<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PluginPackager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PluginController extends Controller
{
    public function download(PluginPackager $packager): BinaryFileResponse
    {
        $zip = $packager->buildZip();

        return response()->download($zip, 'wwc-agent.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }
}

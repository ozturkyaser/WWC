<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\ContentStudioService;
use Illuminate\Http\Request;

class ContentStudioController extends Controller
{
    public function show(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);

        return response()->json(['data' => $studio->payload($site)]);
    }

    public function scan(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);
        $data = $studio->scan($site);
        AuditLogger::log('site.content.scan', $request->attributes->get('organization_id'), $request->user(), $site->id, [], $request);

        return response()->json(['data' => $data]);
    }

    public function plan(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);
        $data = $request->validate(['prompt' => 'required|string|max:4000']);
        try {
            $draft = $studio->plan($site, $data['prompt']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        AuditLogger::log('site.content.plan', $request->attributes->get('organization_id'), $request->user(), $site->id, [
            'prompt' => mb_substr($data['prompt'], 0, 200),
        ], $request);

        return response()->json(['data' => $studio->payload($site->fresh() ?? $site), 'draft' => $draft]);
    }

    public function applyDev(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);
        try {
            $data = $studio->applyDev($site);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        AuditLogger::log('site.content.apply_dev', $request->attributes->get('organization_id'), $request->user(), $site->id, [], $request);

        return response()->json(['data' => $data]);
    }

    public function promote(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);
        try {
            $data = $studio->promoteLive($site);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        AuditLogger::log('site.content.promote', $request->attributes->get('organization_id'), $request->user(), $site->id, [], $request);

        return response()->json(['data' => $data], 202);
    }

    public function upload(Request $request, string $id, ContentStudioService $studio)
    {
        $site = $this->site($request, $id);
        $request->validate(['file' => 'required|file|max:12288|mimes:jpg,jpeg,png,gif,webp,svg']);
        $stored = $studio->storeUpload($site, $request->file('file'));
        AuditLogger::log('site.content.upload', $request->attributes->get('organization_id'), $request->user(), $site->id, [
            'filename' => $stored['filename'],
        ], $request);

        return response()->json(['data' => $stored]);
    }

    private function site(Request $request, string $id): Site
    {
        $orgId = $request->attributes->get('organization_id');

        return Site::where('organization_id', $orgId)->findOrFail($id);
    }
}

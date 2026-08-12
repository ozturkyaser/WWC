<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Services\AgentClient;
use App\Services\HmacSigner;
use Closure;
use Illuminate\Http\Request;

class VerifyAgentHmac
{
    public function handle(Request $request, Closure $next)
    {
        $siteId = $request->header('X-WWC-Site-Id') ?? $request->input('site_id');
        $timestamp = $request->header('X-WWC-Timestamp');
        $nonce = $request->header('X-WWC-Nonce');
        $signature = $request->header('X-WWC-Signature');

        if (! $siteId || ! $timestamp || ! $nonce || ! $signature) {
            return response()->json(['message' => 'Missing HMAC headers.'], 401);
        }

        if (! HmacSigner::isTimestampFresh($timestamp, 300)) {
            return response()->json(['message' => 'Timestamp outside allowed window.'], 401);
        }

        if (! AgentClient::consumeNonce((string) $siteId, (string) $nonce)) {
            return response()->json(['message' => 'Nonce replay detected.'], 401);
        }

        $site = Site::find($siteId);
        if (! $site || ! $site->getHmacSecret()) {
            return response()->json(['message' => 'Unknown or unpaired site.'], 401);
        }

        $body = $request->getContent() ?? '';
        $paths = array_values(array_unique(array_filter([
            '/'.$request->path(),
            parse_url($request->getRequestUri(), PHP_URL_PATH) ?: null,
        ])));

        $secrets = array_filter([
            $site->getHmacSecret(),
            $site->getPreviousHmacSecret(),
        ]);

        $valid = false;
        foreach ($secrets as $secret) {
            foreach ($paths as $path) {
                if (HmacSigner::verify($request->method(), $path, $timestamp, $nonce, $body, $signature, $secret)) {
                    $valid = true;
                    break 2;
                }
            }
        }

        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $request->attributes->set('agent_site', $site);

        return $next($request);
    }
}

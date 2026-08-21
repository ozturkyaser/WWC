<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function info(Request $request)
    {
        $public = rtrim((string) config('wwc.public_api_url'), '/');
        $app = rtrim((string) config('app.url'), '/');
        $host = $request->getSchemeAndHttpHost();

        $candidates = array_values(array_unique(array_filter([
            $public,
            $host,
            $app,
            'http://192.168.1.30:8081',
            'http://172.17.0.1:8081',
            'http://host.docker.internal:8081',
        ])));

        return response()->json([
            'recommended_api_url' => $public !== '' ? $public : 'https://wwc.kiservicehub.de',
            'suggested_api_urls' => $candidates,
            'tips' => [
                'Kundenseiten: immer die Portal-URL verwenden, Standard https://wwc.kiservicehub.de',
                'localhost und LAN-IPs funktionieren nicht von einer öffentlichen WordPress-Seite aus',
            ],
        ]);
    }
}

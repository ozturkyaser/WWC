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
            'recommended_api_url' => $public,
            'suggested_api_urls' => $candidates,
            'tips' => [
                'Online-WordPress braucht eine öffentlich erreichbare API (HTTPS). Lokal: ./scripts/start-tunnel.sh',
                'Produktion: API + Portal auf VPS/Cloud hosten und WWC_PUBLIC_API_URL setzen',
                'Lokales WordPress (LocalWP/VM): LAN-IP möglich, z. B. http://192.168.1.30:8081',
                'localhost funktioniert nicht von einer Online-Website aus',
            ],
        ]);
    }
}

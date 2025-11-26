<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $allowedOrigins = [
            'http://localhost:3000',
        ];

        $origin = $request->headers->get('Origin');
        $allowedOrigin = in_array($origin, $allowedOrigins) ? $origin : null;

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'true',
            'Vary' => 'Origin',
        ];

        if ($allowedOrigin) {
            $headers['Access-Control-Allow-Origin'] = $allowedOrigin;
        }

        // Preflight request
        if ($request->isMethod('OPTIONS')) {
            return response()->json([], 200, $headers);
        }

        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}

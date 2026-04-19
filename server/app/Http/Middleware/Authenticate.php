<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Missing API token.'], 401);
        }

        $client = Client::findByToken($token);

        if (! $client) {
            return response()->json(['message' => 'Invalid API token.'], 401);
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }
}
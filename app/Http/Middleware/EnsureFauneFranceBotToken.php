<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFauneFranceBotToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('biodiversity.faune_france_bot_token');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['message' => 'Jeton du bot Faune-France invalide.'], 401);
        }

        return $next($request);
    }
}

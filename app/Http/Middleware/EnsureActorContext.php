<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActorContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$allowedContexts): Response
    {
        $actorContext = $request->attributes->get('actor_context');

        if (! is_string($actorContext) || ! in_array($actorContext, $allowedContexts, true)) {
            return response()->json([
                'success' => false,
                'code' => 'ACTOR_CONTEXT_FORBIDDEN',
                'message' => 'La credencial actual no permite acceder a este contexto.',
            ], 403);
        }

        return $next($request);
    }
}

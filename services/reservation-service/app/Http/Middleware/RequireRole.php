<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Security\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** À placer après AuthenticateKeycloak : role:INTERNAL, role:CLIENT,AGENT,ADMIN (un des rôles suffit). */
final class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $principal = $request->attributes->get(Principal::class);
        if (! $principal instanceof Principal) {
            throw ApiException::unauthorized();
        }
        if (! $principal->hasAnyRole(...$roles)) {
            throw ApiException::forbidden('Rôle requis : '.implode(' ou ', $roles));
        }

        return $next($request);
    }
}

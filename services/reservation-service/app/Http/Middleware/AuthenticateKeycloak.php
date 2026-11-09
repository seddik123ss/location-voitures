<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Security\KeycloakTokenDecoder;
use App\Security\Principal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Exige un access token Keycloak valide (401 sinon) et range l'appelant dans la requête. */
final class AuthenticateKeycloak
{
    public function __construct(private readonly KeycloakTokenDecoder $decoder)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('Authorization');
        if ($header === null || trim($header) === '') {
            throw ApiException::unauthorized('Token absent : en-tête Authorization: Bearer <token> attendu');
        }
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw ApiException::invalidToken('En-tête Authorization attendu : Bearer <token>');
        }

        $principal = $this->decoder->decode($m[1]);
        $request->attributes->set(Principal::class, $principal);
        app()->instance(Principal::class, $principal);

        return $next($request);
    }
}

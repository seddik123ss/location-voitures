<?php

namespace App\Security;

use App\Http\ApiResponse;
use App\Security\Jwt\InvalidToken;
use App\Security\Jwt\KeycloakTokenDecoder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authorization: Bearer <access token Keycloak>. Produit un ApiUser : id = sub, rôles = claim "roles"
 * réduite aux rôles du realm connus (CLIENT, AGENT, ADMIN, INTERNAL ; les rôles par défaut de Keycloak sont ignorés).
 * Sans en-tête Authorization, l'authenticator ne s'applique pas : l'entry point répond 401 sur les routes protégées.
 * Les routes PUBLIC_ACCESS de access_control (/health, /api/doc) ne vérifient pas le token, même invalide :
 * le firewall est stateless, donc jamais lazy, et l'authenticator s'écarte lui-même.
 */
final class JwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly KeycloakTokenDecoder $decoder,
        #[Autowire(service: 'security.access_map')] private readonly AccessMapInterface $accessMap,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!$request->headers->has('Authorization')) {
            return false;
        }
        [$attributes] = $this->accessMap->getPatterns($request);

        return $attributes !== [AuthenticatedVoter::PUBLIC_ACCESS];
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new InvalidToken('En-tête Authorization attendu : Bearer <token>');
        }
        $claims = $this->decoder->decode($m[1]);

        $id = strtolower($claims['sub']);
        $roles = is_array($claims['roles'] ?? null) ? array_values(array_intersect(ApiUser::REALM_ROLES, $claims['roles'])) : [];

        return new SelfValidatingPassport(new UserBadge($id, static fn () => new ApiUser($id, $roles)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof InvalidToken ? $exception->getMessageKey() : 'Token invalide';

        return ApiResponse::error(401, $message, [], ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}

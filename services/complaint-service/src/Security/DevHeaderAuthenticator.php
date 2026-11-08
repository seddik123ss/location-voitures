<?php

namespace App\Security;

use App\Http\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;

/**
 * DEV UNIQUEMENT (activé sous when@dev dans config/packages/security.yaml).
 * X-Dev-User-Id: <uuid>      X-Dev-Roles: CLIENT | AGENT | ADMIN (séparés par des virgules, CLIENT par défaut)
 * Sera remplacé par l'authenticator JWT Keycloak, qui produira le même ApiUser.
 */
final class DevHeaderAuthenticator extends AbstractAuthenticator
{
    public const HEADER_USER = 'X-Dev-User-Id';
    public const HEADER_ROLES = 'X-Dev-Roles';

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER_USER);
    }

    public function authenticate(Request $request): Passport
    {
        $id = trim((string) $request->headers->get(self::HEADER_USER));
        if (!Uuid::isValid($id)) {
            throw new CustomUserMessageAuthenticationException(sprintf('%s doit être un UUID', self::HEADER_USER));
        }

        $roles = array_values(array_unique(array_filter(array_map(
            static fn (string $r) => strtoupper(trim($r)),
            explode(',', (string) $request->headers->get(self::HEADER_ROLES, 'CLIENT')),
        ))));
        $unknown = array_diff($roles, ApiUser::REALM_ROLES);
        if ($roles === [] || $unknown !== []) {
            throw new CustomUserMessageAuthenticationException(sprintf('%s invalide : rôles possibles %s', self::HEADER_ROLES, implode(', ', ApiUser::REALM_ROLES)));
        }

        return new SelfValidatingPassport(new UserBadge($id, static fn () => new ApiUser($id, $roles)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return ApiResponse::error(401, strtr($exception->getMessageKey(), $exception->getMessageData()));
    }
}

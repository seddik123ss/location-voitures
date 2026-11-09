<?php

namespace App\Security\Jwt;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/** Token mal formé, mal signé, expiré, d'un autre émetteur... : 401 avec ce message. */
final class InvalidToken extends CustomUserMessageAuthenticationException
{
}

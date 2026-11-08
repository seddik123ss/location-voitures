<?php

namespace App\Enum;

/** Rôle Keycloak de l'auteur d'un message ou d'un changement de statut. */
enum AuthorRole: string
{
    case CLIENT = 'CLIENT';
    case AGENT = 'AGENT';
    case ADMIN = 'ADMIN';
}

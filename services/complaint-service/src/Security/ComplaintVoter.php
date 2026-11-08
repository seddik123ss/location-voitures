<?php

namespace App\Security;

use App\Entity\Complaint;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui peut faire quoi sur une réclamation (403). Les règles d'état (409) sont dans ComplaintService.
 *
 * @extends Voter<string, Complaint>
 */
final class ComplaintVoter extends Voter
{
    public const VIEW = 'COMPLAINT_VIEW';
    public const EDIT = 'COMPLAINT_EDIT';
    public const DELETE = 'COMPLAINT_DELETE';
    public const CHANGE_STATUS = 'COMPLAINT_CHANGE_STATUS';
    public const ADD_MESSAGE = 'COMPLAINT_ADD_MESSAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Complaint
            && in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::CHANGE_STATUS, self::ADD_MESSAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof ApiUser) {
            return false;
        }
        $isOwner = $user->hasRole('CLIENT') && $user->getId() === $subject->getUserId();

        return match ($attribute) {
            self::VIEW, self::ADD_MESSAGE => $isOwner || $user->isStaff(),
            self::EDIT => $isOwner,
            self::DELETE => $isOwner || $user->isAdmin(),
            self::CHANGE_STATUS => $user->isStaff(),
            default => false,
        };
    }
}

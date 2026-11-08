<?php

namespace App\Enum;

enum ComplaintStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
    case REJECTED = 'REJECTED';

    /**
     * Transitions autorisées :
     * OPEN -> IN_PROGRESS | REJECTED
     * IN_PROGRESS -> RESOLVED | REJECTED | OPEN
     * RESOLVED et REJECTED : états finaux (toute transition -> 409).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::IN_PROGRESS, self::REJECTED],
            self::IN_PROGRESS => [self::RESOLVED, self::REJECTED, self::OPEN],
            self::RESOLVED, self::REJECTED => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Household\Entity\Household;
use App\Notification\Entity\NotificationLog;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Household|Habit|HabitLog|NotificationLog>
 */
final class HouseholdVoter extends Voter
{
    public const string VIEW = 'HOUSEHOLD_VIEW';

    public const string EDIT = 'HOUSEHOLD_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (! \in_array($attribute, [self::VIEW, self::EDIT], true)) {
            return false;
        }

        return $subject instanceof Household
            || $subject instanceof Habit
            || $subject instanceof HabitLog
            || $subject instanceof NotificationLog;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (! $user instanceof User) {
            return false;
        }

        return match (true) {
            $subject instanceof Household => $user->getHousehold()->getId()->equals($subject->getId()),
            $subject instanceof Habit => $user->getHousehold()->getId()->equals($subject->getHousehold()->getId()),
            $subject instanceof HabitLog => $user->getHousehold()->getId()->equals($subject->getHabit()->getHousehold()->getId()),
            $subject instanceof NotificationLog => $user->getId()->equals($subject->getUser()->getId()),
            default => false,
        };
    }
}

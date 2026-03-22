<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Security;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Enum\HabitFrequency;
use App\Household\Entity\Household;
use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Shared\Security\HouseholdVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(HouseholdVoter::class)]
final class HouseholdVoterTest extends TestCase
{
    private HouseholdVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new HouseholdVoter();
    }

    public function testSameHouseholdGrantsAccessForHousehold(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $household, [HouseholdVoter::VIEW]),
        );
    }

    public function testSameHouseholdGrantsAccessForHabit(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $habit = $this->createHabit($household);
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $habit, [HouseholdVoter::EDIT]),
        );
    }

    public function testSameHouseholdGrantsAccessForHabitLog(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $habit = $this->createHabit($household);
        $habitLog = new HabitLog(
            habit: $habit,
            user: $user,
            loggedAt: new \DateTimeImmutable(),
        );
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $habitLog, [HouseholdVoter::VIEW]),
        );
    }

    public function testSameUserGrantsAccessForNotificationLog(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $habit = $this->createHabit($household);
        $notificationLog = new NotificationLog(
            user: $user,
            channel: NotificationChannel::WEB_PUSH,
            status: NotificationStatus::SENT,
            sentAt: new \DateTimeImmutable(),
            message: 'Test notification',
            habit: $habit,
        );
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, $notificationLog, [HouseholdVoter::VIEW]),
        );
    }

    public function testDifferentHouseholdDeniesAccessForHousehold(): void
    {
        $userHousehold = $this->createHousehold('User Household');
        $otherHousehold = $this->createHousehold('Other Household');
        $user = $this->createUser($userHousehold);
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $otherHousehold, [HouseholdVoter::VIEW]),
        );
    }

    public function testDifferentHouseholdDeniesAccessForHabit(): void
    {
        $userHousehold = $this->createHousehold('User Household');
        $otherHousehold = $this->createHousehold('Other Household');
        $user = $this->createUser($userHousehold);
        $habit = $this->createHabit($otherHousehold);
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $habit, [HouseholdVoter::EDIT]),
        );
    }

    public function testDifferentHouseholdDeniesAccessForHabitLog(): void
    {
        $userHousehold = $this->createHousehold('User Household');
        $otherHousehold = $this->createHousehold('Other Household');
        $user = $this->createUser($userHousehold);
        $otherUser = $this->createUser($otherHousehold, 'other@example.com');
        $habit = $this->createHabit($otherHousehold);
        $habitLog = new HabitLog(
            habit: $habit,
            user: $otherUser,
            loggedAt: new \DateTimeImmutable(),
        );
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $habitLog, [HouseholdVoter::VIEW]),
        );
    }

    public function testDifferentUserDeniesAccessForNotificationLog(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $otherUser = $this->createUser($household, 'other@example.com');
        $notificationLog = new NotificationLog(
            user: $otherUser,
            channel: NotificationChannel::WEB_PUSH,
            status: NotificationStatus::SENT,
            sentAt: new \DateTimeImmutable(),
            message: 'Test notification',
        );
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $notificationLog, [HouseholdVoter::VIEW]),
        );
    }

    public function testNonUserTokenDeniesAccess(): void
    {
        $household = $this->createHousehold();
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, $household, [HouseholdVoter::VIEW]),
        );
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        $household = $this->createHousehold();
        $user = $this->createUser($household);
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, $household, ['SOME_OTHER_ATTRIBUTE']),
        );
    }

    public function testUnsupportedSubjectAbstains(): void
    {
        $user = $this->createUser($this->createHousehold());
        $token = $this->createTokenWithUser($user);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, new \stdClass(), [HouseholdVoter::VIEW]),
        );
    }

    private function createHousehold(string $name = 'Test'): Household
    {
        return new Household($name);
    }

    private function createUser(Household $household, string $email = 'test@example.com'): User
    {
        return new User(
            household: $household,
            email: $email,
            password: 'hashed',
            displayName: 'Test User',
            timezone: 'Europe/Berlin',
        );
    }

    private function createHabit(Household $household): Habit
    {
        return new Habit(
            household: $household,
            name: 'Test Habit',
            frequency: HabitFrequency::DAILY,
        );
    }

    private function createTokenWithUser(User $user): TokenInterface
    {
        $token = self::createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}

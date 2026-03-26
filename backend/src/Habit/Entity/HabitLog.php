<?php

declare(strict_types=1);

namespace App\Habit\Entity;

use App\Auth\Entity\User;
use App\Habit\Enum\HabitLogSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'habit_log')]
final class HabitLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Habit::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly Habit $habit,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly User $user,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private readonly \DateTimeImmutable $loggedAt,
        #[ORM\Column(enumType: HabitLogSource::class)]
        private HabitLogSource $source = HabitLogSource::MANUAL,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $note = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = \Carbon\CarbonImmutable::now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHabit(): Habit
    {
        return $this->habit;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLoggedAt(): \DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function getSource(): HabitLogSource
    {
        return $this->source;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }
}

<?php

declare(strict_types=1);

namespace App\Habit\Entity;

use App\Habit\Enum\HabitFrequency;
use App\Habit\Enum\TimeWindowMode;
use App\Household\Entity\Household;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'habit')]
final class Habit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Household::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly Household $household,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $name,
        #[ORM\Column(enumType: HabitFrequency::class)]
        private HabitFrequency $frequency,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $description = null,
        #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
        private ?string $icon = null,
        #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
        private ?string $color = null,
        #[ORM\Column(type: Types::INTEGER, options: [
            'default' => 0,
        ])]
        private int $sortOrder = 0,
        #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $timeWindowStart = null,
        #[ORM\Column(type: Types::TIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $timeWindowEnd = null,
        #[ORM\Column(enumType: TimeWindowMode::class, options: [
            'default' => 'manual',
        ])]
        private TimeWindowMode $timeWindowMode = TimeWindowMode::MANUAL,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = \Carbon\CarbonImmutable::now();
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getFrequency(): HabitFrequency
    {
        return $this->frequency;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function getTimeWindowStart(): ?\DateTimeImmutable
    {
        return $this->timeWindowStart;
    }

    public function getTimeWindowEnd(): ?\DateTimeImmutable
    {
        return $this->timeWindowEnd;
    }

    public function getTimeWindowMode(): TimeWindowMode
    {
        return $this->timeWindowMode;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setFrequency(HabitFrequency $frequency): void
    {
        $this->frequency = $frequency;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setColor(?string $color): void
    {
        $this->color = $color;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setTimeWindow(
        ?\DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        TimeWindowMode $mode = TimeWindowMode::MANUAL,
    ): void {
        $this->timeWindowStart = $start;
        $this->timeWindowEnd = $end;
        $this->timeWindowMode = $mode;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setTimeWindowStart(?\DateTimeImmutable $start): void
    {
        $this->timeWindowStart = $start;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setTimeWindowEnd(?\DateTimeImmutable $end): void
    {
        $this->timeWindowEnd = $end;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function setTimeWindowMode(TimeWindowMode $mode): void
    {
        $this->timeWindowMode = $mode;
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }

    public function softDelete(): void
    {
        $this->deletedAt = \Carbon\CarbonImmutable::now();
        $this->updatedAt = \Carbon\CarbonImmutable::now();
    }
}

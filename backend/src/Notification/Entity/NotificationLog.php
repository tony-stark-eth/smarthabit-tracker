<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notification_log')]
final class NotificationLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private readonly User $user,
        #[ORM\Column(enumType: NotificationChannel::class)]
        private readonly NotificationChannel $channel,
        #[ORM\Column(enumType: NotificationStatus::class)]
        private NotificationStatus $status,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private readonly \DateTimeImmutable $sentAt,
        #[ORM\Column(type: Types::TEXT)]
        private readonly string $message,
        #[ORM\ManyToOne(targetEntity: Habit::class)]
        #[ORM\JoinColumn(nullable: true)]
        private readonly ?Habit $habit = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getHabit(): ?Habit
    {
        return $this->habit;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setStatus(NotificationStatus $status): void
    {
        $this->status = $status;
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Household\Entity\Household;
use App\Shared\Contract\HouseholdAwareUserInterface;
use App\Shared\Enum\Locale;
use App\Shared\Enum\Theme;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: '"user"')]
final class User implements HouseholdAwareUserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pushSubscriptions = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $consentVersion = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Household::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Household $household,
        #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
        private string $email,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $password,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $displayName,
        #[ORM\Column(type: Types::STRING, length: 50)]
        private string $timezone = 'Europe/Berlin',
        #[ORM\Column(enumType: Locale::class)]
        private Locale $locale = Locale::DE,
        #[ORM\Column(enumType: Theme::class)]
        private Theme $theme = Theme::AUTO,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getTheme(): Theme
    {
        return $this->theme;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getPushSubscriptions(): ?array
    {
        return $this->pushSubscriptions;
    }

    public function getConsentAt(): ?\DateTimeImmutable
    {
        return $this->consentAt;
    }

    public function getConsentVersion(): ?string
    {
        return $this->consentVersion;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
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

    public function setHousehold(Household $household): void
    {
        $this->household = $household;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setLocale(Locale $locale): void
    {
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setTheme(Theme $theme): void
    {
        $this->theme = $theme;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param array<int, array<string, mixed>>|null $pushSubscriptions
     */
    public function setPushSubscriptions(?array $pushSubscriptions): void
    {
        $this->pushSubscriptions = $pushSubscriptions;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function giveConsent(string $version): void
    {
        $this->consentAt = new \DateTimeImmutable();
        $this->consentVersion = $version;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markEmailVerified(): void
    {
        $this->emailVerifiedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        assert($this->email !== '');

        return $this->email;
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Household\Entity\Household;
use Symfony\Component\Security\Core\User\UserInterface;

interface HouseholdAwareUserInterface extends UserInterface
{
    public function getHousehold(): Household;

    public function getTimezone(): string;
}

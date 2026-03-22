<?php

declare(strict_types=1);

namespace App\Habit\Repository;

use App\Habit\Entity\Habit;
use App\Household\Entity\Household;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HabitRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Habit>
     */
    public function findActiveByHousehold(Household $household): array
    {
        return $this->em->createQueryBuilder()
            ->select('h')
            ->from(Habit::class, 'h')
            ->where('h.household = :household')
            ->andWhere('h.deletedAt IS NULL')
            ->orderBy('h.sortOrder', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()
            ->getResult();
    }
}

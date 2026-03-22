<?php

declare(strict_types=1);

namespace App\Notification\Command;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cleanup-push-subscriptions', description: 'Remove push subscriptions not seen in 30+ days')]
final class CleanupPushSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findAll();
        $removed = 0;

        $cutoff = new \DateTimeImmutable('-30 days');

        foreach ($users as $user) {
            $removed += $this->cleanupUser($user, $cutoff);
        }

        $this->em->flush();
        $output->writeln(\sprintf('Removed %d stale subscriptions', $removed));

        return Command::SUCCESS;
    }

    private function cleanupUser(User $user, \DateTimeImmutable $cutoff): int
    {
        $subscriptions = $user->getPushSubscriptions();
        if ($subscriptions === null || $subscriptions === []) {
            return 0;
        }

        $originalCount = count($subscriptions);
        $filtered = $this->filterFreshSubscriptions($subscriptions, $cutoff);
        $removedCount = $originalCount - count($filtered);

        if ($removedCount > 0) {
            $user->setPushSubscriptions($filtered !== [] ? $filtered : null);
        }

        return $removedCount;
    }

    /**
     * @param array<mixed> $subscriptions
     *
     * @return list<array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}>
     */
    private function filterFreshSubscriptions(array $subscriptions, \DateTimeImmutable $cutoff): array
    {
        /** @var list<array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}> $typed */
        $typed = array_values($subscriptions);

        return array_values(array_filter($typed, static function (array $sub) use ($cutoff): bool {
            $lastSeen = $sub['last_seen'];
            if ($lastSeen === '') {
                return true; // keep if no meaningful last_seen value
            }

            return new \DateTimeImmutable($lastSeen) > $cutoff;
        }));
    }
}

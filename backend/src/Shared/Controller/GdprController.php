<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Notification\Entity\NotificationLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GdprController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/v1/user/export', name: 'api_user_export', methods: ['GET'])]
    public function export(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $household = $user->getHousehold();

        $habits = $this->entityManager->getRepository(Habit::class)->findBy([
            'household' => $household,
        ]);

        $habitLogs = $this->entityManager->getRepository(HabitLog::class)->findBy([
            'user' => $user,
        ]);

        $notificationLogs = $this->entityManager->getRepository(NotificationLog::class)->findBy([
            'user' => $user,
        ]);

        $data = [
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'display_name' => $user->getDisplayName(),
                'timezone' => $user->getTimezone(),
                'locale' => $user->getLocale()->value,
                'theme' => $user->getTheme()->value,
                'consent_at' => $user->getConsentAt()?->format(\DateTimeInterface::ATOM),
                'consent_version' => $user->getConsentVersion(),
                'email_verified_at' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
                'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updated_at' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'household' => [
                'id' => $household->getId()->toRfc4122(),
                'name' => $household->getName(),
                'invite_code' => $household->getInviteCode(),
            ],
            'habits' => array_map(static fn (Habit $habit): array => [
                'id' => $habit->getId()->toRfc4122(),
                'name' => $habit->getName(),
                'description' => $habit->getDescription(),
                'frequency' => $habit->getFrequency()->value,
                'icon' => $habit->getIcon(),
                'color' => $habit->getColor(),
                'sort_order' => $habit->getSortOrder(),
                'time_window_start' => $habit->getTimeWindowStart()?->format('H:i'),
                'time_window_end' => $habit->getTimeWindowEnd()?->format('H:i'),
                'time_window_mode' => $habit->getTimeWindowMode()->value,
                'created_at' => $habit->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updated_at' => $habit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ], $habits),
            'habit_logs' => array_map(static fn (HabitLog $log): array => [
                'id' => $log->getId()->toRfc4122(),
                'habit_id' => $log->getHabit()->getId()->toRfc4122(),
                'logged_at' => $log->getLoggedAt()->format(\DateTimeInterface::ATOM),
                'note' => $log->getNote(),
                'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $habitLogs),
            'notification_logs' => array_map(static fn (NotificationLog $log): array => [
                'id' => $log->getId()->toRfc4122(),
                'channel' => $log->getChannel()->value,
                'status' => $log->getStatus()->value,
                'sent_at' => $log->getSentAt()->format(\DateTimeInterface::ATOM),
                'message' => $log->getMessage(),
                'habit_id' => $log->getHabit()?->getId()->toRfc4122(),
            ], $notificationLogs),
            'exported_at' => \Carbon\CarbonImmutable::now()->format(\DateTimeInterface::ATOM),
        ];

        $response = new JsonResponse($data);
        $response->headers->set('Content-Disposition', 'attachment; filename="smarthabit-export.json"');

        return $response;
    }

    #[Route('/api/v1/user/me', name: 'api_user_me_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $household = $user->getHousehold();

        $notificationLogs = $this->entityManager->getRepository(NotificationLog::class)->findBy([
            'user' => $user,
        ]);

        foreach ($notificationLogs as $notificationLog) {
            $this->entityManager->remove($notificationLog);
        }

        $habitLogs = $this->entityManager->getRepository(HabitLog::class)->findBy([
            'user' => $user,
        ]);

        foreach ($habitLogs as $habitLog) {
            $this->entityManager->remove($habitLog);
        }

        $householdMembers = $this->entityManager->getRepository(User::class)->findBy([
            'household' => $household,
        ]);

        if (\count($householdMembers) === 1) {
            $habits = $this->entityManager->getRepository(Habit::class)->findBy([
                'household' => $household,
            ]);

            foreach ($habits as $habit) {
                $this->entityManager->remove($habit);
            }

            $this->entityManager->remove($household);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => $this->translator->trans('gdpr.delete.success'),
        ]);
    }

    #[Route('/api/v1/privacy', name: 'api_privacy', methods: ['GET'])]
    public function privacy(): JsonResponse
    {
        return new JsonResponse([
            'version' => '1.0',
            'effective_date' => '2026-03-22',
            'text' => 'Privacy policy placeholder. Full text will be added before launch.',
        ]);
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->translator->trans('auth.unauthorized'),
            ],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}

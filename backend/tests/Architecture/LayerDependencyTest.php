<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerDependencyTest
{
    /**
     * Services must not depend on controllers
     */
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\*\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\*\Controller'));
    }

    /**
     * Auth domain must not depend on Habit domain
     */
    public function testAuthDoesNotDependOnHabit(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Auth'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Habit'));
    }

    /**
     * Habit services must not depend on Auth (entity cross-refs OK)
     */
    public function testHabitServicesDoNotDependOnAuth(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Habit\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Auth'));
    }

    /**
     * Notification services must not depend on Auth (entity cross-refs OK)
     */
    public function testNotificationServicesDoNotDependOnAuth(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Notification\Service'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Auth'));
    }

    /**
     * Stats domain must not depend on Auth domain
     */
    public function testStatsDoesNotDependOnAuth(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Stats'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Auth'));
    }

    /**
     * Auth must not depend on Notification
     */
    public function testAuthDoesNotDependOnNotification(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Auth'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Notification'));
    }

    /**
     * Habit must not depend on Notification
     */
    public function testHabitDoesNotDependOnNotification(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Habit'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Notification'));
    }

    /**
     * Household must not depend on Notification
     */
    public function testHouseholdDoesNotDependOnNotification(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Household'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\Notification'));
    }

    /**
     * Entities must not depend on controllers
     */
    public function testEntitiesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\*\Entity'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('App\*\Controller'));
    }
}

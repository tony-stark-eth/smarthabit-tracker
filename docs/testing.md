# Testing & Code Quality
## Testing & Code Quality

Quality is not a phase-4 feature. Tooling is in place from commit 1, CI blocks on violations.

### Composer Dev Dependencies

```
phpunit/phpunit: ^13.0
infection/infection: ^0.32
phpstan/phpstan: ^2.1
phpstan/phpstan-symfony: *
phpstan/phpstan-doctrine: *
phpstan/phpstan-phpunit: *
phpstan/phpstan-strict-rules: *
phpstan/phpstan-deprecation-rules: *
phpstan/extension-installer: *
shipmonk/phpstan-rules: *
voku/phpstan-rules: ^3.6
tomasvotruba/cognitive-complexity: ^1.0
tomasvotruba/type-coverage: ^2.1
phpat/phpat: ^0.12
rector/rector: *
symplify/easy-coding-standard: *
symfony/browser-kit: ^8.0
symfony/css-selector: ^8.0
symfony/phpunit-bridge: ^8.0
doctrine/doctrine-fixtures-bundle: ^4.0
zenstruck/foundry: ^2.0
captainhook/captainhook: ^5.0
captainhook/plugin-composer: ^5.0
captainhook/plugin-composer: ^5.0
```

### PHPUnit 13 — Path Coverage + Sealed Test Doubles

PHPUnit 13 (Feb 2026, requires PHP 8.4+) with Xdebug for Path Coverage. FrankenPHP uses ZTS (Zend Thread Safety) PHP, which is incompatible with PCOV (NTS-only). Coverage therefore uses Xdebug with `XDEBUG_MODE=coverage`. Key changes in v13:

- **Sealed Test Doubles**: `$mock->seal()` prevents further configuration after setup — enforces complete setup before the test-act phase
- **`createStub()` vs `createMock()`**: v13 enforces the distinction. Use `createMock()` only when invocation verification is needed (with `expects()`), otherwise use `createStub()`
- **`withParameterSetsInOrder()` / `withParameterSetsInAnyOrder()`**: Replacement for the long-removed `withConsecutive()`

Configuration in `phpunit.xml.dist`:

```xml
<phpunit
    colors="true"
    failOnRisky="true"
    failOnWarning="true"
    executionOrder="random"
>
    <coverage pathCoverage="true">
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <exclude>
            <directory>src/Entity</directory>    <!-- Entities are DTOs -->
            <directory>src/Kernel.php</directory>
        </exclude>
        <report>
            <html outputDirectory="var/coverage"/>
            <clover outputFile="var/coverage/clover.xml"/>
        </report>
    </coverage>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Coverage runs via Xdebug with `XDEBUG_MODE=coverage` in the FrankenPHP dev stage. PCOV cannot be used because FrankenPHP ships ZTS PHP and PCOV only supports NTS (Non-Thread-Safe) builds.

### Unit Tests

Focus on domain logic, no IO. Everything that makes decisions gets unit-tested:

**TimeWindowChecker** — the core logic for whether a habit is currently within its time window. Test cases: inside window, outside, exact boundary, midnight overlap (23:00-01:00), various timezones, DST transitions.

**TimeWindowLearner** — MAD algorithm. Test cases: normal dataset, insufficient data (<7 logs), outlier robustness, weekday/weekend split, minimum window width 30min.

**PushSubscriptionManager** — Add subscription (web_push, ntfy, apns), remove, duplicate check, cleanup for stale subscriptions, mixing different subscription types.

**NotifyHandler** — Multi-transport dispatch logic. Test cases: user with mixed subscription types, error handling per transport (410 Gone → remove, 429 → retry), fallback when one transport fails while others work. Stubs for WebPushService, NtfyClient, ApnsTransport.

**HabitCompletionChecker** — "Was it already completed today?" with timezone awareness. Test cases: log from yesterday 23:59 UTC that is "today" in user timezone, and vice versa.

**InviteCodeGenerator** — Uniqueness, format, collision handling.

**HouseholdIsolationVoter** — Security voter that checks whether a user may access resources of their household. Test cases: own household → allowed, other household → denied, no household → denied, admin override (if needed later).

**AccountDeletionService** — Deletion cascade. Test cases: user with logs → everything deleted, last user in household → household cascade, user with push subscriptions → all removed, user with NotificationLogs → all removed.

**DataExportService** — GDPR export. Test cases: export contains all user data, export contains all logs, export does not contain data from other users, export format is valid JSON.

**RateLimiterConfig** — Not directly unit-testable, but the configuration is verified in integration tests.

Each unit test:
- No DB, no filesystem, no HTTP
- `createStub()` instead of `createMock()` where no invocation verification is needed (PHPUnit 13 enforces this)
- Real mocks only for external services (Web Push, ntfy, APNs) — with `seal()` for strict configuration
- `#[CoversClass(...)]` attribute on every test class
- Data providers for edge cases instead of duplicated tests

### Integration Tests

Test the collaboration of real components with a real PostgreSQL instance (Docker in CI):

**Repository Tests** — Doctrine repositories with real queries. Especially important for the dashboard query (JOIN across Habits + Logs + Users), the time window check (PostgreSQL TIME comparisons), and the deletion cascade (GDPR account deletion).

**API Tests** — Symfony `WebTestCase` with real HTTP requests against the app:
- Auth flow: Register → verification email → login → JWT → protected endpoint
- Password reset: Forgot → token email → reset → old JWT invalid
- CRUD operations + validation (invalid timezone, missing required fields, i18n error messages)
- Household isolation: User A cannot see habits from Household B (security voter)
- Rate limiting: 6th login attempt → 429 Too Many Requests
- GDPR: `GET /export` returns complete data, `DELETE /user/me` cascade-deletes everything
- Push subscription CRUD: register, update, delete for all three types

**Messenger Tests** — Does CheckHabitsCommand produce the correct messages? Does NotifyHandler dispatch to the correct users? Are push errors handled correctly (subscription removed on 410, retry on 429)? Mock only the push clients themselves (WebPush, ntfy HTTP, APNs), everything else real.

**Mercure Tests** — Is a Mercure update published when a log is created? Does the update contain the correct data? Is the topic household-scoped?

**Email Tests** — Symfony Mailer has built-in test tooling (`assertEmailCount()`, `assertEmailSent()`). Tests: verification email after registration, reset email after forgot, correct language based on user locale, links in emails are valid.

**Cleanup Command Tests** — `app:cleanup-push-subscriptions` removes stale subscriptions, keeps active ones. `app:cleanup-old-logs` anonymizes/deletes logs after the retention period.

Test DB is set up via `doctrine:schema:create` before each suite and reset after each test via transaction rollback (Symfony `ResetDatabase` trait or manual `beginTransaction`/`rollBack`).

### Mutation Testing — Infection

Infection runs exclusively against the unit test suite (not integration). Running it against integration tests would be too slow and would cause timeouts. Configuration in `infection.json5`:

```json5
{
    "$schema": "vendor/infection/infection/resources/schema.json",
    "source": {
        "directories": ["src"],
        "excludes": [
            "Entity",
            "Kernel.php",
            "Controller",     // Controller logic is thin, tested via integration
            "Command"         // Commands are thin, tested via integration
        ]
    },
    "logs": {
        "text": "var/infection/infection.log",
        "html": "var/infection/infection.html",
        "summary": "var/infection/summary.log"
    },
    "mutators": {
        "@default": true
    },
    "phpUnit": {
        "configDir": ".",
        "customPath": "vendor/bin/phpunit"
    },
    "testFramework": "phpunit",
    "testFrameworkOptions": "--testsuite=unit",
    "minMsi": 80,
    "minCoveredMsi": 90
}
```

Target values: MSI >= 80%, Covered Code MSI >= 90%. Initially the threshold may be lower, but it will be raised with each phase. Escaped mutants are logged in CI and discussed in PR reviews.

### PHPStan — Level max + Enforced Metrics

PHPStan at level max from day 1, plus 10 extensions that go far beyond what PHPStan checks natively. All extensions use `phpstan/extension-installer` and register themselves automatically. Configuration in `phpstan.neon`:

```neon
parameters:
    level: max
    paths:
        - src
        - tests
    symfony:
        containerXmlPath: var/cache/dev/App_KernelDevDebugContainer.xml
    doctrine:
        objectManagerLoader: tests/object-manager.php
    ignoreErrors: []    # keep empty — fix errors, don't ignore them

    # ── Bleeding Edge ──────────────────────────────────────────────
    # Preview of the next major version. Stricter analysis, better
    # type inference, fewer false positives. Worth it on greenfield.
    treatPhpDocTypesAsCertain: false
    checkUninitializedProperties: true
    checkBenevolentUnionTypes: true
    checkImplicitMixed: true
    checkTooWideReturnTypesInProtectedAndPublicMethods: true
    reportPossiblyNonexistentGeneralArrayOffset: true
    reportPossiblyNonexistentConstantArrayOffset: true
    reportAnyTypeWideningInVarTag: true

    # ── Cognitive Complexity (tomasvotruba/cognitive-complexity) ───
    # Measures the mental effort when reading — not Cyclomatic Complexity!
    # Every nesting level, every break in linear flow increases the score
    cognitive_complexity:
        function: 8       # Max 8 per method — enforces early returns and small methods
        class: 50         # Max 50 per class — enforces SRP

    # ── Type Coverage (tomasvotruba/type-coverage) ────────────────
    # Measures what percentage of properties, parameters and returns are typed
    type_coverage:
        return: 100       # 100% — every method has a return type
        param: 100        # 100% — every parameter has a type
        property: 100     # 100% — every property has a type
        constant: 100     # 100% — every constant has a type (PHP 8.3+)
        declare: 100      # 100% — every file has declare(strict_types=1)

    # ── ShipMonk Rules (shipmonk/phpstan-rules) ───────────────────
    # ~40 extra-strict rules. Everything enabled, selectively disabled where
    # it doesn't fit our stack or conflicts with other rules.
    shipmonkRules:
        # Naming Conventions — we enforce this via phpat instead
        classSuffixNaming:
            superclassToSuffixMapping!:
                \Exception: Exception
                \PHPUnit\Framework\TestCase: Test
                \Symfony\Component\Console\Command\Command: Command

        # Readonly Properties — redundant, we use `final readonly class`
        enforceReadonlyPublicProperty:
            enabled: true

        # Enum Safety — no default in match over enums
        forbidMatchDefaultArmForEnums:
            enabled: true

        # Useless Nullable Returns — when null is never returned
        forbidUselessNullableReturn:
            enabled: true

        # Forgotten Exception Throws — exception created but never thrown
        forbidUnusedException:
            enabled: true

        # Custom Bans — debug functions and DateTime (mutable)
        forbidCustomFunctions:
            list:
                'var_dump': 'Remove debug code'
                'dump': 'Remove debug code'
                'dd': 'Remove debug code'
                'print_r': 'Remove debug code'
                'DateTime::__construct': 'Use DateTimeImmutable'

    # ── voku/phpstan-rules ────────────────────────────────────────
    # Automatically active via extension-installer. Checks:
    # - Type compatibility for operators (+, -, *, /, .)
    # - Assignments in conditions (if ($x = foo()) instead of if ($x === foo()))
    # - Null safety for method calls
    # No extra configuration needed — defaults are sensible.

# ── Architecture Tests (phpat/phpat) ──────────────────────────
# Rules that enforce the domain folder structure and dependency direction
services:
    - class: Tests\Architecture\DomainIsolationTest
      tags: [phpat.test]
    - class: Tests\Architecture\LayerDependencyTest
      tags: [phpat.test]
    - class: Tests\Architecture\NamingConventionTest
      tags: [phpat.test]
```

#### What the Extensions Cover — Overview

| Extension | Version | What it checks | Config needed? |
|---|---|---|---|
| `phpstan-strict-rules` | * | Strict type comparisons (`===`), no `empty()`, no dynamic method calls, strict `in_array()` etc. | No (auto) |
| `phpstan-deprecation-rules` | * | Usage of deprecated code (PHP, Symfony, Doctrine) — important for major upgrades | No (auto) |
| `shipmonk/phpstan-rules` | * | ~40 rules: enum safety, forgotten exceptions, nullable returns, custom bans, comparison safety | Yes (see above) |
| `voku/phpstan-rules` | ^3.6 | Operator type compatibility, assignment-in-condition, null safety | No (auto) |
| `tomasvotruba/cognitive-complexity` | ^1.0 | Cognitive complexity per method (max 8) and class (max 50) | Yes (see above) |
| `tomasvotruba/type-coverage` | ^2.1 | Type declaration coverage: return, param, property, constant, declare | Yes (see above) |
| `phpat/phpat` | ^0.12 | Architecture rules: domain isolation, layer dependencies, naming | Yes (services) |
| `phpstan-symfony` | * | Container-aware: correct service types, route parameters, Twig | No (auto) |
| `phpstan-doctrine` | * | Entity mapping, repository returns, query builder | No (auto) |
| `phpstan-phpunit` | * | Mock type inference, TestCase methods, assert types | No (auto) |

**Why so many?** Each package covers a different gap. PHPStan level max + strict-rules checks types and logic. ShipMonk catches enum errors, forgotten exceptions, and unsafe casts. voku checks operator compatibility. Cognitive complexity and type coverage enforce metrics. phpat enforces architecture. Deprecation rules warn early about API changes in Symfony 8.x or PHP 8.4. The combination is the point — individually each package is optional, together they close almost all gaps that manual code review would otherwise need to catch.

**Cognitive Complexity 8 per method** — that is strict, but doable. For comparison: Sonar's default is 15. Our value of 8 enforces early returns, small methods, and no nested conditionals. When a method exceeds the limit, it is a clear signal to extract.

**Type Coverage 100%** — we are starting on a new project with PHP 8.4, there is no legacy code. 100% from day 1 is the right moment — it never gets easier later.

**ShipMonk `forbidCustomFunctions`** — especially important: `DateTime::__construct` is banned, only `DateTimeImmutable`. And `var_dump`/`dump`/`dd` cannot be accidentally committed.

### Architecture Tests (phpat)

PHPat enforces dependency rules as a PHPStan extension — no separate tests needed, runs in the same CI step.

```php
// tests/Architecture/DomainIsolationTest.php
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class DomainIsolationTest
{
    // Domain services must not depend on Symfony (no framework coupling)
    public function testDomainDoesNotDependOnFramework(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Habit\Service'))
            ->andClasses(Selector::inNamespace('App\Notification\Service'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
            );
    }

    // Entities have no dependency on services
    public function testEntitiesAreIsolated(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\*\Entity'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\*\Service'),
                Selector::inNamespace('App\*\Repository'),
            );
    }
}

// tests/Architecture/LayerDependencyTest.php
final class LayerDependencyTest
{
    // Household must not depend on Notification (dependency direction)
    public function testHouseholdDoesNotDependOnNotification(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Household'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\Notification'));
    }

    // Controllers depend on services, not the other way around
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\*\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\*\Controller'));
    }
}

// tests/Architecture/NamingConventionTest.php
final class NamingConventionTest
{
    // All exceptions must live in an Exception namespace
    public function testExceptionsInCorrectNamespace(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::extends(\RuntimeException::class))
            ->shouldBeInNamespace('App\*\Exception');
    }
}
```

No `ignoreErrors` as a dumping ground. If PHPStan flags something, it gets fixed, or it gets a `@phpstan-ignore` with an explanation directly in the code.

### Rector — Automatic Code Modernization

Rector keeps the code at PHP 8.4 / Symfony 8 level. Runs in CI as a check (`--dry-run`), locally as auto-fix. Configuration in `rector.php`:

```php
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        SymfonySetList::SYMFONY_80,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        DoctrineSetList::DOCTRINE_ORM_214,
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        PHPUnitSetList::PHPUNIT_130,
    ]);
```

### Easy Coding Standard (ECS)

ECS instead of PHP-CS-Fixer or PHP_CodeSniffer — one config, both tools. Configuration in `ecs.php`:

```php
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPreparedSets(
        psr12: true,
        common: true,
        strict: true,
        cleanCode: true,
    );
```

### CI Pipeline (GitHub Actions or GitLab CI)

Every push / PR goes through:

```
1. ECS Check          — Coding standard (fastest check first)
2. PHPStan            — Static analysis
3. Rector --dry-run   — Outdated patterns?
4. PHPUnit Unit       — with path coverage
5. PHPUnit Integration — against PostgreSQL (service container in CI)
6. Infection           — Mutation testing against unit suite
```

Steps 1-3 run in parallel (no DB needed). Steps 4-6 run sequentially (coverage → mutation needs coverage data). CI fails on: ECS violations, PHPStan errors, Rector diff, test failures, path coverage < 80%, MSI < 80%.

### Makefile / Composer Scripts

Local shortcuts so nobody needs to remember the long commands:

```makefile
quality:        ## All checks locally
	@make ecs phpstan rector-check test infection

ecs:            ## Coding standard (fix)
	vendor/bin/ecs check --fix

ecs-check:      ## Coding standard (check only)
	vendor/bin/ecs check

phpstan:        ## Static analysis
	vendor/bin/phpstan analyse

rector:         ## Rector auto-fix
	vendor/bin/rector process

rector-check:   ## Rector dry-run
	vendor/bin/rector process --dry-run

test:           ## PHPUnit (all suites)
	vendor/bin/phpunit

test-unit:      ## Unit tests only with coverage
	vendor/bin/phpunit --testsuite=unit --coverage-html=var/coverage

test-integration: ## Integration tests only
	vendor/bin/phpunit --testsuite=integration

infection:      ## Mutation testing
	vendor/bin/infection --threads=4 --show-mutations

infection-fast: ## Infection only for changed files (CI optimization)
	vendor/bin/infection --threads=4 --git-diff-filter=AM --git-diff-base=origin/main
```

### Test Directory Structure

```
tests/
├── Unit/
│   ├── Service/
│   │   ├── TimeWindowCheckerTest.php
│   │   ├── TimeWindowLearnerTest.php
│   │   ├── PushSubscriptionManagerTest.php
│   │   ├── NotifyHandlerTest.php
│   │   ├── HabitCompletionCheckerTest.php
│   │   ├── AccountDeletionServiceTest.php
│   │   ├── DataExportServiceTest.php
│   │   └── HouseholdIsolationVoterTest.php
│   ├── Util/
│   │   └── InviteCodeGeneratorTest.php
│   └── ValueObject/
│       └── TimeWindowTest.php
├── Integration/
│   ├── Repository/
│   │   ├── HabitRepositoryTest.php
│   │   ├── HabitLogRepositoryTest.php
│   │   └── NotificationLogRepositoryTest.php
│   ├── Controller/
│   │   ├── AuthControllerTest.php
│   │   ├── PasswordResetControllerTest.php
│   │   ├── HabitControllerTest.php
│   │   ├── DashboardControllerTest.php
│   │   ├── LogControllerTest.php
│   │   ├── PushSubscriptionControllerTest.php
│   │   ├── UserExportControllerTest.php
│   │   ├── UserDeletionControllerTest.php
│   │   └── RateLimitingTest.php
│   ├── Messenger/
│   │   ├── CheckHabitsHandlerTest.php
│   │   └── NotifyHabitHandlerTest.php
│   ├── Mercure/
│   │   └── HabitLogMercurePublishTest.php
│   ├── Email/
│   │   ├── VerificationEmailTest.php
│   │   └── PasswordResetEmailTest.php
│   └── Command/
│       ├── CleanupPushSubscriptionsCommandTest.php
│       └── CleanupOldLogsCommandTest.php
├── Factory/              — Object Mothers / Factories for test data
│   ├── HabitFactory.php
│   ├── UserFactory.php
│   └── HouseholdFactory.php
├── Architecture/         — phpat architecture tests (run via PHPStan, not PHPUnit)
│   ├── DomainIsolationTest.php
│   ├── LayerDependencyTest.php
│   └── NamingConventionTest.php
└── bootstrap.php
```

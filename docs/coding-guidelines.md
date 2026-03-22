# Coding Guidelines

These guidelines apply to all PHP projects. They supplement the automated tools (PHPStan, Rector, ECS, Infection) with things no tool can check: architecture decisions, naming, structure, defensive patterns.

## Core Principles

**KISS** — The simplest solution that solves the problem. No abstraction without a concrete second use case.

**YAGNI** — No code for features that might come someday. If you write an interface with only one implementation "because we might later..." → delete the interface.

**DRY** — But: duplication is cheaper than the wrong abstraction. Two similar methods are better than one bent into shape with flags for both cases.

**SOLID** — Not as an academic ideal, but as a refactoring compass. If a class is hard to test, it probably violates SRP or DIP.

**Minimal & Defensive** — Every method validates its inputs. Every return has an explicit type. No implicit behavior, no "it just works like that".

---

## PHP Conventions

### Strict Types — always, everywhere

```php
<?php

declare(strict_types=1);
```

First line, every file, no exceptions. PHP without strict_types is a different language.

### Value Objects for Domain Concepts

Primitive obsession is the most common mistake. A time window is not an array with two strings.

```php
// ❌ Primitive Obsession
function isInWindow(string $start, string $end, string $timezone): bool

// ✅ Value Object
final readonly class TimeWindow
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException('Start must be before end.');
        }
    }

    public function contains(\DateTimeImmutable $time): bool
    {
        return $time >= $this->start && $time <= $this->end;
    }
}
```

Good candidates for value objects: email addresses, invite codes, timezones, time windows, UUIDs, monetary amounts, coordinates. Rule: if you need to validate a primitive value before using it, it needs a value object.

### Immutability by Default

`readonly` on every class and property that does not need to be mutated. PHP 8.4 makes this even more powerful with property hooks.

```php
// ✅ Immutable by default
final readonly class HabitCreatedEvent
{
    public function __construct(
        public Uuid $habitId,
        public Uuid $householdId,
        public \DateTimeImmutable $createdAt,
    ) {}
}
```

- `DateTimeImmutable` instead of `DateTime` — always, everywhere.
- `readonly class` as default. Only `class` when mutation is explicitly needed.
- Arrays that should not grow → document the return type, not `array` but specific collection types.

### Early Returns — no else chains

```php
// ❌ Nested, hard to read
function canNotify(User $user, Habit $habit): bool
{
    if ($user->isVerified()) {
        if ($habit->isActive()) {
            if (!$this->wasNotifiedToday($user, $habit)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    } else {
        return false;
    }
}

// ✅ Early Returns — flat, readable
function canNotify(User $user, Habit $habit): bool
{
    if (!$user->isVerified()) {
        return false;
    }

    if (!$habit->isActive()) {
        return false;
    }

    if ($this->wasNotifiedToday($user, $habit)) {
        return false;
    }

    return true;
}
```

Rule: maximum nesting depth of 2. If you write a third `if` inside an `if` inside an `if`, refactor.

### Composition over Inheritance

```php
// ❌ Inheritance chain
abstract class AbstractNotifier
{
    abstract protected function send(): void;
    // 200 lines of shared logic
}

class WebPushNotifier extends AbstractNotifier { ... }
class NtfyNotifier extends AbstractNotifier { ... }
class ApnsNotifier extends AbstractNotifier { ... }

// ✅ Interface + Composition
interface PushTransport
{
    public function send(PushSubscription $subscription, Notification $notification): PushResult;
    public function supports(PushSubscription $subscription): bool;
}

final readonly class WebPushTransport implements PushTransport { ... }
final readonly class NtfyTransport implements PushTransport { ... }
final readonly class ApnsTransport implements PushTransport { ... }

// Strategy Pattern — choose the right transport at runtime
final readonly class NotificationDispatcher
{
    /** @param iterable<PushTransport> $transports */
    public function __construct(
        private iterable $transports,
    ) {}

    public function dispatch(PushSubscription $sub, Notification $notification): PushResult
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($sub)) {
                return $transport->send($sub, $notification);
            }
        }

        throw new UnsupportedTransportException($sub->type);
    }
}
```

Symfony's tagged services make this trivial — `#[AutoconfigureTag('app.push_transport')]` and dependency injection handles the rest.

### Design Patterns — when to use which

| Pattern | When to use | Example in this project |
|---|---|---|
| **Strategy** | Multiple algorithms fulfilling the same interface | Push transports (WebPush, ntfy, APNs) |
| **Factory** | Object creation with logic/validation | `PushSubscriptionFactory::fromRequest()` |
| **Repository** | Abstract database access | Doctrine repositories, custom query methods |
| **Value Object** | Primitives with meaning and validation | `TimeWindow`, `InviteCode`, `Email` |
| **DTO** | Transport data between layers | Request/response DTOs for API |
| **Observer/Event** | Decoupled reaction to actions | Symfony events (HabitLogged → Mercure update) |
| **Decorator** | Extend behavior without modifying the class | Wrapping a service with logging |

Do not use until needed: Abstract Factory, Builder, Mediator, Singleton (never), Service Locator (never).

### Dependency Injection — Constructor only

```php
// ❌ Setter Injection — incomplete objects possible
class NotifyHandler
{
    private WebPushService $webPush;

    public function setWebPush(WebPushService $webPush): void
    {
        $this->webPush = $webPush;
    }
}

// ❌ Service Locator — hidden dependencies
class NotifyHandler
{
    public function handle(): void
    {
        $webPush = $this->container->get(WebPushService::class);
    }
}

// ✅ Constructor Injection — dependencies are explicit and enforced
final readonly class NotifyHandler
{
    public function __construct(
        private WebPushService $webPush,
        private NtfyClient $ntfy,
        private LoggerInterface $logger,
    ) {}
}
```

### Methods — short and focused

- **Maximum 20 lines** per method. If longer → extract private methods.
- **Maximum 3 parameters**. More → DTO or value object.
- **Cognitive complexity max 8** per method — enforced via `tomasvotruba/cognitive-complexity` in PHPStan. Every nesting level, every `break`, every `continue`, every `&&`/`||` in conditionals increases the score. Cognitive complexity measures the mental effort when reading, not the number of paths (that would be cyclomatic complexity).
- **One abstraction level** per method. Do not mix business logic and SQL in the same method.
- **Method names are verbs**: `findActiveHabits()`, `markAsCompleted()`, `dispatchNotification()`. Not `habits()`, `completed()`, `notification()`.

### Classes — small and focused

- **Maximum ~150 lines** per class (excluding imports/docblocks). If more → class has too many responsibilities.
- **Cognitive complexity max 50** per class — enforced via PHPStan.
- **Maximum 5 dependencies** in the constructor. More → class does too much. Extract a service.
- **`final` by default**. Every class is `final` until extension is proven necessary. Prevents the fragile base class problem.
- **`readonly` by default**. Every class is `final readonly` until mutation is explicitly needed.

### No Magic Values

```php
// ❌ Magic numbers and strings
if ($retryCount > 3) { ... }
if ($status === 'sent') { ... }
$token = substr($random, 0, 32);

// ✅ Named constants and enums
private const int MAX_RETRY_COUNT = 3;
private const int TOKEN_LENGTH = 32;

enum NotificationStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Clicked = 'clicked';
}
```

### Error Handling — explicit, never swallowed

```php
// ❌ Exception swallowed
try {
    $this->webPush->send($subscription, $payload);
} catch (\Exception) {
    // do nothing
}

// ❌ Generic catch-all
try {
    $this->webPush->send($subscription, $payload);
} catch (\Exception $e) {
    throw new \RuntimeException('Push failed');
}

// ✅ Catch specifically, log, throw domain exception
try {
    $this->webPush->send($subscription, $payload);
} catch (ExpiredSubscriptionException $e) {
    $this->subscriptionManager->remove($subscription);
    $this->logger->info('Removed expired subscription', ['endpoint' => $subscription->endpoint]);
} catch (RateLimitException $e) {
    throw PushDeliveryException::rateLimited($subscription, $e);
} catch (TransportException $e) {
    $this->logger->error('Push delivery failed', [
        'endpoint' => $subscription->endpoint,
        'error' => $e->getMessage(),
    ]);
    throw PushDeliveryException::transportError($subscription, $e);
}
```

Custom exceptions with named constructors are more readable than `new PushDeliveryException('message', 0, $previous)`.

### Return Types — always explicit

```php
// ❌ Implicit return
function findUser(Uuid $id)  // what comes back? User? null? array?

// ✅ Explicit
function findUser(Uuid $id): ?User
function getUser(Uuid $id): User  // throws exception if not found
function findUsersByHousehold(Uuid $householdId): array  // @return User[]
```

Convention: `find*` may return `null`, `get*` throws if not found.

---

## Svelte/TypeScript Conventions

### TypeScript strict

```json
// tsconfig.json
{
  "compilerOptions": {
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "noImplicitReturns": true
  }
}
```

### No `any` — never

```typescript
// ❌
function parseResponse(data: any): Habit { ... }

// ✅
interface HabitResponse {
  id: string;
  name: string;
  emoji: string;
  // ...
}
function parseResponse(data: HabitResponse): Habit { ... }
```

### Svelte 5 Runes instead of legacy

```svelte
<!-- ❌ Legacy -->
<script>
  let count = 0;
  $: doubled = count * 2;
</script>

<!-- ✅ Svelte 5 Runes -->
<script>
  let count = $state(0);
  let doubled = $derived(count * 2);
</script>
```

### Components — small and props-driven

- Maximum ~100 lines per component (template + script + style).
- Props with defaults and types. No `$$props` or `$$restProps`.
- No business logic in components — extract into `$lib/` modules.
- No direct `fetch` in components — use the API wrapper from `$lib/api/`.

---

## General Rules

### Git Commits

- Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `docs:`, `chore:`.
- One commit = one logical change. No "fix everything" commits.
- Commit message describes **why**, not **what**. The diff shows what changed.

### Folder Structure Follows Domain, Not Framework

```
src/
├── Habit/                  — Everything related to habits
│   ├── Entity/
│   ├── Repository/
│   ├── Service/
│   ├── Event/
│   └── Exception/
├── Household/              — Household + invite logic
├── Notification/           — Push dispatch, all transports
├── Auth/                   — Registration, login, JWT, password reset
├── Stats/                  — Statistics + analytics
└── Shared/                 — Value objects, interfaces, utils that are cross-domain
```

Not: `src/Entity/`, `src/Service/`, `src/Controller/` as flat folders with 30 files. That does not scale and reveals nothing about the domain.

**Enforced via phpat** — Architecture tests in `tests/Architecture/` are automatically checked in CI: domain services must not depend on Symfony/Doctrine, entities must not depend on services, no layer may reach upward (Service → Controller). See `docs/testing.md` for the concrete phpat rules.

---

## Automated Enforcement Matrix

These guidelines are not recommendations — they are automatically enforced in CI:

| Rule | Tool | Threshold | CI blocks? |
|---|---|---|---|
| Cognitive complexity per method | `tomasvotruba/cognitive-complexity` | max 8 | Yes |
| Cognitive complexity per class | `tomasvotruba/cognitive-complexity` | max 50 | Yes |
| Type coverage (return, param, property) | `tomasvotruba/type-coverage` | 100% | Yes |
| `declare(strict_types=1)` coverage | `tomasvotruba/type-coverage` | 100% | Yes |
| Domain isolation (no framework deps) | `phpat/phpat` | Architecture test | Yes |
| Layer dependencies (no upward deps) | `phpat/phpat` | Architecture test | Yes |
| Naming conventions | `phpat/phpat` + `shipmonk/phpstan-rules` | Architecture test | Yes |
| Strict type comparisons (`===`, no `empty`) | `phpstan-strict-rules` | strict | Yes |
| Deprecated code usage | `phpstan-deprecation-rules` | 0 deprecations | Yes |
| Enum safety, forgotten exceptions, casts | `shipmonk/phpstan-rules` | ~40 rules | Yes |
| Operator type compatibility | `voku/phpstan-rules` | strict | Yes |
| Debug functions banned | `shipmonk/phpstan-rules` (`forbidCustomFunctions`) | 0 | Yes |
| `DateTime` banned (only Immutable) | `shipmonk/phpstan-rules` (`forbidCustomFunctions`) | 0 | Yes |
| Uninitialized properties | PHPStan (`checkUninitializedProperties`) | strict | Yes |
| Implicit mixed | PHPStan (`checkImplicitMixed`) | strict | Yes |
| PHPStan level | `phpstan/phpstan` | max | Yes |
| Coding standard | `symplify/easy-coding-standard` | PSR-12 + strict | Yes |
| Code modernity | `rector/rector` | PHP 8.4 + Symfony 8 | Yes (dry-run) |
| Path coverage | PHPUnit 13 + Xdebug | >= 80% | Yes |
| Mutation score (MSI) | `infection/infection` | >= 80% | Yes |
| Covered code MSI | `infection/infection` | >= 90% | Yes |

Everything in this table blocks the merge if not met. No "we'll fix it later".

### Code Review Checklist

Before code is merged — mentally go through:

1. **Does it do what it should?** — Not "does the code look good", but: does it solve the problem?
2. **Is a test missing?** — Every new path needs a test. Every exception needs a test.
3. **Can I still understand it in 6 months?** — Clever code is bad code.
4. **Is the simplest solution chosen?** — Is there a way with less code?
5. **Are edge cases handled?** — null, empty arrays, timezone boundaries, race conditions.
6. **Is the dependency direction correct?** — Domain does not depend on infrastructure.
7. **Is error handling specific?** — No catch-all, no swallowed exceptions.
8. **Are all strings externalized?** — Hardcoded strings visible to the user → i18n.

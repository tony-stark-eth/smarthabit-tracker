# Backend — Config Files & Starter Code

#### `composer.json` — require-dev

```json
{
    "require": {
        "php": ">=8.4",
        "symfony/framework-bundle": "^8.0",
        "symfony/runtime": "^8.0",
        "doctrine/orm": "^3.6",
        "doctrine/doctrine-bundle": "^2.13",
        "doctrine/doctrine-migrations-bundle": "^3.4",
        "symfony/messenger": "^8.0",
        "symfony/serializer": "^8.0",
        "symfony/validator": "^8.0",
        "symfony/mailer": "^8.0",
        "symfony/rate-limiter": "^8.0",
        "symfony/mercure-bundle": "^0.3",
        "lexik/jwt-authentication-bundle": "^3.1",
        "symfony/translation": "^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0",
        "infection/infection": "^0.32",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-symfony": "*",
        "phpstan/phpstan-doctrine": "*",
        "phpstan/phpstan-phpunit": "*",
        "phpstan/phpstan-strict-rules": "*",
        "phpstan/phpstan-deprecation-rules": "*",
        "phpstan/extension-installer": "*",
        "shipmonk/phpstan-rules": "*",
        "voku/phpstan-rules": "^3.6",
        "tomasvotruba/cognitive-complexity": "^1.0",
        "tomasvotruba/type-coverage": "^2.1",
        "phpat/phpat": "^0.12",
        "rector/rector": "*",
        "symplify/easy-coding-standard": "*",
        "symfony/browser-kit": "^8.0",
        "symfony/css-selector": "^8.0",
        "symfony/phpunit-bridge": "^8.0",
        "doctrine/doctrine-fixtures-bundle": "^4.0",
        "zenstruck/foundry": "^2.0",
        "captainhook/captainhook": "^5.0",
        "captainhook/plugin-composer": "^5.0"
    }
}
```

> **Note**: `runtime/frankenphp-symfony` has been removed — it is not needed and breaks Symfony 8.

#### `phpstan.neon`

Full configuration as documented in `docs/testing.md`. Includes:

- Level max + Bleeding Edge Flags (`checkUninitializedProperties`, `checkImplicitMixed`, `checkBenevolentUnionTypes`, `reportPossiblyNonexistentGeneralArrayOffset`, `reportPossiblyNonexistentConstantArrayOffset`, `reportAnyTypeWideningInVarTag`)
- Cognitive Complexity: function 8, class 50
- Type Coverage: return 100, param 100, property 100, constant 100, declare 100
- ShipMonk Rules: `forbidCustomFunctions` (var_dump, dump, dd, print_r, DateTime::__construct), `enforceReadonlyPublicProperty`, `forbidMatchDefaultArmForEnums`, `forbidUselessNullableReturn`, `forbidUnusedException`, `classSuffixNaming` (Exception, Test, Command)
- voku/phpstan-rules: auto (no config needed)
- phpat services: `Tests\Architecture\LayerDependencyTest`, `Tests\Architecture\NamingConventionTest`
- Symfony + Doctrine Extensions

#### `rector.php`

```php
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

#### `ecs.php`

```php
return ECSConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withPreparedSets(
        psr12: true,
        common: true,
        strict: true,
        cleanCode: true,
    );
```

#### `phpunit.xml.dist`

- Path Coverage via Xdebug with `XDEBUG_MODE=coverage` (`pathCoverage="true"`) — FrankenPHP uses ZTS (Zend Thread Safety), and PCOV only supports NTS (Non-Thread Safe) builds
- Two suites: `unit` (tests/Unit), `integration` (tests/Integration)
- `<env>`: `APP_ENV=test`, `DATABASE_URL` pointing to app_test DB (directly to database, not pgbouncer)
- `<coverage>`: `<include><directory>src</directory></include>`
- `failOnWarning="true"`, `failOnRisky="true"`, `beStrictAboutTestsThatDoNotTestAnything="true"`

#### `infection.json5`

```json5
{
    "$schema": "vendor/infection/infection/resources/schema.json",
    "source": { "directories": ["src"] },
    "logs": {
        "text": "var/infection/infection.log",
        "html": "var/infection/infection.html"
    },
    "tmpDir": "var/infection/tmp",
    "phpUnit": { "configDir": "." },
    "testFramework": "phpunit",
    "testFrameworkOptions": "--testsuite=unit",
    "mutators": { "@default": true },
    "minMsi": 80,
    "minCoveredMsi": 90
}
```

## Backend — Starter Code

#### `src/Shared/Controller/HealthController.php`

Two endpoints that work immediately and drive the CI smoke test:

- `GET /api/v1/health` — returns `{"status": "ok", "timestamp": "..."}`. Checks nothing, just shows that the container is running.
- `GET /api/v1/health/ready` — checks the DB connection (`SELECT 1`). Returns 503 if the DB is unreachable. Useful for Docker healthchecks and load balancers.

#### `tests/Integration/Controller/HealthControllerTest.php`

Tests both endpoints. Proves that PHPUnit + integration tests + real PostgreSQL work in CI. This single test validates the entire toolchain.

#### `tests/Architecture/LayerDependencyTest.php`

Generic rule: `App\*\Service` must not depend on `App\*\Controller`. Applies to any project.

#### `tests/Architecture/NamingConventionTest.php`

Generic rule: anything that extends `RuntimeException` must live in an `Exception` namespace.

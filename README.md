# PHP DB Locker

![cog-php-db-locker](https://user-images.githubusercontent.com/1849174/167773585-171bef35-8e6d-461c-b1b1-ad9d2b07290a.png)

<p align="center">
    <a href="https://github.com/cybercog/php-db-locker/releases"><img src="https://img.shields.io/github/release/cybercog/php-db-locker.svg?style=flat-square" alt="Releases"></a>
    <a href="https://github.com/cybercog/php-db-locker/blob/master/LICENSE"><img src="https://img.shields.io/github/license/cybercog/php-db-locker.svg?style=flat-square" alt="License"></a>
</p>

## Introduction

> WARNING! This library is currently under development and may not be stable. Use in your services at your own risk.

PHP application-level database locking mechanisms to implement concurrency control patterns.

Supported drivers:

- Postgres — [PostgreSQL Advisory Locks Documentation](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS)

## Installation

Pull in the package through [Composer](https://getcomposer.org/).

```shell
composer require cybercog/php-db-locker
```

## Usage

### Postgres

#### Transaction-level advisory lock

```php
$dbConnection = new PDO($dsn, $username, $password);

$locker = new \Cog\DbLocker\Postgres\PostgresAdvisoryLocker();
$lockId = \Cog\DbLocker\Postgres\PostgresLockId::fromKeyValue('user', '4');

$dbConnection->beginTransaction();
$lock = $locker->acquireSessionLevelLockHandler(
    $dbConnection,
    $lockId,
    \Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum::NonBlocking,
    \Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum::Exclusive,
);
if ($lock->wasAcquired) {
    // Execute logic if lock was successful
} else {
    // Execute logic if lock acquisition has been failed
}
$dbConnection->commit();
```

#### Session-level advisory lock

```php
$dbConnection = new PDO($dsn, $username, $password);

$locker = new \Cog\DbLocker\Postgres\PostgresAdvisoryLocker();
$lockId = \Cog\DbLocker\Postgres\PostgresLockId::fromKeyValue('user', '4');

try {
    $lock = $locker->acquireSessionLevelLockHandler(
        $dbConnection,
        $lockId,
        \Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum::NonBlocking,
        \Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum::Exclusive,
    );
    if ($lock->wasAcquired) {
        // Execute logic if lock was successful
    } else {
        // Execute logic if lock acquisition has been failed
    }
} finally {
    $lock->release();
}
```

## Changelog

Detailed changes for each release are documented in the [CHANGELOG.md](https://github.com/cybercog/php-db-locker/blob/master/CHANGELOG.md).

## License

- `PHP DB Locker` package is open-sourced software licensed under the [MIT license](LICENSE) by [Anton Komarev].

## 🌟 Stargazers over time

[![Stargazers over time](https://chart.yhype.me/github/repository-star/v1/490362626.svg)](https://yhype.me?utm_source=github&utm_medium=cybercog-php-db-locker&utm_content=chart-repository-star-cumulative)

## About CyberCog

[CyberCog] is a Social Unity of enthusiasts. Research the best solutions in product & software development is our passion.

- [Follow us on Twitter](https://twitter.com/cybercog)

<a href="https://cybercog.su"><img src="https://cloud.githubusercontent.com/assets/1849174/18418932/e9edb390-7860-11e6-8a43-aa3fad524664.png" alt="CyberCog"></a>

[Anton Komarev]: https://komarev.com
[CyberCog]: https://cybercog.su

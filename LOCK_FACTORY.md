# PostgresLockFactory - Руководство по использованию

## Обзор

`PostgresLockFactory` - это фабрика для создания объектов `PostgresLock`, которая решает проблему использования адаптеров подключений к БД с `PostgresAdvisoryLocker`. Вместо передачи подключения в каждый метод локера, фабрика создает объект блокировки, который инкапсулирует в себе подключение и ключ блокировки.

## Основные преимущества

- **Чистый API**: Не нужно передавать подключение в каждый метод
- **Универсальность**: Работает с PDO, Doctrine DBAL, Laravel Eloquent
- **Инкапсуляция**: Все параметры блокировки связаны с объектом
- **Безопасность**: Невозможно случайно использовать неправильное подключение
- **Простота тестирования**: Легко мокать и тестировать

## Создание локов

### Создание по неймспейсу и ID

```php
use Cog\DbLocker\Postgres\PostgresLockFactory;

// С PDO
$pdo = new PDO('pgsql:host=localhost;dbname=test', 'user', 'password');
$lock = PostgresLockFactory::create($pdo, 'payment_processing', 123);

// С Doctrine DBAL
$adapter = new DoctrineDbConnectionAdapter($doctrineConnection);
$lock = PostgresLockFactory::create($adapter, 'inventory_sync', 456);

// С Laravel Eloquent
$adapter = new EloquentDbConnectionAdapter(DB::connection());
$lock = PostgresLockFactory::create($adapter, 'user_registration', 789);
```

### Создание по строковому ключу

```php
// Создание лока по строковому имени
$lock = PostgresLockFactory::createFromString($pdo, 'critical-section-name');
$lock = PostgresLockFactory::createFromString($adapter, 'another-lock');
```

## Использование локов

### Транзакционные локи (рекомендуется)

```php
$pdo->beginTransaction();

try {
    if ($lock->acquireTransactionLevel()) {
        // Выполняем критическую операцию
        processPayment($userId);
        $pdo->commit();
    } else {
        $pdo->rollback();
        throw new RuntimeException('Не удалось захватить лок');
    }
} catch (Exception $e) {
    $pdo->rollback();
    throw $e;
}
```

### Сессионные локи с автоматическим освобождением

```php
$result = $lock->withinSessionLock(function($lockHandle) {
    if (!$lockHandle->wasAcquired) {
        throw new RuntimeException('Лок не захвачен');
    }
    
    // Выполняем критическую операцию
    return performOperation();
});
// Лок автоматически освобождается
```

### Ручное управление сессионными локами

```php
$lockHandle = $lock->acquireSessionLevel();

if ($lockHandle->wasAcquired) {
    try {
        // Выполняем операцию
        performOperation();
    } finally {
        $lock->release();
        // или $lockHandle->release();
    }
}
```

## Практические примеры

### Обработка платежей

```php
class PaymentService
{
    public function processPayment(int $userId, $connection): void
    {
        $lock = PostgresLockFactory::create($connection, 'payment_processing', $userId);
        
        $connection->beginTransaction();
        try {
            if (!$lock->acquireTransactionLevel()) {
                throw new PaymentException('Платеж уже обрабатывается');
            }
            
            $this->executePayment($userId);
            $connection->commit();
        } catch (Exception $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
```

### Синхронизация инвентаря

```php
class InventoryService
{
    public function updateStock(int $productId, int $quantity, $connection): void
    {
        $lock = PostgresLockFactory::create($connection, 'inventory_sync', $productId);
        
        $result = $lock->withinSessionLock(function($lockHandle) use ($productId, $quantity) {
            if (!$lockHandle->wasAcquired) {
                throw new InventoryException("Товар {$productId} заблокирован");
            }
            
            return $this->performStockUpdate($productId, $quantity);
        });
        
        return $result;
    }
}
```

### Универсальный критический сервис

```php
class CriticalSectionService
{
    public function execute($connection, string $namespace, int $id, callable $operation): mixed
    {
        $lock = PostgresLockFactory::create($connection, $namespace, $id);
        
        return $lock->withinSessionLock(function($lockHandle) use ($operation) {
            if (!$lockHandle->wasAcquired) {
                throw new RuntimeException('Критическая секция заблокирована');
            }
            
            return $operation();
        });
    }
}

// Использование
$service = new CriticalSectionService();

// С любым типом подключения
$result = $service->execute($pdo, 'user_action', 123, fn() => updateUser(123));
$result = $service->execute($doctrineAdapter, 'order_process', 456, fn() => processOrder(456));
$result = $service->execute($eloquentAdapter, 'cache_rebuild', 1, fn() => rebuildCache());
```

## Настройка режимов блокировки

### Режимы ожидания

```php
use Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum;

// Неблокирующий режим (по умолчанию) - возвращает false если лок занят
$acquired = $lock->acquireTransactionLevel(PostgresLockWaitModeEnum::NonBlocking);

// Блокирующий режим - ждет освобождения лока
$acquired = $lock->acquireTransactionLevel(PostgresLockWaitModeEnum::Blocking);
```

### Режимы доступа

```php
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;

// Эксклюзивный доступ (по умолчанию) - только один процесс может держать лок
$acquired = $lock->acquireTransactionLevel(
    PostgresLockWaitModeEnum::NonBlocking,
    PostgresLockAccessModeEnum::Exclusive
);

// Разделяемый доступ - несколько процессов могут держать лок одновременно
$acquired = $lock->acquireTransactionLevel(
    PostgresLockWaitModeEnum::NonBlocking,
    PostgresLockAccessModeEnum::Share
);
```

## Проверка состояния

```php
// Проверка состояния транзакции
if ($lock->isInTransaction()) {
    echo "Соединение находится в транзакции";
}

// Получение типа платформы
$platform = $lock->getPlatformName(); // 'postgresql'

// Получение ключа блокировки
$lockKey = $lock->getLockKey();
echo $lockKey->humanReadableValue; // 'payment_processing'
echo $lockKey->objectId;           // 123
echo $lockKey->classId;            // crc32('payment_processing')
```

## Освобождение всех локов сессии

```php
// Освобождает ВСЕ локи текущей сессии, не только данного объекта
$lock->releaseAll();
```

## Лучшие практики

1. **Предпочитайте транзакционные локи** - они автоматически освобождаются при commit/rollback
2. **Используйте `withinSessionLock()`** для сессионных локов - гарантирует освобождение при исключениях
3. **Создавайте локи рядом с местом использования** - не храните их в конструкторах сервисов
4. **Используйте осмысленные неймспейсы** - группируйте связанные локи по логическим областям
5. **Проверяйте `wasAcquired`** в callback'ах сессионных локов

## Миграция с PostgresAdvisoryLocker

```php
// Старый код
$locker = new PostgresAdvisoryLocker();
$acquired = $locker->acquireTransactionLevelLock($pdo, $key);

// Новый код
$lock = PostgresLockFactory::create($pdo, 'my_namespace', $id);
$acquired = $lock->acquireTransactionLevel();
```

## Ошибки и исключения

- `LogicException` - попытка захватить транзакционный лок вне транзакции
- `RuntimeException` - ошибки подключения к БД или выполнения SQL
- `InvalidArgumentException` - неподдерживаемый тип адаптера (маловероятно)

Фабрика значительно упрощает работу с блокировками и делает код более читаемым и поддерживаемым.
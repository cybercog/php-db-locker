<?php

declare(strict_types=1);

use Cog\DbLocker\DbConnectionAdapter\DoctrineDbConnectionAdapter;
use Cog\DbLocker\DbConnectionAdapter\EloquentDbConnectionAdapter;
use Cog\DbLocker\Postgres\PostgresLockFactory;
use Doctrine\DBAL\DriverManager;
use Illuminate\Database\Connection as EloquentConnection;

/**
 * Примеры использования PostgresLockFactory
 * 
 * Фабрика создает объекты PostgresLock, которые инкапсулируют
 * все операции с конкретной блокировкой.
 */
class PostgresLockFactoryExample
{
    /**
     * Пример 1: Простое использование с PDO
     */
    public function exampleWithPdo(): void
    {
        $pdo = new PDO('pgsql:host=localhost;dbname=test', 'user', 'password');
        
        // Создаем лок для обработки платежей пользователя 123
        $lock = PostgresLockFactory::create($pdo, 'payment_processing', 123);
        
        // Начинаем транзакцию
        $pdo->beginTransaction();
        
        try {
            // Захватываем транзакционный лок
            if ($lock->acquireTransactionLevel()) {
                echo "Лок захвачен, обрабатываем платеж\n";
                $this->processPayment(123);
                $pdo->commit();
            } else {
                echo "Не удалось захватить лок\n";
                $pdo->rollback();
            }
        } catch (Exception $e) {
            $pdo->rollback();
            throw $e;
        }
    }

    /**
     * Пример 2: Использование с Doctrine DBAL в транзакции
     */
    public function exampleWithDoctrine(): void
    {
        $connection = DriverManager::getConnection([
            'dbname' => 'test',
            'user' => 'user', 
            'password' => 'password',
            'host' => 'localhost',
            'driver' => 'pdo_pgsql',
        ]);

        $adapter = new DoctrineDbConnectionAdapter($connection);
        
        // Создаем лок для синхронизации инвентаря товара 456
        $lock = PostgresLockFactory::create($adapter, 'inventory_sync', 456);
        
        $connection->beginTransaction();
        
        try {
            // Используем существующую транзакцию
            if ($lock->acquireTransactionLevel()) {
                echo "Обновляем инвентарь товара 456\n";
                $this->updateInventory(456);
                $connection->commit();
            } else {
                $connection->rollback();
                throw new RuntimeException('Товар заблокирован');
            }
        } catch (Exception $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Пример 3: Использование с Laravel Eloquent
     */
    public function exampleWithEloquent(): void
    {
        // В реальном приложении: DB::connection()
        $eloquentConnection = $this->getMockEloquentConnection();
        $adapter = new EloquentDbConnectionAdapter($eloquentConnection);
        
        // Создаем лок для регистрации пользователя
        $lock = PostgresLockFactory::createFromString($adapter, 'user_registration');
        
        // В Laravel это будет DB::transaction()
        try {
            $result = $lock->withinSessionLock(function($lockHandle) {
                if (!$lockHandle->wasAcquired) {
                    throw new RuntimeException('Регистрация временно недоступна');
                }
                
                echo "Регистрируем нового пользователя\n";
                return $this->registerUser();
            });
            
            echo "Пользователь зарегистрирован: {$result}\n";
        } catch (Exception $e) {
            echo "Ошибка регистрации: {$e->getMessage()}\n";
        }
    }

    /**
     * Пример 4: Использование в сервис-классе
     */
    public function exampleInService(): void
    {
        // Сервис для обработки критических секций
        class CriticalSectionService
        {
            public function processUserAction(
                $connection, 
                int $userId, 
                string $action,
                callable $operation
            ): mixed {
                // Создаем лок для конкретного пользователя и действия
                $lock = PostgresLockFactory::create($connection, "user_action_{$action}", $userId);
                
                return $lock->withinSessionLock(function($lockHandle) use ($operation, $userId, $action) {
                    if (!$lockHandle->wasAcquired) {
                        throw new RuntimeException("Действие {$action} для пользователя {$userId} уже выполняется");
                    }
                    
                    echo "Выполняем {$action} для пользователя {$userId}\n";
                    return $operation();
                });
            }
        }
        
        $service = new CriticalSectionService();
        $pdo = new PDO('pgsql:host=localhost;dbname=test', 'user', 'password');
        
        // Обработка смены пароля
        $result = $service->processUserAction(
            $pdo, 
            123, 
            'change_password',
            function() {
                // Логика смены пароля
                sleep(1); // имитация работы
                return 'password_changed';
            }
        );
        
        echo "Результат: {$result}\n";
    }

    /**
     * Пример 5: Работа с несколькими локами
     */
    public function exampleMultipleLocks(): void
    {
        $pdo = new PDO('pgsql:host=localhost;dbname=test', 'user', 'password');
        
        // Создаем локи для разных ресурсов
        $userLock = PostgresLockFactory::create($pdo, 'user', 123);
        $accountLock = PostgresLockFactory::create($pdo, 'account', 456);
        
        // Захватываем несколько локов в правильном порядке (избежание дедлоков)
        $userLockHandle = $userLock->acquireSessionLevel();
        $accountLockHandle = $accountLock->acquireSessionLevel();
        
        try {
            if ($userLockHandle->wasAcquired && $accountLockHandle->wasAcquired) {
                echo "Все локи захвачены, выполняем перевод\n";
                $this->transferMoney(123, 456, 100);
            } else {
                echo "Не удалось захватить все необходимые локи\n";
            }
        } finally {
            // Освобождаем локи в обратном порядке
            $accountLockHandle->release();
            $userLockHandle->release();
        }
    }

    /**
     * Пример 6: Проверка состояния транзакции через лок
     */
    public function exampleTransactionCheck(): void
    {
        $pdo = new PDO('pgsql:host=localhost;dbname=test', 'user', 'password');
        $lock = PostgresLockFactory::create($pdo, 'transaction_check', 1);
        
        echo "В транзакции: " . ($lock->isInTransaction() ? 'да' : 'нет') . "\n";
        
        $pdo->beginTransaction();
        echo "В транзакции: " . ($lock->isInTransaction() ? 'да' : 'нет') . "\n";
        
        if ($lock->acquireTransactionLevel()) {
            echo "Транзакционный лок захвачен\n";
        }
        
        $pdo->commit();
        echo "В транзакции: " . ($lock->isInTransaction() ? 'да' : 'нет') . "\n";
    }

    /**
     * Пример 7: Универсальная функция с разными типами подключений
     */
    public function exampleUniversalFunction(): void
    {
        $this->executeWithLock(
            new PDO('pgsql:host=localhost;dbname=test', 'user', 'password'),
            'pdo_operation',
            1,
            function() { return 'PDO result'; }
        );
        
        $doctrineConnection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'localhost',
            'dbname' => 'test',
            'user' => 'user',
            'password' => 'password',
        ]);
        
        $this->executeWithLock(
            new DoctrineDbConnectionAdapter($doctrineConnection),
            'doctrine_operation',
            2,
            function() { return 'Doctrine result'; }
        );
    }
    
    /**
     * Универсальная функция для выполнения операций с блокировкой
     */
    private function executeWithLock($connection, string $namespace, int $id, callable $operation): void
    {
        $lock = PostgresLockFactory::create($connection, $namespace, $id);
        
        $result = $lock->withinSessionLock(function($lockHandle) use ($operation, $namespace, $id) {
            if (!$lockHandle->wasAcquired) {
                throw new RuntimeException("Не удалось захватить лок {$namespace}:{$id}");
            }
            
            return $operation();
        });
        
        echo "Результат операции {$namespace}:{$id} = {$result}\n";
    }

    // Вспомогательные методы для примеров
    private function processPayment(int $userId): void
    {
        echo "Обрабатываем платеж для пользователя {$userId}\n";
        sleep(1);
    }

    private function updateInventory(int $productId): void
    {
        echo "Обновляем инвентарь товара {$productId}\n";
        sleep(1);
    }

    private function registerUser(): string
    {
        return 'user_' . uniqid();
    }

    private function transferMoney(int $fromUserId, int $toUserId, float $amount): void
    {
        echo "Переводим {$amount} от пользователя {$fromUserId} к {$toUserId}\n";
        sleep(1);
    }

    private function getMockEloquentConnection(): EloquentConnection
    {
        return new class extends EloquentConnection {
            public function __construct() {}
            public function transactionLevel(): int { return 0; }
            public function selectOne($query, $bindings = [], $useReadPdo = true) { return null; }
            public function getPdo() { return new PDO('pgsql:host=localhost;dbname=test', 'user', 'password'); }
        };
    }
}

/**
 * Преимущества использования PostgresLockFactory:
 * 
 * 1. **Простота использования**: 
 *    - Один вызов фабрики создает готовый к использованию объект лока
 *    - Все параметры инкапсулированы в объекте
 * 
 * 2. **Чистый API**: 
 *    - Не нужно передавать подключение в каждый метод
 *    - Методы стали короче и понятнее
 * 
 * 3. **Универсальность**: 
 *    - Работает с любыми типами подключений (PDO, Doctrine, Eloquent)
 *    - Единый интерфейс для всех случаев использования
 * 
 * 4. **Безопасность**: 
 *    - Объект лока связан с конкретным подключением и ключом
 *    - Невозможно случайно использовать неправильное подключение
 * 
 * 5. **Удобство в сервисах**: 
 *    - Можно создать лок один раз и передавать между методами
 *    - Легко тестировать и мокать
 */

// Пример запуска
echo "=== Примеры использования PostgresLockFactory ===\n";

$example = new PostgresLockFactoryExample();
echo "\n--- Пример с PDO ---\n";
$example->exampleWithPdo();

echo "\n--- Пример проверки транзакции ---\n";
$example->exampleTransactionCheck();

echo "\n--- Пример нескольких локов ---\n";
$example->exampleMultipleLocks();

echo "\n--- Пример в сервисе ---\n";
$example->exampleInService();
<?php

/**
 * SharkyPHP Database Library
 *
 * 驱动公共基类：负责配置解析、懒连接、参数校验、事务栈与表元信息缓存
 *
 * @package SharkyPHP\Database
 */
declare (strict_types = 1);

namespace Sharky\Database\Driver;

use InvalidArgumentException;
use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Exceptions\DBException;
use Sharky\Database\Support\TableMetaCache;
use Throwable;

abstract class AbstractConnection implements ConnectionInterface
{
    /** 表元信息缓存实例（按数据库名 + 表名） */
    private static ?TableMetaCache $metaCache = null;

    protected array $config;

    /** 底层连接句柄，null 表示尚未连接 */
    protected mixed $handle = null;

    /**
     * 查询结果类型强制转换，用于消除 PDO / mysqli 的返回差异
     *
     * 默认 true：所有字段按字符串返回，与 mysqli 默认行为一致，
     * 需要原生类型时在配置中传 'native_types' => true。
     */
    protected bool $stringifyFetch = true;

    /** 事务嵌套层级 */
    protected int $transactionLevel = 0;

    /**
     * @throws InvalidArgumentException 配置缺失或取值非法
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        $this->stringifyFetch = ! ($config['native_types'] ?? false);
    }

    /**
     * 按当前策略规整结果集，保证两个驱动返回结构一致
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeRows(array $rows): array
    {
        if (! $this->stringifyFetch) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (is_int($value) || is_float($value)) {
                    $rows[$i][$column] = (string) $value;
                }
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // 由具体驱动实现
    // ------------------------------------------------------------------

    abstract public function query(string $sql, array $params = []): array;

    abstract public function execute(string $sql, array $params = []): int;

    abstract public function lastInsertId(): int;

    abstract public function connectionId(): int;

    abstract protected function connect(): void;

    abstract protected function disconnect(): void;

    abstract protected function fetchAllRaw(string $sql): array;

    abstract protected function beginTransactionInternal(): bool;

    abstract protected function commitInternal(): bool;

    abstract protected function rollbackInternal(): bool;

    // ------------------------------------------------------------------
    // 连接管理
    // ------------------------------------------------------------------

    public function isConnected(): bool
    {
        return $this->handle !== null;
    }

    protected function ensureConnection(): void
    {
        if ($this->handle === null) {
            $this->connect();
        }
    }

    /**
     * 带「长连接失效自动重连」地执行一次数据库操作。
     *
     * 首次执行若因连接断开类错误（MySQL server has gone away / Lost connection /
     * 通信链路失败等）失败，则关闭已失效句柄、重建连接并重试一次；第二次若仍失败
     * 则直接抛出，不会死循环。
     *
     * 处于事务中（transactionLevel > 0）时不做重连：连接断开意味着事务已丢失，
     * 静默重连会导致后续语句在「无事务」的新连接上执行，破坏原子性，应直接抛出。
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function retryable(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DBException $e) {
            if ($this->transactionLevel === 0 && $this->isConnectionLost($e)) {
                $this->close();
                $this->ensureConnection();

                // 二次重试在 try 之外：若仍失败直接抛出，避免死循环
                return $operation();
            }

            throw $e;
        }
    }

    /**
     * 判断异常是否由底层连接断开引起（此时重连可恢复）。
     */
    protected function isConnectionLost(DBException $e): bool
    {
        static $lostCodes = ['2002', '2006', '2013', '2055'];

        if (in_array((string) $e->getCode(), $lostCodes, true)) {
            return true;
        }

        // SQLSTATE 08S01 = 通信链路失败，明确属于连接问题
        if ($e->getSqlState() === '08S01') {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'connection reset');
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        try {
            $this->disconnect();
        } catch (Throwable) {
            // 关闭连接失败不向外抛出
        } finally {
            $this->handle           = null;
            $this->transactionLevel = 0;
        }
    }

    public function getPrefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
    }

    public function __destruct()
    {
        $this->close();
    }

    // ------------------------------------------------------------------
    // 表元信息
    // ------------------------------------------------------------------

    public function getFields(string $table): array
    {
        return $this->tableMeta($table)['fields'];
    }

    public function getPrimarys(string $table): array
    {
        return $this->tableMeta($table)['primary'];
    }

    protected function tableMeta(string $table): array
    {
        $this->assertIdentifier($table);

        $cache = self::metaCache();
        $key   = $this->config['database'] . '.' . $table;

        $meta = $cache->get($key);
        if ($meta === null) {
            $meta = $this->loadTableMeta($table);
            $cache->set($key, $meta);
        }

        return $meta;
    }

    protected function loadTableMeta(string $table): array
    {
        try {
            $this->ensureConnection();

            $columns = $this->fetchAllRaw("SHOW COLUMNS FROM `{$table}`");
            $indexes = $this->fetchAllRaw("SHOW INDEX FROM `{$table}` WHERE Key_name = 'PRIMARY'");

            $fields = [];
            foreach ($columns as $row) {
                $fields[] = (string) $row['Field'];
            }

            $primary = [];
            foreach ($indexes as $row) {
                $primary[] = (string) $row['Column_name'];
            }

            return [
                'fields'  => $fields,
                'primary' => $primary,
            ];
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    protected function assertIdentifier(string $table): void
    {
        if (! preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
            throw new InvalidArgumentException(sprintf("Illegal table name: '%s'", $table));
        }
    }

    protected static function metaCache(): TableMetaCache
    {
        return self::$metaCache ??= new TableMetaCache();
    }

    public static function flushMetaCache(): void
    {
        self::metaCache()->flush();
    }

    // ------------------------------------------------------------------
    // 事务
    // ------------------------------------------------------------------

    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    public function beginTransaction(): bool
    {
        try {
            $this->ensureConnection();

            $level = ++$this->transactionLevel;

            if ($level > 1) {
                // 嵌套事务：外层已开启事务，内层用 SAVEPOINT 模拟
                $this->fetchAllRaw('SAVEPOINT ' . $this->savepointName($level));

                return true;
            }

            return $this->beginTransactionInternal();
        } catch (Throwable $th) {
            $this->transactionLevel = max(0, $this->transactionLevel - 1);

            throw DBException::transform($th);
        }
    }

    public function commit(): bool
    {
        $this->assertInTransaction('commit');

        try {
            $this->ensureConnection();

            $level = $this->transactionLevel--;

            if ($level > 1) {
                // 释放保存点后，外层事务依然有效
                $this->fetchAllRaw('RELEASE SAVEPOINT ' . $this->savepointName($level));

                return true;
            }

            return $this->commitInternal();
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    public function rollback(): bool
    {
        $this->assertInTransaction('rollback');

        try {
            $this->ensureConnection();

            $level = $this->transactionLevel--;

            if ($level > 1) {
                // 回滚到保存点后，外层事务依然有效
                $this->fetchAllRaw('ROLLBACK TO SAVEPOINT ' . $this->savepointName($level));

                return true;
            }

            return $this->rollbackInternal();
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    public function runTransaction(callable $callback, bool $silence = false): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);

            if ($result === false) {
                $this->rollback();

                return false;
            }

            $this->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                if ($this->transactionLevel > 0) {
                    $this->rollback();
                }
            } catch (Throwable) {
                // 回滚失败时保留原始异常
            }

            if ($silence) {
                return false;
            }

            throw $e;
        }
    }

    private function savepointName(int $level): string
    {
        return 'sp_' . $level;
    }

    private function assertInTransaction(string $operation): void
    {
        if ($this->transactionLevel === 0) {
            throw new DBException(sprintf('Cannot %s: not in an active transaction', $operation));
        }
    }

}

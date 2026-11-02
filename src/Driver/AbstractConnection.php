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

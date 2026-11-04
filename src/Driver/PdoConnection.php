<?php
/**
 * SharkyPHP Database Library
 *
 * PDO 驱动实现
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database\Driver;

use PDO;
use PDOStatement;
use Sharky\Database\Exceptions\DBException;
use Sharky\Database\Support\ParameterBinder;
use Throwable;

final class PdoConnection extends AbstractConnection  
{
    protected mixed $handle = null;

    protected function connect(): void
    {
        $cfg = $this->config;

        try {
            $host = $cfg['persistent'] ? 'p:' . $cfg['host'] : $cfg['host'];

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $cfg['port'],
                $cfg['database'],
                $cfg['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => $cfg['persistent'],
                // 默认把 int/float 统一转成字符串，保持与 mysqli 驱动一致的返回结构
                PDO::ATTR_STRINGIFY_FETCHES  => $this->stringifyFetch,
                PDO::ATTR_TIMEOUT            => $cfg['timeout'],
            ];

            // 关闭模拟预处理，让占位符真正走到服务端（与 mysqli 行为一致）
            if (defined('PDO::ATTR_EMULATE_PREPARES')) {
                $options[PDO::ATTR_EMULATE_PREPARES] = false;
            }

            // 注意：持久连接不支持在构造后修改 ATTR_TIMEOUT，已在 options 中传入
            $this->handle = new PDO($dsn, $cfg['username'], $cfg['password'], $options);

            if (! $this->stringifyFetch) {
                // 让 PDO 返回原生 int/float，与 mysqli 原生行为对齐
                $this->handle->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
            }
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    protected function disconnect(): void
    {
        // PDO 无显式 close，置 null 让 GC 释放（持久连接会被连接池复用）
        $this->handle = null;
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->retryable(function () use ($sql, $params) {
            try {
                $this->ensureConnection();

                $statement = $this->run($sql, $params);

                return $this->normalizeRows($statement->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $th) {
                throw DBException::transform($th);
            }
        });
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->retryable(function () use ($sql, $params) {
            try {
                $this->ensureConnection();

                return $this->run($sql, $params)->rowCount();
            } catch (Throwable $th) {
                throw DBException::transform($th);
            }
        });
    }

    protected function fetchAllRaw(string $sql): array
    {
        return $this->retryable(function () use ($sql) {
            try {
                $this->ensureConnection();

                return $this->normalizeRows($this->handle->query($sql)->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $th) {
                throw DBException::transform($th);
            }
        });
    }

    public function lastInsertId(): int
    {
        return $this->retryable(function () {
            try {
                $this->ensureConnection();

                // PDO::lastInsertId() 在 PHP 中始终返回字符串（无自增时是 "0"），必须显式转 int
                return (int) $this->handle->lastInsertId();
            } catch (Throwable $th) {
                throw DBException::transform($th);
            }
        });
    }

    public function connectionId(): int
    {
        return $this->retryable(function () {
            try {
                $this->ensureConnection();

                return (int) $this->handle->query('SELECT CONNECTION_ID()')->fetchColumn();
            } catch (Throwable $th) {
                throw DBException::transform($th);
            }
        });
    }

    protected function beginTransactionInternal(): bool
    {
        return $this->handle->beginTransaction();
    }

    protected function commitInternal(): bool
    {
        return $this->handle->commit();
    }

    protected function rollbackInternal(): bool
    {
        return $this->handle->rollBack();
    }

    private function run(string $sql, array $values): PDOStatement
    {
        $values    = ParameterBinder::positional($sql, $values);
        $statement = $this->handle->prepare($sql);

        if ($values !== []) {
            foreach (array_values($values) as $index => $value) {
                $statement->bindValue($index + 1, $value, $this->pdoType($value));
            }
        }

        $statement->execute();

        return $statement;
    }

    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value)  => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}

<?php
/**
 * SharkyPHP Database Library
 *
 * MySQLi 驱动实现
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database\Driver;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use Sharky\Database\Exceptions\DBException;
use Sharky\Database\Support\ParameterBinder;
use Throwable;

final class MysqliConnection extends AbstractConnection  
{
    protected mixed $handle = null;

    protected function connect(): void
    {
        // 打开 mysqli 异常模式：连接 / 预处理 / 执行失败都会抛 mysqli_sql_exception
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $cfg = $this->config;

        try {
            $host = $cfg['persistent'] ? 'p:' . $cfg['host'] : $cfg['host'];

            $handle = mysqli_init();
            if ($handle === false) {
                throw new DBException('mysqli_init() failed');
            }

            $handle->options(MYSQLI_OPT_CONNECT_TIMEOUT, $cfg['timeout']);

            $handle->real_connect(
                $host,
                $cfg['username'],
                $cfg['password'],
                $cfg['database'],
                $cfg['port']
            );

            $handle->set_charset($cfg['charset']);

            $this->handle = $handle;
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    protected function disconnect(): void
    {
        if ($this->handle instanceof mysqli) {
            $this->handle->close();
        }

        $this->handle = null;
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            $this->ensureConnection();

            $statement = $this->run($sql, $params);
            $result    = $statement->get_result();

            if (! $result instanceof mysqli_result) {
                throw new DBException('The statement does not produce a result set: use execute() instead');
            }

            return $this->normalizeRows($result->fetch_all(MYSQLI_ASSOC));
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            $this->ensureConnection();

            return $this->run($sql, $params)->affected_rows;
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    protected function fetchAllRaw(string $sql): array
    {
        $this->ensureConnection();

        $result = $this->handle->query($sql);

        if (! $result instanceof mysqli_result) {
            return [];
        }

        $rows = $this->normalizeRows($result->fetch_all(MYSQLI_ASSOC));
        $result->free();

        return $rows;
    }

    public function lastInsertId(): int
    {
        try {
            $this->ensureConnection();

            $id = $this->handle->insert_id;

            return is_int($id) ? $id : 0;
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    public function connectionId(): int
    {
        try {
            $this->ensureConnection();

            $result = $this->handle->query('SELECT CONNECTION_ID()');
            $row    = $result->fetch_row();
            $result->free();

            return (int) $row[0];
        } catch (Throwable $th) {
            throw DBException::transform($th);
        }
    }

    protected function beginTransactionInternal(): bool
    {
        return $this->handle->begin_transaction();
    }

    protected function commitInternal(): bool
    {
        return $this->handle->commit();
    }

    protected function rollbackInternal(): bool
    {
        return $this->handle->rollback();
    }

    /**
     * mysqli 只支持 '?' 位置占位符，命名参数在这里改写为位置参数
     *
     * mysqli 绑定是引用传参，必须持有参数数组变量直到 execute 结束
     */
    private function run(string $sql, array $values): mysqli_stmt
    {
        if (ParameterBinder::isNamed($values)) {
            [$sql, $values] = ParameterBinder::convertNamedToPositional($sql, $values);
        } else {
            $values = ParameterBinder::positional($sql, $values);
        }

        $stmt = $this->handle->prepare($sql);

        if (! $stmt instanceof mysqli_stmt) {
            throw new DBException('Failed to prepare statement: ' . $this->handle->error);
        }

        if ($values !== []) {
            $types = '';
            foreach ($values as $value) {
                $types .= $this->mysqliType($value);
            }

            $bindValues = array_values($values);
            $stmt->bind_param($types, ...$bindValues);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * 位置参数 -> mysqli bind_param 类型串
     */
    private function mysqliType(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => 'i',
            is_int($value)   => 'i',
            is_float($value) => 'd',
            default          => 's',
        };
    }
}

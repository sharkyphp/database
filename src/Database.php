<?php
/**
 * SharkyPHP Database Library
 *
 * Mysql 数据库连接管理器
 *
 * @package Sharky\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database;

use InvalidArgumentException;
use RuntimeException;
use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Factory\ConnectionFactory;

final class Database
{
    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connects = [];

    /**
     * @var array
     */
    private array $config = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(?string $connect = null): ConnectionInterface
    {
        if ($connect === null) {
            $connect = array_key_first($this->config['hosts'] ?? []);
            if ($connect === null) {
                throw new RuntimeException('Database hosts configuration is empty, cannot resolve default connection');
            }
        }

        if (array_key_exists($connect, $this->connects)) {
            return $this->connects[$connect];
        }

        // 浅拷贝总配置
        $config = $this->config;

        // 节点是否存在
        if (! isset($config['hosts'][$connect]) || ! is_array($config['hosts'][$connect])) {
            throw new InvalidArgumentException(
                sprintf('Database connection node [%s] is not defined in hosts config', $connect)
            );
        }

        // 合并主机配置，移除 hosts
        $nodeConfig = $config['hosts'][$connect];
        unset($config['hosts']);
        $config = [ ...$config, ...$nodeConfig];

        // 可选参数补默认值
        $config['persistent'] = $config['persistent'] ?? false;

        $connection               = ConnectionFactory::make($config);
        $this->connects[$connect] = $connection;

        return $connection;
    }

    public function query(string $sql, array $params = []): mixed
    {
        return $this->defaultConnect()->query($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->defaultConnect()->execute($sql, $params);
    }

    public function beginTransaction(): bool
    {
        return $this->defaultConnect()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->defaultConnect()->commit();
    }

    public function rollback(): bool
    {
        return $this->defaultConnect()->rollback();
    }

    public function runTransaction(callable $callBack): mixed
    {
        return $this->defaultConnect()->runTransaction($callBack);
    }

    public function lastInsertId(): int
    {
        return $this->defaultConnect()->lastInsertId();
    }

    public function closeAll(): void
    {
        foreach ($this->connects as $connection) {
            $connection->close();
        }
        $this->connects = [];
    }

    public function disconnect(string $connect): void
    {
        if (array_key_exists($connect, $this->connects)) {
            $this->connects[$connect]->close();
            unset($this->connects[$connect]);
        }
    }

    /**
     * @return array<string>
     */
    public function getConnectedNames(): array
    {
        return array_keys($this->connects);
    }

    /**
     * 默认连接（取 hosts 第一个节点）
     */
    private function defaultConnect(): ConnectionInterface
    {
        return $this->connect();
    }
}

<?php

/**
 * SharkyPHP Database Library
 *
 * 数据库连接统一契约：PDO 与 MySQLi 两个驱动都必须实现本接口
 *
 * 连接管理相关的 3 个方法（isConnected / close / getPrefix）继承
 * Sharky\Contracts\ConnectionInterface，保证与 Redis 连接接口保持一致。
 *
 * @package SharkyPHP.Database
 */
declare (strict_types = 1);

namespace Sharky\Database\Contracts;

use Sharky\Contracts\ConnectionInterface as BaseConnectionInterface;
use Sharky\Database\Exceptions\DBException;

interface ConnectionInterface extends BaseConnectionInterface
{
    /**
     * 执行查询语句，返回结果集（关联数组列表）
     *
     * @param string $sql    含占位符的 SQL
     * @param array  $params 绑定参数（'?' 顺序参数 或 ':name' 命名参数）
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws DBException
     */
    public function query(string $sql, array $params = []): array;

    /**
     * 执行写入语句，返回受影响行数
     *
     * @throws DBException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * 获取表中全部字段名
     *
     * @return string[]
     *
     * @throws DBException
     */
    public function getFields(string $table): array;

    /**
     * 获取表主键字段名（复合主键按索引顺序）
     *
     * @return string[]
     *
     * @throws DBException
     */
    public function getPrimarys(string $table): array;

    /**
     * 开启事务（嵌套调用时自动降级为 SAVEPOINT）
     *
     * @throws DBException
     */
    public function beginTransaction(): bool;

    /**
     * 提交事务（嵌套层级时只释放当前 SAVEPOINT）
     *
     * @throws DBException
     */
    public function commit(): bool;

    /**
     * 回滚事务（嵌套层级时只回滚到当前 SAVEPOINT）
     *
     * @throws DBException
     */
    public function rollback(): bool;

    /**
     * 以回调方式执行事务
     *
     * 回调返回 false 则回滚；抛出异常则回滚后重新抛出（$silence 为 true 时吞掉异常返回 false）
     *
     * @param callable(self): mixed $callback
     *
     * @throws DBException
     */
    public function runTransaction(callable $callback, bool $silence = false): mixed;

    /**
     * 最近一次 INSERT 产生的自增 ID
     *
     * @throws DBException
     */
    public function lastInsertId(): int;

    /**
     * 当前数据库连接 ID（SELECT CONNECTION_ID()）
     *
     * @throws DBException
     */
    public function connectionId(): int;

    /**
     * 当前事务嵌套层级，0 表示未开启事务
     */
    public function transactionLevel(): int;

    /**
     * 连接是否已建立
     */
    public function isConnected(): bool;
}

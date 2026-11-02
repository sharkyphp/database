<?php
/**
 * SharkyPHP Database Library
 *
 * 连接工厂
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database\Factory;

use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Driver\MysqliConnection;
use Sharky\Database\Driver\PdoConnection;

final class ConnectionFactory
{
    /** 驱动注册表：type => 实现类 */
    private const DRIVERS = [
        'pdo'    => PdoConnection::class,
        'mysqli' => MysqliConnection::class,
    ];

    /** @var array<string, class-string<ConnectionInterface>> */
    private static array $drivers = self::DRIVERS;

    private function __construct()
    {
    }

    /**
     * 根据配置创建连接
     */
    public static function make(array $config): ConnectionInterface
    {
        // 统一校验必填 key
        $requiredKeys = ['type', 'driver', 'host', 'port', 'timeout', 'username', 'password', 'database', 'charset'];
        foreach ($requiredKeys as $k) {
            if (! array_key_exists($k, $config)) {
                throw new InvalidArgumentException(
                    sprintf('Missing required config key [%s] for connection [%s]', $k, $connect)
                );
            }
        }

        $driver = self::driver($config['driver']);

        return new $driver($config);
    }

    /**
     * 注册自定义驱动，可在不修改本类的前提下接入新驱动
     *
     * @param class-string<ConnectionInterface> $driver
     */
    public static function extend(string $driver, string $concrete): void
    {
        $driver = strtolower($driver);

        self::$drivers[$driver] = $concrete;
    }

    /**
     * 支持的类型列表
     *
     * @return string[]
     */
    public static function supported(): array
    {
        return array_keys(self::$drivers);
    }

    /**
     * @return class-string<ConnectionInterface>
     */
    private static function driver(string $driver): string
    {
        if (! isset(self::$drivers[$driver])) {
            $allowed = implode(', ', array_keys(self::$drivers));

            throw new \InvalidArgumentException(
                sprintf("Unsupported database drivers '%s'. Allowed drivers: %s", $driver, $allowed)
            );
        }

        return self::$drivers[$driver];
    }
}

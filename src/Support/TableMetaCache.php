<?php
/**
 * SharkyPHP Database Library
 *
 * 表元信息缓存
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database\Support;

final class TableMetaCache
{
    protected array $metaCache = [];

    /**
     * 获取缓存
     * @param string $table 表名
     * @return array|null
     */
    public function get(string $table): ?array
    {
        return $this->metaCache[$table] ?? null;
    }

    /**
     * 写入缓存
     * @param string $table
     * @param array $data ['fields'=>[],'primary'=>[]]
     */
    public function set(string $table, array $data): void
    {
        $this->metaCache[$table] = $data;
    }

    /**
     * 清除单表缓存
     * @param string $table
     */
    public function clear(string $table): void
    {
        unset($this->metaCache[$table]);
    }

    /**
     * 清空全部
     */
    public function flush(): void
    {
        $this->metaCache = [];
    }

}

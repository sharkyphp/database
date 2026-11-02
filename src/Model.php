<?php

/**
 * SharkyPHP Database Library
 *
 * 活动记录基类：属性存取 + 持久化。
 *
 * 查询统一经由浅包装 ActiveQuery：ActiveQuery 持有查询构造器并负责自动
 * 执行 SQL、实例化模型，填充时不按表字段过滤（便于联合查询保留关联列）。
 *
 * 子类约定：
 *   - 表名默认由类名推断（UserModel -> user），可用 TABLE 常量覆盖
 *   - 表前缀默认沿用连接配置，可用 PREFIX 常量覆盖
 *   - fillable / hidden 控制批量赋值与序列化
 *
 * @package SharkyPHP\Database
 */
declare (strict_types = 1);

namespace Sharky\Database;

use ArrayAccess;
use JsonSerializable;
use RuntimeException;
use Sharky\Contracts\ArrayableInterface;
use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Query\ActiveQuery;

abstract class Model implements ArrayAccess, JsonSerializable, ArrayableInterface
{
    /** 覆盖自动推断的表名（不含前缀） */
    public const TABLE = null;

    /** 覆盖配置文件的表前缀 */
    public const PREFIX = null;

    /** 允许批量赋值的字段白名单；空数组 = 允许表中全部字段 */
    protected array $fillable = [];

    /** 序列化时隐藏的字段 */
    protected array $hidden = [];

    /** 行数据 */
    protected array $attributes = [];

    /** 原始数据库数据（判断新增 / 更新用） */
    protected array $original = [];

    private static ?ConnectionInterface $connection = null;

    private ?array $tableFields = null;

    private ?array $tablePrimarys = null;

    private bool $isNewRecord = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        $this->syncOriginal();
        $this->isNewRecord = true;
    }

    // ==================================================================
    // 连接与元信息
    // ==================================================================

    /**
     * 全局绑定连接（在应用启动时调用一次）
     */
    public static function setConnection(ConnectionInterface $connection): void
    {
        self::$connection = $connection;
    }

    protected static function connection(): ConnectionInterface
    {
        if (self::$connection === null) {
            throw new RuntimeException(sprintf('No database connection bound to model. Invoke %s::setConnection() before use.', static::class));
        }

        return self::$connection;
    }

    public function getTable(): string
    {
        return static::TABLE ?? $this->inferTableName();
    }

    /**
     * 最终落库表名（含前缀）：配置文件前缀 或 模型 PREFIX 覆盖
     */
    public function getFullTable(): string
    {
        return $this->getPrefix() . $this->getTable();
    }

    /**
     * 生效表前缀：
     *   - 模型定义了非 null 的 PREFIX 常量 → 以它为准（覆盖配置文件）；
     *   - 否则沿用连接驱动配置中的前缀。
     */
    public static function getPrefix(): string
    {
        if (static::PREFIX !== null) {
            return static::PREFIX;
        }

        return self::connection()->getPrefix();
    }

    private function inferTableName(): string
    {
        $class = static::class;
        $pos   = strrpos($class, '\\');
        $base  = $pos === false ? $class : substr($class, $pos + 1);
        $base  = preg_replace('/(Model|Entity|Record)$/i', '', $base);
        return $this->camelToSnake($base ?: $class);
    }

    private function camelToSnake(string $value): string
    {
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $value) ?? $value;
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;

        return strtolower($value);
    }

    /**
     * @return string[] 表全部字段（进程内缓存）
     */
    public function getFields(): array
    {
        return $this->tableFields ??= static::connection()->getFields($this->getFullTable());
    }

    /**
     * @return string[] 主键字段
     */
    public function getPrimarys(): array
    {
        return $this->tablePrimarys ??= static::connection()->getPrimarys($this->getFullTable());
    }

    /**
     * 查询入口：返回 ActiveQuery（已绑定模型、连接、表名与表前缀）。
     *
     * 模型不再直接使用查询构造器，统一经由 ActiveQuery 自动执行 SQL
     * 并实例化模型，便于联合查询直接拿到结果对象。
     */
    public static function query(): ActiveQuery
    {
        $model = new static();

        $query = new ActiveQuery(static::class, self::connection());
        $query->from($model->getTable());
        $query->setPrefix($model->getPrefix());

        return $query;
    }

    // ==================================================================
    // 查询便捷方法
    // ==================================================================

    public static function find(int | string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * @return Collection<int, static>
     */
    public static function all() : Collection
    {
        return static::query()->get();
    }

    // ==================================================================
    // 属性操作
    // ==================================================================

    /**
     * 由数据库结果行构造模型实例（不按表字段过滤，便于联合查询保留关联列）。
     */
    public static function newFromRow(array $row): static
    {
        $model = new static();
        $model->fill($row);
        $model->syncOriginal();
        $model->isNewRecord = false;

        return $model;
    }

    /**
     * 把当前属性标记为原始状态（从数据库加载后调用）
     */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * 填充模型（不做字段过滤，所有传入键都会写入属性）。
     *
     * 不按表字段过滤，方便联合查询将关联表列一并带入模型。
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    private function filterFillable(array $attributes): array
    {
        if ($this->fillable === []) {
            // 未配置白名单：过滤掉表里不存在的字段（连接不可用时跳过过滤）
            try {
                $fields = array_flip($this->getFields());
            } catch (\Throwable) {
                return $attributes;
            }

            return array_intersect_key($attributes, $fields);
        }

        return array_intersect_key($attributes, array_flip($this->fillable));
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function toArray(): array
    {
        $data = $this->attributes;

        foreach ($this->hidden as $hidden) {
            unset($data[$hidden]);
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ArrayAccess ------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    // 魔术方法 -----------------------------------------------------------

    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }

    // ==================================================================
    // 持久化
    // ==================================================================

    /**
     * 有主键且库中存在则更新，否则插入；返回受影响行数
     */
    public function save(): int
    {
        $data = $this->filterFillable($this->attributes);

        if ($data === []) {
            return 0;
        }

        if (! $this->isNewRecord) {
            $key = $this->primaryKeyCondition();

            if ($key === []) {
                throw new RuntimeException('Missing primary key for update operation');
            }

            $exists = static::query()->where($key)->count() > 0;

            if ($exists) {
                $changed = $this->changedAttributes($data);

                if ($changed === []) {
                    return 0;
                }

                $affected = static::query()->where($key)->update($changed);

                $this->original = $this->attributes;

                return $affected;
            }
        }

        // 新增
        $affected = static::query()->insert($data);

        // 回填自增主键
        if ($affected > 0) {
            $lastId   = static::connection()->lastInsertId();
            $primarys = $this->getPrimarys();

            if ($lastId > 0 && $primarys !== [] && count($primarys) === 1 && ! isset($this->attributes[$primarys[0]])) {
                $this->attributes[$primarys[0]] = $lastId;
            }
        }

        $this->original = $this->attributes;

        return $affected;
    }

    /**
     * 按主键删除当前记录
     */
    public function delete(): int
    {
        if ($this->isNewRecord) {
            throw new RuntimeException('This record does not exist in the database and cannot be deleted');
        }

        $key = $this->primaryKeyCondition();
        if ($key === []) {
            throw new RuntimeException('Missing primary key for delete operation');
        }
        return static::query()->where($key)->delete();
    }

    /**
     * 从当前属性中提取主键条件
     *
     * @return array<string, mixed>
     */
    private function primaryKeyCondition(): array
    {
        $key = [];

        foreach ($this->getPrimarys() as $primary) {
            if (isset($this->attributes[$primary])) {
                $key[$primary] = $this->attributes[$primary];
            }
        }

        return $key;
    }

    /**
     * 只更新发生变化的字段
     *
     * @return array<string, mixed>
     */
    private function changedAttributes(array $data): array
    {
        if ($this->original === []) {
            return $data;
        }

        $changed = [];

        foreach ($data as $key => $value) {
            if (! array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    // ==================================================================
    // 调试
    // ==================================================================

    public function __debugInfo(): array
    {
        return [
            'table'      => $this->getTable(),
            'prefix'     => $this->getPrefix(),
            'attributes' => $this->attributes,
        ];
    }
}

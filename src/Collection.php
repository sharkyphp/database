<?php
/**
 * SharkyPHP Database Library
 *
 * 数据库模型集合
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database;

use Iterator;
use JsonSerializable;
use Sharky\Contracts\ArrayableInterface;

final class Collection implements Iterator, JsonSerializable, ArrayableInterface
{
    protected $items  = [];
    private $position = 0;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    // 将集合转换为数组
    public function toArray(): array
    {
        return array_map(function ($item) {
            return $item instanceof Model ? $item->toArray() : $item;
        }, $this->items);
    }

    // 应用回调函数到每个元素并返回新集合
    public function map(callable $callback)
    {
        $mapped = array_map($callback, $this->items);
        return new static($mapped);
    }

    // 添加单个模型
    public function add($item): void
    {
        $this->items[] = $item;
    }

    // 获取所有项
    public function all(): array
    {
        return $this->items;
    }

    // 返回集合的数量
    public function count(): int
    {
        return count($this->items);
    }

    // 添加count属性的访问器方法（getter）
    public function __get($name): int | null
    {
        if ($name === 'count') {
            return count($this->items);
        }
        return null;
    }

    public function __debugInfo(): array
    {
        return [
            "count" => count($this->items),
        ];
    }

    // 返回当前元素
    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    // 返回当前元素的键
    public function key(): int
    {
        return $this->position;
    }

    // 将内部指针向前移动一位
    public function next(): void
    {
        $this->position++;
    }

    // 重置内部指针到第一个元素
    public function rewind(): void
    {
        $this->position = 0;
    }

    // 检查当前位置是否有效
    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    // 实现 JsonSerializable 接口
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}

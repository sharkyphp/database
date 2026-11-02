<?php

/**
 * SharkyPHP Database Library
 *
 * 活动查询（ActiveQuery）：模型查询的浅包装
 *
 * 持有查询构造器，转发链式调用；执行类方法（get / first / find / paginate）
 * 会自动执行 SQL 并把结果行实例化为模型，填充时不做字段过滤，
 * 因此联合查询的关联列也能随结果一并返回。
 *
 * @package SharkyPHP\Database\Query
 */
declare (strict_types = 1);

namespace Sharky\Database\Query;

use Closure;
use InvalidArgumentException;
use Sharky\Database\Collection;
use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Exceptions\DBException;
use Sharky\Database\Model;

/**
 * @template TModel of Model
 */
final class ActiveQuery
{
    /** @var QueryBuilder 底层查询构造器 */
    protected QueryBuilder $builder;

    /** @var class-string<Model> 绑定的模型类 */
    protected string $modelClass;

    /**
     * @param class-string<Model>    $modelClass
     * @param ConnectionInterface|null $connection
     */
    public function __construct(string $modelClass, ?ConnectionInterface $connection = null)
    {
        $this->modelClass = $modelClass;
        $this->builder    = new QueryBuilder('', $connection);
    }

    // ==================================================================
    // 基础配置
    // ==================================================================

    public function withConnection(ConnectionInterface $connection): self
    {
        $this->builder->withConnection($connection);

        return $this;
    }

    /**
     * 注册表名映射（逻辑名 => 真实表名），build 时自动替换
     */
    public function tableMap(string $logical, string $real): self
    {
        $this->builder->tableMap($logical, $real);

        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->builder->from($table, $alias);

        return $this;
    }

    /**
     * 覆盖表前缀（优先于连接驱动配置文件中的前缀）
     */
    public function setPrefix(string $prefix): self
    {
        $this->builder->setPrefix($prefix);

        return $this;
    }

    public function alias(string $alias): self
    {
        $this->builder->alias($alias);

        return $this;
    }

    /**
     * 查询字段：'*'、'a,b'、['a', ['b', 'b_alias'], Expr]
     */
    public function select(string | array $columns = '*'): self
    {
        $this->builder->select($columns);

        return $this;
    }

    // ==================================================================
    // WHERE
    // ==================================================================

    public function where(callable | array $conditions): self
    {
        $this->builder->where($conditions);

        return $this;
    }

    public function orWhere(callable | array $conditions): self
    {
        $this->builder->orWhere($conditions);

        return $this;
    }

    // ==================================================================
    // JOIN / GROUP / ORDER / LIMIT
    // ==================================================================

    /**
     * @param string|array{0: string, 1?: string}|QueryBuilder $table 表名 / [表名, 别名] / 子查询
     * @param string|array                                     $on    'a.id = b.uid' 或 ['a.id', '=', 'b.uid']
     */
    public function join(string | array | QueryBuilder $table, string | array $on, string $type = 'INNER'): self
    {
        $this->builder->join($table, $on, $type);

        return $this;
    }

    public function leftJoin(string | array | QueryBuilder $table, string | array $on): self
    {
        $this->builder->leftJoin($table, $on);

        return $this;
    }

    public function rightJoin(string | array | QueryBuilder $table, string | array $on): self
    {
        $this->builder->rightJoin($table, $on);

        return $this;
    }

    public function crossJoin(string | array | QueryBuilder $table, string | array $on): self
    {
        $this->builder->crossJoin($table, $on);

        return $this;
    }

    public function groupBy(string | array $columns): self
    {
        $this->builder->groupBy($columns);

        return $this;
    }

    public function orderBy(string | array $columns): self
    {
        $this->builder->orderBy($columns);

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->builder->limit($limit, $offset);

        return $this;
    }

    public function page(int $page, int $pageSize = 20): self
    {
        $this->builder->page($page, $pageSize);

        return $this;
    }

    /**
     * 行级排他锁：SELECT ... FOR UPDATE [SKIP LOCKED]
     *
     * 必须在事务内使用（配合 ConnectionInterface::runTransaction()）。
     */
    public function forUpdate(bool $skipLocked = false): self
    {
        $this->builder->forUpdate($skipLocked);

        return $this;
    }

    // ==================================================================
    // 编译与原始执行
    // ==================================================================

    /**
     * 编译当前待执行语句，返回 [SQL, 参数]；不连接数据库、不执行
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        return $this->builder->toSql();
    }

    /**
     * 手动执行当前待执行语句，返回原始结果（数组行 / 受影响行数）
     *
     * @return array<int, array<string, mixed>>|int
     *
     * @throws DBException
     */
    public function run(): array | int
    {
        return $this->builder->run();
    }

    public function lastSql(): ?array
    {
        return $this->builder->lastSql();
    }

    // ==================================================================
    // 模型化执行（自动实例化）
    // ==================================================================

    /**
     * 取全部结果并实例化为模型集合
     *
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->builder->run();

        return new Collection(array_map([$this->modelClass, 'newFromRow'], $rows));
    }

    /**
     * get() 的别名
     *
     * @return Collection<int, Model>
     */
    public function all(): Collection
    {
        return $this->get();
    }

    /**
     * 取第一行实例化为模型，无结果返回 null
     */
    public function first(): ?Model
    {
        $row = $this->builder->first();

        return $row === null ? null : $this->modelClass::newFromRow($row);
    }

    /**
     * 按主键查询单条，无结果返回 null
     *
     * @param int|string $id
     */
    public function find(int | string $id): ?Model
    {
        $instance = new $this->modelClass();

        $primarys = $instance->getPrimarys();

        if ($primarys === [] || count($primarys) > 1) {
            throw new InvalidArgumentException(sprintf('%s::find() only works for single primary key tables', $this->modelClass));
        }

        return $this->where([$primarys[0], '=', $id])->first();
    }

    /**
     * COUNT(*)，返回整数
     */
    public function count(string $column = '*'): int
    {
        return $this->builder->count($column);
    }

    /**
     * 分页查询，data 为模型实例集合
     *
     * @return array{total: int, data: Collection<int, Model>, current_page: int, page_size: int, total_pages: int, has_more: bool}
     */
    public function paginate(int $page = 1, int $pageSize = 20): array
    {
        $result         = $this->builder->paginate($page, $pageSize);
        $result['data'] = new Collection(array_map([$this->modelClass, 'newFromRow'], $result['data']));

        return $result;
    }

    // ==================================================================
    // 写操作（登记并立即执行，返回受影响行数）
    // ==================================================================

    /**
     * 登记并执行插入，返回受影响行数
     *
     * @param array|QueryBuilder $data 单行 / 多行 / 子查询
     */
    public function insert(array | QueryBuilder $data): int
    {
        $this->builder->insert($data);

        return (int) $this->builder->run();
    }

    public function insertIgnore(array | QueryBuilder $data): int
    {
        $this->builder->insertIgnore($data);

        return (int) $this->builder->run();
    }

    /**
     * 登记并执行 INSERT ... ON DUPLICATE KEY UPDATE
     *
     * @param array<string, mixed|Expr> $data
     * @param array                     $rules
     */
    public function upsert(array $data, array $rules): int
    {
        $this->builder->upsert($data, $rules);

        return (int) $this->builder->run();
    }

    /**
     * 登记并执行更新（需带 where），返回受影响行数
     *
     * @param array<string, mixed|Expr> $data
     */
    public function update(array $data): int
    {
        $this->builder->update($data);

        return (int) $this->builder->run();
    }

    /**
     * 登记并执行删除（需带 where），返回受影响行数
     */
    public function delete(): int
    {
        $this->builder->delete();

        return (int) $this->builder->run();
    }
}

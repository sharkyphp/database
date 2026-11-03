<?php

/**
 * SharkyPHP Database Library
 *
 * 查询构造器：纯 SQL 编译，不依赖任何模型 / 容器
 *
 * @package SharkyPHP\Database\Query
 */
declare (strict_types = 1);

namespace Sharky\Database\Query;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Sharky\Database\Contracts\ConnectionInterface;
use Sharky\Database\Exceptions\DBException;
use Sharky\Database\Exceptions\InsecureExecuteException;
use Sharky\Database\Model;

final class QueryBuilder
{

    private ConnectionInterface $connection;

    /** 允许的比较 / 位运算符白名单 */
    private const OPERATORS = [
        '=', '!=', '<>', '>', '<', '>=', '<=',
        '&', '|', '^', '~', '<<', '>>',
        'LIKE', 'NOT LIKE',
    ];

    /** 允许的 join 类型 */
    private const JOINS = ['LEFT', 'RIGHT', 'INNER', 'CROSS'];

    /**
     * 待执行语句：构造器只负责积累，run() 时才真正执行
     *
     * @var array{type: string, data?: mixed, rules?: array, verb?: string}
     */
    private array $pending = ['type' => 'select'];

    private string $prefix = '';

    /** setPrefix() 是否被显式调用；未调用时取连接驱动的表前缀 */
    private bool $prefixExplicit = false;

    private string $table = '';

    private string $alias = '';

    /** @var string[] 查询字段 */
    private array $columns = [];

    /** where 节点：['type' => 'condition'|'group', 'glue' => 'AND'|'OR', ...] */
    private array $wheres = [];

    /** 闭包嵌套深度 */
    private array $groupStack = [];

    private array $joins = [];

    private array $groupBy = [];

    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** SELECT ... FOR UPDATE 排他锁（需在事务内使用） */
    private bool $forUpdate = false;

    /** FOR UPDATE SKIP LOCKED */
    private bool $skipLocked = false;

    private string $lastSql = '';

    /** @var list<mixed> */
    private array $lastParams = [];

    /** @var array<string, string> 表别名映射：逻辑表名 => 实际表名（含前缀） */
    private array $tableMap = [];

    public function __construct(string $table = '', ?ConnectionInterface $connection = null)
    {
        if ($table !== '') {
            $this->from($table);
        }

        if ($connection !== null) {
            $this->connection = $connection;
        }
    }

    // ==================================================================
    // 基础配置
    // ==================================================================

    public function withConnection(ConnectionInterface $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * 注册表名映射（如前缀、Model 层的真实表名），build 时自动替换
     */
    public function tableMap(string $logical, string $real): self
    {
        $this->tableMap[$logical] = $real;
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->alias = (string) $alias;
        return $this;
    }

    public function prefix(string $prefix): self
    {
        return $this->setPrefix($prefix);
    }

    /**
     * 覆盖表前缀（优先于连接驱动配置文件中的前缀）
     *
     * 不传或传空字符串之外的显式值都将固定使用给定前缀；
     * 调用本方法后构造器不再回退到连接驱动的前缀。
     */
    public function setPrefix(string $prefix): self
    {
        $this->prefix          = $prefix;
        $this->prefixExplicit  = true;
        return $this;
    }

    public function alias(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * 查询字段：'*'、'a,b'、['a', ['b', 'b_alias'], Expr]
     */
    public function select(string | array $columns = '*'): self
    {
        $this->columns = is_string($columns)
            ? array_values(array_filter(array_map('trim', explode(',', $columns))))
            : $columns;
        return $this;
    }

    // ==================================================================
    // WHERE
    // ==================================================================

    /**
     * 条件写法（与原版兼容）：
     *   ['id', '=', 1]                     单条
     *   ['id' => 1, 'name' => 'x']         多条 AND
     *   ['age' => ['>', 18]]               键 => [运算符, 值]
     *   [['id', '=', 1], ['name', '!=', 'x']]  多条
     *   fn($qb) => $qb->where(...)         闭包 = 嵌套括号分组
     */
    public function where(callable | array $conditions): self
    {
        return $this->addWhere($conditions, 'AND');
    }

    public function orWhere(callable | array $conditions): self
    {
        return $this->addWhere($conditions, 'OR');
    }

    /**
     * orWhere 的别名，保持与 1.x / 模型代理命名一致。
     *
     * 避免闭包内习惯写 `$qb->whereOr(...)`（QueryBuilder 实例只有 orWhere，
     * 曾因此报 undefined method）。二者行为完全一致。
     */
    public function whereOr(callable | array $conditions): self
    {
        return $this->addWhere($conditions, 'OR');
    }

    /**
     * 条件成立时才追加 where；条件不成立时等价于不过滤。
     *
     * 用于「可能为空的条件」场景，省去调用方先判空：
     *   $q->whereIf($keyword !== '', ['name', 'like', "%{$keyword}%"])
     */
    public function whereIf(mixed $condition, callable | array $clause): self
    {
        if ($condition) {
            return $this->addWhere($clause, 'AND');
        }

        return $this;
    }

    private function addWhere(callable | array $conditions, string $glue): self
    {
        $glue = strtoupper($glue);

        if ($conditions instanceof Closure || is_callable($conditions)) {
            return $this->wrapGroup($conditions, $glue);
        }

        // 空数组视为「无此条件」：静默忽略，不再抛 Invalid where parameter。
        // 迁移场景下常写 `$cond ? ['col','op',$cond] : []`，空数组应等价于不过滤，
        // 与 1.x 行为一致，避免每个可能为空的条件都先判空。
        if ($conditions === []) {
            return $this;
        }

        foreach ($this->normalizeWhere($conditions) as $condition) {
            $this->pushWhere($condition, $glue);
        }

        return $this;
    }

    private function pushWhere(array $condition, string $glue): void
    {
        $node = ['type' => 'condition', 'glue' => $glue, 'condition' => $condition];

        if ($this->groupStack !== []) {
            $this->groupStack[count($this->groupStack) - 1][] = $node;

            return;
        }

        $this->wheres[] = $node;
    }

    private function wrapGroup(Closure $callback, string $glue): self
    {
        $group              = ['type' => 'group', 'glue' => $glue, 'children' => []];
        $this->groupStack[] = &$group['children'];
        $callback($this);
        array_pop($this->groupStack);

        if ($this->groupStack !== []) {
            $this->groupStack[count($this->groupStack) - 1][] = $group;

            return $this;
        }

        $this->wheres[] = $group;

        return $this;
    }

    /**
     * 把各种写法归一化为 [field, operator, value]
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function normalizeWhere(array $conditions): array
    {
        // ['字段', 运算符, 值] 或 ['字段', 值]
        if (isset($conditions[0]) && is_string($conditions[0])) {
            if (count($conditions) === 3) {
                return [[$conditions[0], strtoupper(trim($conditions[1])), $conditions[2]]];
            }

            if (count($conditions) === 2) {
                return [[$conditions[0], '=', $conditions[1]]];
            }
        }

        $normalized = [];
        foreach ($conditions as $key => $value) {
            if (is_string($key)) {
                // '字段' => 值  或  '字段' => [运算符, 值]
                if (is_array($value) && count($value) === 2 && isset($value[0]) && is_string($value[0])) {
                    $normalized[] = [$key, strtoupper(trim($value[0])), $value[1]];
                } else {
                    $normalized[] = [$key, '=', $value];
                }

                continue;
            }

            if (is_array($value)) {
                // ['字段', 运算符, 值] / ['字段', 值]
                if (isset($value[0]) && is_string($value[0])) {
                    $normalized[] = count($value) === 3
                        ? [$value[0], strtoupper(trim($value[1])), $value[2]]
                        : [$value[0], '=', $value[1]];

                    continue;
                }

                // ['字段' => 值] 嵌套写法
                foreach ($value as $subKey => $subValue) {
                    if (is_string($subKey)) {
                        $normalized[] = is_array($subValue) && count($subValue) === 2 && isset($subValue[0]) && is_string($subValue[0])
                            ? [$subKey, strtoupper(trim($subValue[0])), $subValue[1]]
                            : [$subKey, '=', $subValue];
                    }
                }

                continue;
            }

            throw new InvalidArgumentException('Invalid where parameter');

        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Invalid where parameter');

        }

        return $normalized;
    }

    /**
     * 编译 where，返回 [SQL 片段, 参数]；空条件返回 ['', []]
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $params = [];
        $sql    = $this->compileNodes($this->wheres, $params);

        if ($sql === '') {
            return ['', []];
        }

        return [" WHERE {$sql}", $params];
    }

    /**
     * @param array $nodes
     * @param list<mixed> $params
     */
    private function compileNodes(array $nodes, array &$params): string
    {
        $parts = [];

        foreach ($nodes as $index => $node) {
            $glue = $index === 0 ? '' : " {$node['glue']} ";

            if ($node['type'] === 'group') {
                $inner = $this->compileNodes($node['children'], $params);
                if ($inner !== '') {
                    $parts[] = $glue . '(' . $inner . ')';
                }

                continue;
            }

            $parts[] = $glue . $this->compileCondition($node['condition'], $params);
        }

        return implode('', $parts);
    }

    /**
     * 编译单条条件
     *
     * @param array{0: string, 1: string, 2: mixed} $condition
     * @param list<mixed> $params
     */
    private function compileCondition(array $condition, array &$params): string
    {
        [$field, $operator, $value] = $condition;

        $operator = strtoupper(trim((string) preg_replace('/\s+/', ' ', $operator)));
        $field    = $this->columnRef($field);

        if (in_array($operator, ['IN', 'NOT IN'], true)) {
            return $this->compileIn($field, $operator, $value, $params);
        }

        if (in_array($operator, ['IS', 'IS NOT'], true)) {
            return $this->compileIs($field, $operator, $value);
        }

        if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
            return $this->compileBetween($field, $operator, $value, $params);
        }

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unrecognized operator `%s`', $operator));

        }

        // NULL 与 = / != 的组合直接落成 IS NULL / IS NOT NULL
        if ($value === null) {
            return $operator === '!=' || $operator === '<>'
                ? "{$field} IS NOT NULL"
                : "{$field} IS NULL";
        }

        if ($value instanceof Expr) {
            return "{$field} {$operator} {$value}";
        }

        $params[] = $value;

        return "{$field} {$operator} ?";
    }

    /**
     * @param list<mixed> $params
     */
    private function compileIn(string $field, string $operator, mixed $value, array &$params): string
    {
        if ($value instanceof self) {
            [$subSql, $subParams] = $value->toSql();

            foreach ($subParams as $p) {
                $params[] = $p;
            }

            return "{$field} {$operator} ({$subSql})";
        }

        if ($value instanceof Closure) {
            return $this->compileIn($field, $operator, $value(), $params);
        }

        $values = is_array($value) ? $value : [$value];

        if ($values === []) {
            throw new InvalidArgumentException(sprintf('The argument for `%s` must not be empty', $operator));
        }

        $params = array_merge($params, array_values($values));

        return "{$field} {$operator} (" . implode(', ', array_fill(0, count($values), '?')) . ')';
    }

    private function compileIs(string $field, string $operator, mixed $value): string
    {
        if ($value === null) {
            $value = 'NULL';
        } elseif (is_bool($value)) {
            $value = $value ? 'TRUE' : 'FALSE';
        } elseif (is_string($value) && in_array(strtoupper($value), ['NULL', 'TRUE', 'FALSE'], true)) {
            $value = strtoupper($value);
        } else {
            throw new InvalidArgumentException(sprintf('`%s` expects only NULL, TRUE or FALSE', $operator));
        }

        return "{$field} {$operator} {$value}";
    }

    /**
     * @param list<mixed> $params
     */
    private function compileBetween(string $field, string $operator, mixed $value, array &$params): string
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException(sprintf('`%s` expects a two-element array', $operator));
        }

        $params[] = reset($value);
        $params[] = end($value);

        return "{$field} {$operator} ? AND ?";
    }

    // ==================================================================
    // JOIN / GROUP / ORDER / LIMIT
    // ==================================================================

    /**
     * @param string|array{0: string, 1?: string}|self $table 表名 / [表名, 别名] / 子查询
     * @param string|array $on 'a.id = b.uid' 或 ['a.id', '=', 'b.uid']
     */
    public function join(string | array | self $table, string | array $on, string $type = 'INNER'): self
    {
        $type = strtoupper($type);

        if (! in_array($type, self::JOINS, true)) {
            throw new InvalidArgumentException(sprintf('Join type `%s` is not supported. Allowed types: %s', $type, implode(',', self::JOINS)));
        }

        $this->joins[] = ['table' => $table, 'on' => $on, 'type' => $type];

        return $this;
    }

    public function leftJoin(string | array | self $table, string | array $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    public function rightJoin(string | array | self $table, string | array $on): self
    {
        return $this->join($table, $on, 'RIGHT');
    }

    public function crossJoin(string | array | self $table, string | array $on): self
    {
        return $this->join($table, $on, 'CROSS');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildJoin(): array
    {
        if ($this->joins === []) {
            return ['', []];
        }

        $sql    = '';
        $params = [];

        foreach ($this->joins as $join) {
            [$tableSql, $tableParams] = $this->buildJoinTable($join['table']);
            $params                   = array_merge($params, $tableParams);

            $sql .= " {$join['type']} JOIN {$tableSql} ON " . $this->buildJoinOn($join['on']);
        }

        return [$sql, $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildJoinTable(string | array | self $table): array
    {
        if ($table instanceof self) {
            [$subSql, $subParams] = $table->toSql();

            return ["({$subSql})", $subParams];
        }

        if (is_array($table)) {
            $name  = $table[0];
            $alias = $table[1] ?? null;

            return [$this->resolveTable($name) . ($alias ? " AS `{$alias}`" : ''), []];
        }

        return [$this->resolveTable($table), []];
    }

    private function buildJoinOn(string | array $on): string
    {
        if (is_string($on)) {
            return $on; // ON 条件是字段间比较，字段名已由上层保证可信
        }

        [$left, $operator, $right] = $on;

        return "{$this->columnRef($left)} {$operator} {$this->columnRef($right)}";
    }

    public function groupBy(string | array $columns): self
    {
        $cols = is_string($columns) ? array_map('trim', explode(',', $columns)) : $columns;

        $this->groupBy = array_merge($this->groupBy, $cols);

        return $this;
    }

    /**
     * orderBy('age desc') / orderBy(['age' => 'desc']) / orderBy([['age', 'desc']])
     */
    public function orderBy(string | array $columns): self
    {
        $items = is_string($columns) ? [$columns] : $columns;

        foreach ($items as $key => $item) {
            if (is_string($key)) {
                $this->pushOrder($key, is_string($item) ? $item : 'asc');

                continue;
            }

            if (is_string($item)) {
                [$field, $direction] = array_pad(preg_split('/\s+/', trim($item)) ?: [], 2, 'asc');
                $this->pushOrder($field, $direction);

                continue;
            }

            if (is_array($item)) {
                $this->pushOrder($item[0], $item[1] ?? 'asc');
            }
        }

        return $this;
    }

    private function pushOrder(string $field, string $direction): void
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sort direction `%s`. Allowed: asc, desc', $direction));
        }

        $this->orderBy[] = $this->columnRef($field) . ' ' . strtoupper($direction);
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit  = max(0, $limit);
        $this->offset = max(0, $offset);

        return $this;
    }

    public function page(int $page, int $pageSize = 20): self
    {
        $page = max(1, $page);

        return $this->limit($pageSize, ($page - 1) * $pageSize);
    }

    /**
     * 行级排他锁：SELECT ... FOR UPDATE [SKIP LOCKED]
     *
     * 必须在事务内使用（配合 ConnectionInterface::runTransaction()），
     * 命中行加 X 锁，其它事务的锁定读与写操作会阻塞直到本事务提交。
     * skipLocked = true 时跳过已被锁住的行而不是等待。
     */
    public function forUpdate(bool $skipLocked = false): self
    {
        $this->forUpdate  = true;
        $this->skipLocked = $skipLocked;

        return $this;
    }

    // ==================================================================
    // 编译与执行（手动）
    // ==================================================================

    /**
     * 编译当前待执行语句，返回 [SQL, 参数]；不连接数据库、不执行
     *
     * 任何构造器实例都可以直接内嵌为子查询（JOIN 表 / IN / INSERT INTO ... SELECT）
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        return match ($this->pending['type']) {
            'insert' => $this->compileInsert($this->pending['data'], $this->pending['verb']),
            'upsert' => $this->compileUpsert($this->pending['data'], $this->pending['rules']),
            'update' => $this->compileUpdate($this->pending['data']),
            'delete' => $this->compileDelete(),
            default  => $this->compileSelect(),
        };
    }

    /**
     * 手动执行当前待执行语句
     *
     * SELECT 返回结果行；INSERT / UPDATE / DELETE 返回受影响行数。
     *
     * @return array<int, array<string, mixed>>|int
     *
     * @throws DBException
     */
    public function run(): array | int
    {
        [$sql, $params] = $this->toSql();
        $this->setLastSql($sql, $params);

        $isRead = $this->pending['type'] === 'select';

        if ($isRead) {
            return $this->connection()->query($sql, $params);
        }

        return $this->connection()->execute($sql, $params);
    }

    /**
     * 取第一行，无结果返回 null（显式执行）
     */
    public function first(): ?array
    {
        $rows = (clone $this)->limit(1)->run();

        return is_array($rows) ? ($rows[0] ?? null) : null;
    }

    /**
     * COUNT(*)（或指定字段），显式执行
     */
    public function count(string $column = '*'): int
    {
        $column = $column === '*' ? '*' : $this->columnRef($column);

        [$joinSql, $joinParams]   = $this->buildJoin();
        [$whereSql, $whereParams] = $this->buildWhere();

        $sql    = "SELECT COUNT({$column}) AS __aggregate FROM {$this->tableRef()}{$joinSql}{$whereSql}";
        $params = array_merge($joinParams, $whereParams);

        $this->setLastSql($sql, $params);

        $row = $this->connection()->query($sql, $params);

        return (int) ($row[0]['__aggregate'] ?? 0);
    }

    /**
     * 分页查询（显式执行，内部跑 count + 数据页两条 SQL）
     *
     * @return array{total: int, data: array, current_page: int, page_size: int, total_pages: int, has_more: bool}
     */
    public function paginate(int $page = 1, int $pageSize = 20): array
    {
        $total = (clone $this)->count();

        $list = (clone $this)->page($page, $pageSize)->run();

        return [
            'total'        => $total,
            'data'         => $list,
            'current_page' => $page,
            'page_size'    => $pageSize,
            'total_pages'  => (int) max(1, ceil($total / $pageSize)),
            'has_more'     => $page * $pageSize < $total,
        ];
    }

    /**
     * 编译 SELECT
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileSelect(): array
    {
        $columnsSql = $this->buildColumns();
        $table      = $this->tableRef();

        [$joinSql, $joinParams]   = $this->buildJoin();
        [$whereSql, $whereParams] = $this->buildWhere();
        $params                   = array_merge($joinParams, $whereParams);

        $sql = "SELECT {$columnsSql} FROM {$table}{$joinSql}{$whereSql}";

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($c) => $this->columnRef($c), $this->groupBy));
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;

            if ($this->offset > 0) {
                $sql .= ' OFFSET ' . $this->offset;
            }
        }

        if ($this->forUpdate) {
            $sql .= ' FOR UPDATE';

            if ($this->skipLocked) {
                $sql .= ' SKIP LOCKED';
            }
        }

        return [$sql, $params];
    }

    // ==================================================================
    // 待执行语句注册（只登记，不执行；统一由 run() 触发）
    // ==================================================================

    /**
     * 登记插入语句；传构造器实例时生成 INSERT INTO ... SELECT
     *
     * @param array|self $data 单行数组 / 多行二维数组 / 子查询构造器
     */
    public function insert(array | self $data): self
    {
        $this->pending = ['type' => 'insert', 'data' => $data, 'verb' => 'INSERT INTO'];

        return $this;
    }

    /**
     * 登记 INSERT IGNORE
     */
    public function insertIgnore(array | self $data): self
    {
        $this->pending = ['type' => 'insert', 'data' => $data, 'verb' => 'INSERT IGNORE INTO'];

        return $this;
    }

    /**
     * 登记 INSERT ... ON DUPLICATE KEY UPDATE
     *
     * @param array $data 插入数据
     * @param array $rules 更新规则：'字段'（等于 VALUES(字段)）/ '字段' => 值 / '字段' => Expr
     */
    public function upsert(array $data, array $rules): self
    {
        $this->pending = ['type' => 'upsert', 'data' => $data, 'rules' => $rules];

        return $this;
    }

    /**
     * 登记更新语句（run 时校验必须带 where）
     *
     * @param array<string, mixed|Expr> $data
     */
    public function update(array $data): self
    {
        $this->pending = ['type' => 'update', 'data' => $data];

        return $this;
    }

    /**
     * 登记删除语句（run 时校验必须带 where）
     */
    public function delete(): self
    {
        $this->pending = ['type' => 'delete'];

        return $this;
    }

    // ------------------------------------------------------------------
    // 编译（纯函数，不触碰连接）
    // ------------------------------------------------------------------

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileInsert(array | self $data, string $verb): array
    {
        $table = $this->tableRef();

        // 子查询
        if ($data instanceof self) {
            [$subSql, $subParams] = $data->toSql();

            $columnsSql = '';
            if ($this->columns !== [] && $this->columns !== ['*']) {
                $columnsSql = " ({$this->buildColumns()})";
            }

            return ["{$verb} {$table}{$columnsSql} ({$subSql})", $subParams];
        }

        // 批量：字段取所有行的并集，缺失的键绑 NULL
        if (isset($data[0]) && is_array($data[0])) {
            $fields = [];

            foreach ($data as $row) {
                foreach (array_keys($row) as $field) {
                    $fields[$field] = true;
                }
            }

            $fields = array_keys($fields);
            $params = [];

            foreach ($data as $row) {
                foreach ($fields as $field) {
                    $params[] = $row[$field] ?? null;
                }
            }

            $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';

            $sql = "{$verb} {$table} ({$this->columnList($fields)}) VALUES "
            . implode(', ', array_fill(0, count($data), $placeholders));

            return [$sql, $params];
        }

        // 单条
        $fields = array_keys($data);
        $params = array_values($data);

        $sql = "{$verb} {$table} ({$this->columnList($fields)}) VALUES ("
        . implode(', ', array_fill(0, count($fields), '?')) . ')';

        return [$sql, $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileUpsert(array $data, array $rules): array
    {
        [$insertSql, $params]       = $this->compileInsert($data, 'INSERT INTO');
        [$updateSql, $updateParams] = $this->buildUpsertClause($rules);

        return [$insertSql . $updateSql, array_merge($params, $updateParams)];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildUpsertClause(array $rules): array
    {
        if ($rules === []) {
            return ['', []];
        }

        $sets   = [];
        $params = [];

        foreach ($rules as $key => $value) {
            // 数字键 => 值是字段名，等价 ON DUPLICATE 时取插入值
            if (is_int($key) && is_string($value)) {
                $field  = $this->columnRef($value);
                $sets[] = "{$field} = VALUES({$field})";

                continue;
            }

            $field = $this->columnRef((string) $key);

            if ($value instanceof Expr) {
                $sets[] = "{$field} = {$value}";

                continue;
            }

            $sets[]   = "{$field} = ?";
            $params[] = $value;
        }

        return [' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets), $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileUpdate(array $data): array
    {
        [$whereSql, $whereParams] = $this->buildWhere();

        if ($whereSql === '') {
            throw new InsecureExecuteException('UPDATE requires a WHERE clause');
        }

        [$joinSql, $joinParams] = $this->buildJoin();

        $sets   = [];
        $params = [];

        foreach ($data as $field => $value) {
            $field = $this->columnRef((string) $field);

            if ($value instanceof Expr) {
                $sets[] = "{$field} = {$value}";

                continue;
            }

            if ($value === null) {
                $sets[] = "{$field} = NULL";

                continue;
            }

            $sets[]   = "{$field} = ?";
            $params[] = $value;
        }

        $params = array_merge($joinParams, $params, $whereParams);

        return ["UPDATE {$this->tableRef()}{$joinSql} SET " . implode(', ', $sets) . $whereSql, $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileDelete(): array
    {
        [$whereSql, $whereParams] = $this->buildWhere();

        if ($whereSql === '') {
            throw new InsecureExecuteException('DELETE requires a WHERE clause');
        }

        [$joinSql, $joinParams] = $this->buildJoin();
        $params                 = array_merge($joinParams, $whereParams);

        return ["DELETE FROM {$this->tableRef()}{$joinSql}{$whereSql}", $params];
    }

    // ==================================================================
    // 工具
    // ==================================================================

    private function connection(): ConnectionInterface
    {
        if (! isset($this->connection)) {
            throw new RuntimeException('QueryBuilder has no database connection. Use withConnection() or inject via constructor.');
        }

        return $this->connection;
    }

    public function lastSql(): ?array
    {
        if ($this->lastSql === '') {
            return null;
        }

        return [
            'raw'    => $this->lastSql,
            'params' => $this->lastParams,
        ];
    }

    private function setLastSql(string $sql, array $params): void
    {
        $this->lastSql    = $sql;
        $this->lastParams = $params;
    }

    /**
     * 字段引用：`` `table`.`field` ``，支持别名 as、`*`、数字字面量（如按序号排序）
     */
    private function columnRef(string $field): string
    {
        $field = trim($field);

        // 数字字面量（ORDER BY 1 等），原样输出
        if (preg_match('/^[+-]?[0-9]+(\.[0-9]+)?$/', $field)) {
            return $field;
        }

        if ($field === '*') {
            return '*';
        }

        // 别名拆分
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $field, $m)) {
            return $this->columnRef($m[1]) . ' AS ' . $this->columnRef($m[2]);
        }

        $parts = explode('.', $field);
        $sql   = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '*') {
                $sql[] = '*';

                continue;
            }

            if ($part === '') {
                throw new InvalidArgumentException('Field name must not be empty');
            }

            // 已带反引号
            if (str_starts_with($part, '`') && str_ends_with($part, '`')) {
                $sql[] = $part;

                continue;
            }

            if (! preg_match('/^[A-Za-z0-9_$]+$/', $part)) {
                throw new InvalidArgumentException(sprintf('Illegal field name `%s`', $part));
            }

            $sql[] = "`{$part}`";
        }

        return implode('.', $sql);
    }

    private function columnList(array $fields): string
    {
        return implode(', ', array_map(fn($f) => $this->columnRef((string) $f), $fields));
    }

    private function buildColumns(): string
    {
        if ($this->columns === [] || $this->columns === ['*']) {
            return '*';
        }

        $sql = [];

        foreach ($this->columns as $column) {
            if (is_array($column)) {
                // ['字段', '别名']
                if (count($column) !== 2) {
                    throw new InvalidArgumentException('Aliased field expects a [field, alias] tuple');
                }

                $sql[] = $this->columnRef((string) $column[0]) . ' AS ' . $this->columnRef((string) $column[1]);

                continue;
            }

            if ($column instanceof Expr) {
                $sql[] = (string) $column;

                continue;
            }

            $sql[] = $this->columnRef((string) $column);
        }

        return implode(', ', $sql);
    }

    private function tableRef(): string
    {
        if ($this->table === '') {
            throw new InvalidArgumentException('Query table is not specified. Call from() first.');

        }

        return $this->resolveTableName($this->table) . ($this->alias !== '' ? " AS `{$this->alias}`" : '');
    }

    /**
     * 解析表名为反引号包裹的标识符。
     *
     * 支持两种来源：
     *   - 普通表名 / tableMap 别名：按当前生效前缀拼接；
     *   - Model 类名（如 `App\Http\Models\Master\Users`）：自动解析为该模型自身的完整表名
     *     （含其自身前缀，sharky_ / boss_），不再叠加当前查询前缀——
     *     消除跨前缀 join 必须手写 `getFullTable()` 或 `crossPrefixQuery()` 的临时方案。
     */
    private function resolveTableName(string $table): string
    {
        $real = $this->isModelClass($table)
            ? $this->modelFullTable($table)
            : $this->resolvePrefix() . ($this->tableMap[$table] ?? $table);

        if (! preg_match('/^[A-Za-z0-9_$]+$/', $real)) {
            if (str_contains($table, '\\') && class_exists($table)) {
                throw new InvalidArgumentException(sprintf(
                    'Illegal table name `%s`: it looks like a class name but is not a Model. ' .
                    'Pass a Model class (resolved to its table) or the raw table name instead.',
                    $table
                ));
            }

            throw new InvalidArgumentException(sprintf('Illegal table name `%s`', $table));
        }

        return "`{$real}`";
    }

    private function resolveTable(string $table): string
    {
        return $this->resolveTableName($table);
    }

    private function isModelClass(string $name): bool
    {
        return class_exists($name) && is_subclass_of($name, Model::class);
    }

    private function modelFullTable(string $class): string
    {
        // 模型类自身已含前缀（SharkyModel 锁 sharky_ / 业务模型走配置 boss_），
        // 不再叠加当前查询前缀，避免跨前缀 join 出现 boss_sharky_users 这类叠加错误。
        return (new $class())->getFullTable();
    }

    /**
     * 解析当前生效的表前缀：
     *   - 已显式调用 setPrefix() 则以其为准；
     *   - 否则从已绑定的连接驱动取配置前缀；
     *   - 两者皆无则为空字符串。
     */
    private function resolvePrefix(): string
    {
        if ($this->prefixExplicit) {
            return $this->prefix;
        }

        if (isset($this->connection)) {
            return $this->connection->getPrefix();
        }

        return $this->prefix;
    }
}

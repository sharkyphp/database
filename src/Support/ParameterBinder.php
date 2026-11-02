<?php
/**
 * SharkyPHP Database Library
 *
 * 参数绑定辅助：把上层传入的参数转换成各驱动可用的绑定形式
 *
 * @package SharkyPHP\\Database
 * @author Sharky
 * @copyright 2026 SharkyPHP
 * @license MIT
 * @version 2.0.0
 * @internal
 */
declare (strict_types = 1);

namespace Sharky\Database\Support;

use InvalidArgumentException;

/**
 * 支持两种占位符写法：
 *  - 位置参数：['a', 'b']                      对应 ?, ?
 *  - 命名参数：[':id' => 1, 'name' => 'x']     冒号可省略
 *
 * 命名参数会按其在 SQL 中出现的顺序重排为位置列表，
 * 因为 mysqli（bind_param）与关闭模拟预处理后的 PDO 底层都是按位置绑定。
 */
final class ParameterBinder
{
    /** 匹配「字面量」或「命名占位符」：字面量命中落到 $m[1]，占位符命中落到 $m[2] */
    private const PLACEHOLDER_PATTERN =
        '/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`[^`]*`|\\?)'
        . '|(?<!:):([A-Za-z_][A-Za-z0-9_]*)/';

    private function __construct()
    {}

    public static function isNamed(array $params): bool
    {
        foreach ($params as $key => $_) {
            return is_string($key);
        }

        return false;
    }

    /**
     * 校验并归一化为位置参数列表
     *
     * @return list<string|int|float|bool|null>
     *
     * @throws InvalidArgumentException
     */
    public static function positional(string $sql, array $params): array
    {
        self::assertValues($params);

        if ($params === []) {
            return [];
        }

        if (! self::isNamed($params)) {
            return array_values($params);
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[str_starts_with($key, ':') ? $key : ':' . $key] = $value;
        }

        $ordered = [];
        foreach (self::placeholderNames($sql) as $name) {
            $name = ':' . $name;

            if (! array_key_exists($name, $normalized)) {
                throw new InvalidArgumentException(sprintf("Missing value for placeholder '%s'", $name));
            }

            $ordered[] = $normalized[$name];
            unset($normalized[$name]);
        }

        if ($normalized !== []) {
            throw new InvalidArgumentException(
                sprintf('Provided parameter(s) not found in SQL: %s', implode(', ', array_keys($normalized)))
            );
        }

        return $ordered;
    }

    /**
     * 按出现顺序提取命名占位符（跳过字符串 / 反引号字面量与 '::' 类型转换）
     *
     * @return list<string>
     */
    public static function placeholderNames(string $sql): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $sql, $matches, PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $match) {
            if (($match[1] ?? '') === '' && ($match[2] ?? '') !== '') {
                $names[] = $match[2];
            }
        }

        return $names;
    }

    /**
     * 把命名占位符改写成位置占位符 '?'，供不支持命名参数的驱动（mysqli）使用
     *
     * @return array{0: string, 1: list<string|int|float|bool|null>}
     *
     * @throws InvalidArgumentException
     */
    public static function convertNamedToPositional(string $sql, array $params): array
    {
        $positional = self::positional($sql, $params);

        $converted = preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $m): string {
                // $m[1] 命中字面量（引号串 / 反引号标识符 / 已有 '?'），原样保留
                return ($m[1] ?? '') !== '' ? $m[1] : '?';
            },
            $sql
        );

        if ($converted === null) {
            throw new InvalidArgumentException('Unable to parse named placeholders in SQL');
        }

        return [$converted, $positional];
    }

    /**
     * @param array<string|int, mixed> $params
     *
     * @throws InvalidArgumentException
     */
    private static function assertValues(array $params): void
    {
        foreach ($params as $key => $value) {
            if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                continue;
            }

            throw new InvalidArgumentException(
                sprintf("Parameter '%s' expects string or numeric value, given: %s", $key, get_debug_type($value))
            );
        }
    }
}

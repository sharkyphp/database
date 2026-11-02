<?php

/**
 * SharkyPHP Database Library
 *
 * SQL 表达式：把一段字符串明确标记为「原样输出」，与普通字符串值区分
 *
 * 用法：
 *   $qb->_update(['balance' => new Expr('balance + 100')]);
 *   $qb->_where(['score', '>', new Expr('score * 2')]);
 *
 * @package SharkyPHP\Database\Query
 */
declare (strict_types = 1);

namespace Sharky\Database\Query;

final class Expr
{
    public function __construct(private string $expression)
    {
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}

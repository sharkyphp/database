<?php

/**
 * 不安全的写操作异常：UPDATE / DELETE 缺少 WHERE 条件时抛出，
 * 防止误改 / 误删整张表。
 *
 * @author Sharky
 */
declare (strict_types = 1);

namespace Sharky\Database\Exceptions;

use RuntimeException;

class InsecureExecuteException extends RuntimeException
{
}

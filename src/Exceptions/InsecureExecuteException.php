<?php

/**
 * 不安全的写操作异常：UPDATE / DELETE 缺少 WHERE 条件时抛出，
 * 防止误改 / 误删整张表。
 *
 * @author Sharky
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Database\Exceptions;

use RuntimeException;

class InsecureExecuteException extends RuntimeException
{
}

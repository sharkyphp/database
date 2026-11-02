<?php
/**
 * 数据库异常
 * 
 * @author Sharky
 * @version 2.0.0
 */
declare (strict_types = 1);

namespace Sharky\Database\Exceptions;

use Exception;
use mysqli_sql_exception;
use PDOException;
use Throwable;

class DBException extends Exception
{

    private string $sqlState = '';

    public static function transform(Throwable $dbException): Throwable
    {
        if ($dbException instanceof mysqli_sql_exception) {
            $sqlState = $dbException->getSqlState();
            $errno    = (int) ($dbException->getCode());
            $rawMsg   = $dbException->getMessage();

        } elseif ($dbException instanceof PDOException) {
            $sqlState = $dbException->getCode();
            $errno    = (int) ($dbException->errorInfo[1] ?? 0);
            $rawMsg   = $dbException->errorInfo[2] ?? '';

        } else {
            // 非数据库异常原样抛出
            return $dbException;
        }

        $dbErr           = new self($rawMsg, $errno, $dbException);
        $dbErr->sqlState = $sqlState;
        return $dbErr;
    }

    public function getSqlState(): string
    {
        return $this->sqlState;
    }
}

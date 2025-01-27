<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use Throwable;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Driver\PDO\TransactionPDO as AbstractTransactionPDO;

final class TransactionPDO extends AbstractTransactionPDO
{
    /**
     * Creates a new savepoint.
     *
     * @param string $name the savepoint name.
     *
     * @throws Exception|InvalidConfigException|Throwable
     */
    public function createSavepoint(string $name): void
    {
        $this->db->createCommand("SAVE TRANSACTION $name")->execute();
    }

    /**
     * Releases an existing savepoint.
     *
     * @param string $name the savepoint name.
     *
     * @throws NotSupportedException
     */
    public function releaseSavepoint(string $name): void
    {
        // does nothing as MSSQL does not support this
    }

    /**
     * Rolls back to a previously created savepoint.
     *
     * @param string $name the savepoint name.
     *
     * @throws Exception|InvalidConfigException|Throwable
     */
    public function rollBackSavepoint(string $name): void
    {
        $this->db->createCommand("ROLLBACK TRANSACTION $name")->execute();
    }
}

<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use JsonException;
use Yiisoft\Db\Constraint\Constraint;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\QueryBuilder\DMLQueryBuilder as AbstractDMLQueryBuilder;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;

use function implode;
use function in_array;
use function ltrim;
use function strrpos;
use function is_array;
use function reset;

final class DMLQueryBuilder extends AbstractDMLQueryBuilder
{
    public function __construct(
        private QueryBuilderInterface $queryBuilder,
        private QuoterInterface $quoter,
        private SchemaInterface $schema
    ) {
        parent::__construct($queryBuilder, $quoter, $schema);
    }

    /**
     * @throws Exception|InvalidArgumentException|InvalidConfigException|NotSupportedException
     */
    public function insertEx(string $table, QueryInterface|array $columns, array &$params = []): string
    {
        /**
         * @psalm-var string[] $names
         * @psalm-var string[] $placeholders
         */
        [$names, $placeholders, $values, $params] = $this->prepareInsertValues($table, $columns, $params);

        $sql = 'INSERT INTO '
            . $this->quoter->quoteTableName($table)
            . (!empty($names) ? ' (' . implode(', ', $names) . ')' : '')
            . ' OUTPUT INSERTED.* INTO @temporary_inserted'
            . (!empty($placeholders) ? ' VALUES (' . implode(', ', $placeholders) . ')' : (string) $values);

        $cols = [];
        $tableSchema = $this->schema->getTableSchema($table);
        $returnColumns = $tableSchema?->getColumns() ?? [];
        foreach ($returnColumns as $returnColumn) {
            $cols[] = $this->quoter->quoteColumnName($returnColumn->getName()) . ' '
                . $returnColumn->getDbType()
                . (in_array(
                    $returnColumn->getDbType(),
                    ['char', 'varchar', 'nchar', 'nvarchar', 'binary', 'varbinary']
                ) ? '(MAX)' : '')
                . ' ' . ($returnColumn->isAllowNull() ? 'NULL' : '');
        }

        return 'SET NOCOUNT ON;DECLARE @temporary_inserted TABLE (' . implode(', ', $cols) . ');'
            . $sql . ';SELECT * FROM @temporary_inserted';
    }

    /**
     * @throws InvalidArgumentException
     */
    public function resetSequence(string $tableName, int|string $value = null): string
    {
        $table = $this->schema->getTableSchema($tableName);

        if ($table !== null && $table->getSequenceName() !== null) {
            $tableName = $this->quoter->quoteTableName($tableName);

            if ($value === null) {
                $pk = $table->getPrimaryKey();
                $key = $this->quoter->quoteColumnName(reset($pk));
                $value = "(SELECT COALESCE(MAX($key),0) FROM $tableName)+1";
            }

            return "DBCC CHECKIDENT ('$tableName', RESEED, $value)";
        }

        throw new InvalidArgumentException("There is not sequence associated with table '$tableName'.");
    }

    /**
     * @throws Exception|InvalidArgumentException|InvalidConfigException|JsonException|NotSupportedException
     */
    public function upsert(
        string $table,
        QueryInterface|array $insertColumns,
        bool|array $updateColumns,
        array &$params = []
    ): string {
        /** @psalm-var Constraint[] $constraints */
        $constraints = [];

        /** @psalm-var string[] $insertNames */
        [$uniqueNames, $insertNames, $updateNames] = $this->prepareUpsertColumns(
            $table,
            $insertColumns,
            $updateColumns,
            $constraints
        );

        if (empty($uniqueNames)) {
            return $this->insert($table, $insertColumns, $params);
        }

        $onCondition = ['or'];
        $quotedTableName = $this->quoter->quoteTableName($table);

        foreach ($constraints as $constraint) {
            $constraintCondition = ['and'];

            $columnNames = $constraint->getColumnNames() ?? [];

            if (is_array($columnNames)) {
                /** @psalm-var string[] $columnNames */
                foreach ($columnNames as $name) {
                    $quotedName = $this->quoter->quoteColumnName($name);
                    $constraintCondition[] = "$quotedTableName.$quotedName=[EXCLUDED].$quotedName";
                }
            }

            $onCondition[] = $constraintCondition;
        }

        $on = $this->queryBuilder->buildCondition($onCondition, $params);

        /** @psalm-var string[] $placeholders */
        [, $placeholders, $values, $params] = $this->prepareInsertValues($table, $insertColumns, $params);
        $mergeSql = 'MERGE ' . $this->quoter->quoteTableName($table) . ' WITH (HOLDLOCK) '
            . 'USING (' . (!empty($placeholders)
            ? 'VALUES (' . implode(', ', $placeholders) . ')'
            : ltrim((string) $values, ' ')) . ') AS [EXCLUDED] (' . implode(', ', $insertNames) . ') ' . "ON ($on)";
        $insertValues = [];

        foreach ($insertNames as $name) {
            $quotedName = $this->quoter->quoteColumnName($name);

            if (strrpos($quotedName, '.') === false) {
                $quotedName = '[EXCLUDED].' . $quotedName;
            }

            $insertValues[] = $quotedName;
        }

        $insertSql = 'INSERT (' . implode(', ', $insertNames) . ')' . ' VALUES (' . implode(', ', $insertValues) . ')';

        if ($updateColumns === false) {
            return "$mergeSql WHEN NOT MATCHED THEN $insertSql;";
        }

        if ($updateColumns === true) {
            $updateColumns = [];

            /** @psalm-var string[] $updateNames */
            foreach ($updateNames as $name) {
                $quotedName = $this->quoter->quoteColumnName($name);
                if (strrpos($quotedName, '.') === false) {
                    $quotedName = '[EXCLUDED].' . $quotedName;
                }

                $updateColumns[$name] = new Expression($quotedName);
            }
        }

        /**
         * @var array $params
         * @psalm-var string[] $updates
         * @psalm-var array<string, ExpressionInterface|string> $updateColumns
         */
        [$updates, $params] = $this->prepareUpdateSets($table, $updateColumns, $params);
        $updateSql = 'UPDATE SET ' . implode(', ', $updates);

        return "$mergeSql WHEN MATCHED THEN $updateSql WHEN NOT MATCHED THEN $insertSql;";
    }
}

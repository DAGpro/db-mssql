<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use Exception;
use Throwable;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\QueryBuilder\DDLQueryBuilder as AbstractDDLQueryBuilder;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\ColumnSchemaBuilder;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;

use function array_diff;

final class DDLQueryBuilder extends AbstractDDLQueryBuilder
{
    public function __construct(
        private QueryBuilderInterface $queryBuilder,
        private QuoterInterface $quoter,
        private SchemaInterface $schema
    ) {
        parent::__construct($queryBuilder, $quoter, $schema);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function addCommentOnColumn(string $table, string $column, string $comment): string
    {
        return $this->buildAddCommentSql($comment, $table, $column);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function addCommentOnTable(string $table, string $comment): string
    {
        return $this->buildAddCommentSql($comment, $table);
    }

    /**
     * @throws Exception
     */
    public function addDefaultValue(string $name, string $table, string $column, mixed $value): string
    {
        return 'ALTER TABLE '
            . $this->quoter->quoteTableName($table)
            . ' ADD CONSTRAINT '
            . $this->quoter->quoteColumnName($name)
            . ' DEFAULT ' . (string) $this->quoter->quoteValue($value)
            . ' FOR ' . $this->quoter->quoteColumnName($column);
    }

    public function alterColumn(string $table, string $column, ColumnSchemaBuilder|string $type): string
    {
        return 'ALTER TABLE '
            . $this->quoter->quoteTableName($table)
            . ' ALTER COLUMN '
            . $this->quoter->quoteColumnName($column)
            . ' '
            . $this->queryBuilder->getColumnType($type);
    }

    /**
     * @throws InvalidConfigException|NotSupportedException|Throwable|\Yiisoft\Db\Exception\Exception
     */
    public function checkIntegrity(string $schema = '', string $table = '', bool $check = true): string
    {
        $enable = $check ? 'CHECK' : 'NOCHECK';

        /** @var Schema */
        $schemaInstance = $this->schema;
        $defaultSchema = $schema ?: $schemaInstance->getDefaultSchema() ?? '';
        /** @psalm-var string[] */
        $tableNames = $schemaInstance->getTableSchema($table)
             ? [$table] : $schemaInstance->getTableNames($defaultSchema);
        $viewNames = $schemaInstance->getViewNames($defaultSchema);
        $tableNames = array_diff($tableNames, $viewNames);
        $command = '';

        foreach ($tableNames as $tableName) {
            $tableName = $this->quoter->quoteTableName("$defaultSchema.$tableName");
            $command .= "ALTER TABLE $tableName $enable CONSTRAINT ALL; ";
        }

        return $command;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function dropCommentFromColumn(string $table, string $column): string
    {
        return $this->buildRemoveCommentSql($table, $column);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function dropCommentFromTable(string $table): string
    {
        return $this->buildRemoveCommentSql($table);
    }

    public function dropDefaultValue(string $name, string $table): string
    {
        return 'ALTER TABLE '
            . $this->quoter->quoteTableName($table)
            . ' DROP CONSTRAINT '
            . $this->quoter->quoteColumnName($name);
    }

    public function renameTable(string $oldName, string $newName): string
    {
        return 'sp_rename '
            . $this->quoter->quoteTableName($oldName) . ', '
            . $this->quoter->quoteTableName($newName);
    }

    public function renameColumn(string $table, string $oldName, string $newName): string
    {
        return 'sp_rename '
            . $this->quoter->quoteTableName($table) . '.'
            . $this->quoter->quoteColumnName($oldName) . ', '
            . $this->quoter->quoteColumnName($newName)
            . ' COLUMN';
    }

    /**
     * Builds a SQL command for adding or updating a comment to a table or a column. The command built will check if a
     * comment already exists. If so, it will be updated, otherwise, it will be added.
     *
     * @param string $comment the text of the comment to be added. The comment will be properly quoted by the method.
     * @param string $table the table to be commented or whose column is to be commented. The table name will be
     * properly quoted by the method.
     * @param string|null $column optional. The name of the column to be commented. If empty, the command will add the
     * comment to the table instead. The column name will be properly quoted by the method.
     *
     * @throws Exception|InvalidArgumentException if the table does not exist.
     *
     * @return string the SQL statement for adding a comment.
     */
    private function buildAddCommentSql(string $comment, string $table, string $column = null): string
    {
        $tableSchema = $this->schema->getTableSchema($table);

        if ($tableSchema === null) {
            throw new InvalidArgumentException("Table not found: $table");
        }

        $schemaName = $tableSchema->getSchemaName()
            ? "N'" . (string) $tableSchema->getSchemaName() . "'" : 'SCHEMA_NAME()';
        $tableName = 'N' . (string) $this->quoter->quoteValue($tableSchema->getName());
        $columnName = $column ? 'N' . (string) $this->quoter->quoteValue($column) : null;
        $comment = 'N' . (string) $this->quoter->quoteValue($comment);
        $functionParams = "
            @name = N'MS_description',
            @value = $comment,
            @level0type = N'SCHEMA', @level0name = $schemaName,
            @level1type = N'TABLE', @level1name = $tableName"
            . ($column ? ", @level2type = N'COLUMN', @level2name = $columnName" : '') . ';';

        return "
            IF NOT EXISTS (
                    SELECT 1
                    FROM fn_listextendedproperty (
                        N'MS_description',
                        'SCHEMA', $schemaName,
                        'TABLE', $tableName,
                        " . ($column ? "'COLUMN', $columnName " : ' DEFAULT, DEFAULT ') . "
                    )
            )
                EXEC sys.sp_addextendedproperty $functionParams
            ELSE
                EXEC sys.sp_updateextendedproperty $functionParams
        ";
    }

    /**
     * Builds a SQL command for removing a comment from a table or a column. The command built will check if a comment
     * already exists before trying to perform the removal.
     *
     * @param string $table the table that will have the comment removed or whose column will have the comment removed.
     * The table name will be properly quoted by the method.
     * @param string|null $column optional. The name of the column whose comment will be removed. If empty, the command
     * will remove the comment from the table instead. The column name will be properly quoted by the method.
     *
     * @throws Exception|InvalidArgumentException if the table does not exist.
     *
     * @return string the SQL statement for removing the comment.
     */
    private function buildRemoveCommentSql(string $table, string $column = null): string
    {
        $tableSchema = $this->schema->getTableSchema($table);

        if ($tableSchema === null) {
            throw new InvalidArgumentException("Table not found: $table");
        }

        $schemaName = $tableSchema->getSchemaName()
            ? "N'" . (string) $tableSchema->getSchemaName() . "'" : 'SCHEMA_NAME()';
        $tableName = 'N' . (string) $this->quoter->quoteValue($tableSchema->getName());
        $columnName = $column ? 'N' . (string) $this->quoter->quoteValue($column) : null;

        return "
            IF EXISTS (
                    SELECT 1
                    FROM fn_listextendedproperty (
                        N'MS_description',
                        'SCHEMA', $schemaName,
                        'TABLE', $tableName,
                        " . ($column ? "'COLUMN', $columnName " : ' DEFAULT, DEFAULT ') . "
                    )
            )
                EXEC sys.sp_dropextendedproperty
                    @name = N'MS_description',
                    @level0type = N'SCHEMA', @level0name = $schemaName,
                    @level1type = N'TABLE', @level1name = $tableName"
                    . ($column ? ", @level2type = N'COLUMN', @level2name = $columnName" : '') . ';';
    }
}

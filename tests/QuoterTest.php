<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\Tests;

use Yiisoft\Db\TestSupport\TestQuoterTrait;

/**
 * @group mssql
 */
final class QuoterTest extends TestCase
{
    use TestQuoterTrait;

    /**
     * @return string[][]
     */
    public function simpleTableNamesProvider(): array
    {
        return [
            ['test', 'test', ],
            ['te`st', 'te`st', ],
            ['te\'st', 'te\'st', ],
            ['te"st', 'te"st', ],
            ['current-table-name', 'current-table-name', ],
            ['[current-table-name]', 'current-table-name', ],
        ];
    }

    /**
     * @return string[][]
     */
    public function simpleColumnNamesProvider(): array
    {
        return [
            ['test', '[test]', 'test'],
            ['[test]', '[test]', 'test'],
            ['*', '*', '*'],
        ];
    }

    /**
     * @return string[][]
     */
    public function columnNamesProvider(): array
    {
        return [
            ['*', '*'],
            ['table.*', '[table].*'],
            ['[table].*', '[table].*'],
            ['table.column', '[table].[column]'],
            ['[table].column', '[table].[column]'],
            ['table.[column]', '[table].[column]'],
            ['[table].[column]', '[table].[column]'],
        ];
    }
}

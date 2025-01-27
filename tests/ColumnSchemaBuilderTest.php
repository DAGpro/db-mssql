<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\Tests;

use Yiisoft\Db\TestSupport\TestColumnSchemaBuilderTrait;

/**
 * @group mssql
 */
final class ColumnSchemaBuilderTest extends TestCase
{
    use TestColumnSchemaBuilderTrait;

    /**
     * @dataProvider typesProviderTrait
     */
    public function testCustomTypes(string $expected, string $type, ?int $length, mixed $calls): void
    {
        $this->checkBuildString($expected, $type, $length, $calls);
    }
}

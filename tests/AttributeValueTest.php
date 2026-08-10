<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Classes\AttributeValue;

class AttributeValueTest extends TestCase
{
    /**
     * @dataProvider scalarLikeValues
     * @param mixed $value
     */
    public function testScalarLikeValuesCastToString($value, string $expected): void
    {
        $this->assertSame($expected, AttributeValue::stringify($value));
    }

    public function scalarLikeValues(): array
    {
        return [
            'int' => [42, '42'],
            'float' => [3.5, '3.5'],
            'true' => [true, '1'],
            'false' => [false, ''],
            'string' => ['hi', 'hi'],
            'null' => [null, ''],
        ];
    }

    public function testStringableObjectUsesToString(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'stringified';
            }
        };

        $this->assertSame('stringified', AttributeValue::stringify($obj));
    }

    public function testArrayIsJsonEncoded(): void
    {
        $this->assertSame('["acc-1","acc-2"]', AttributeValue::stringify(['acc-1', 'acc-2']));
        $this->assertSame('{"a":1,"b":2}', AttributeValue::stringify(['a' => 1, 'b' => 2]));
    }

    public function testPlainObjectIsJsonEncoded(): void
    {
        $obj = new \stdClass();
        $obj->id = 7;

        $this->assertSame('{"id":7}', AttributeValue::stringify($obj));
    }

    public function testPartialContentSurvivesOneBadNestedElement(): void
    {
        // A single unencodable leaf (malformed UTF-8) must not collapse the
        // whole label — the good parts are kept, only the bad leaf goes null.
        $encoded = AttributeValue::stringify(['accountIds' => ['acc-1', "\xB1"]]);

        $this->assertSame('{"accountIds":["acc-1",null]}', $encoded);
    }

    public function testTopLevelUnencodableValueBecomesNullNotPlaceholder(): void
    {
        // Under PARTIAL_OUTPUT an unencodable value never throws or returns
        // false; a top-level one (e.g. a resource) encodes as "null". We keep
        // a string either way rather than losing the label to an exception.
        $this->assertSame('null', AttributeValue::stringify(fopen('php://memory', 'r')));
    }
}

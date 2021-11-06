<?php

declare(strict_types=1);

namespace IAmBod\Serializer\Xlsx\Test\Encoder;

use IAmBod\Serializer\Xlsx\Encoder\XlsxEncoder;
use PHPUnit\Framework\TestCase;

class XlsxEncoderTest extends TestCase
{
    /**
     * @var XlsxEncoder
     */
    private $encoder;

    protected function setUp(): void
    {
        $this->encoder = new XlsxEncoder();
    }

    public function testEncodeDecode()
    {
        $data = [
            'string' => 'foo',
            'int' => 2,
            'false' => false,
            'true' => true,
            'int_one' => 1,
            'string_one' => '1',
        ];

        $encoded = $this->encoder->encode($data, 'xlsx');

        $decoded = $this->encoder->decode($encoded, 'xlsx', [
            XlsxEncoder::AS_COLLECTION_KEY => false
        ]);

        $this->assertSame([
            'string' => 'foo',
            'int' => 2,
            'false' => 0,
            'true' => 1,
            'int_one' => 1,
            'string_one' => 1,
        ], $decoded);
    }

    public function testEncodeAndDecodeCollection()
    {
        $value = [
            [
                'foo' => 'hello',
                'bar' => 'hey ho'
            ],
            [
                'foo' => 'hi',
                'bar' => 'let\'s go'
            ],
        ];

        $encoded = $this->encoder->encode($value, 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame($value, $decoded);
    }

    public function testEncodeAndDecodePlainIndexedArray()
    {
        $value = [
            'a',
            'b',
            'c',
            'd'
        ];

        $encoded = $this->encoder->encode($value, 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([$value], $decoded);
    }

    public function testEncodeAndDecodeNonArray()
    {
        $encoded = $this->encoder->encode('foo', 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([['foo']], $decoded);
    }

    public function testEncodeNestedArrays()
    {
        $value = [
            'foo' => 'hello',
            'bar' => [
                [
                    'id' => 'yo',
                    1 => 'wesh'
                ],
                [
                    'baz' => 'Halo',
                    'foo' => 'olá'
                ],
            ]
        ];

        $encoded = $this->encoder->encode($value, 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([
            [
                'foo' => 'hello',
                'bar.0.id' => 'yo',
                'bar.0.1' => 'wesh',
                'bar.1.baz' => 'Halo',
                'bar.1.foo' => 'olá'
            ]
        ], $decoded);
    }

    public function testEncodeAndDecodeEmptyArray()
    {
        $encoded = $this->encoder->encode([], 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([], $decoded);
    }

    public function testDecodeEmptyArray()
    {
        $this->assertEquals([], $this->encoder->decode('', 'xlsx'));
    }

    public function testEncodeAndDecodeVariableStructure()
    {
        $value = [
            [
                'a' => [
                    'foo', 'bar'
                ]
            ],
            [
                'a' => [],
                'b' => 'baz'
            ],
            [
                'a' => ['bar', 'foo'],
                'c' => 'pong'
            ],
        ];

        $encoded = $this->encoder->encode($value, 'xlsx');
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([
            [
                'a.0' => 'foo',
                'a.1' => 'bar',
                'c' => null,
                'b' => null
            ],
            [
                'a.0' => null,
                'a.1' => null,
                'c' => null,
                'b' => 'baz'
            ],
            [
                'a.0' => 'bar',
                'a.1' => 'foo',
                'c' => 'pong',
                'b' => null
            ]
        ], $decoded);
    }

    public function testEncodeAndDecodeCustomHeaders()
    {
        $value = [
            [
                'a' => 'foo',
                'b' => 'bar'
            ]
        ];

        $encoded = $this->encoder->encode($value, 'xlsx', [
            XlsxEncoder::HEADERS_KEY => ['b', 'c']
        ]);
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([
            [
                'b' => 'bar',
                'c' => null
            ]
        ], $decoded);
    }

    public function testEncodeAndDecodeCustomMappedHeaders()
    {
        $value = [
            [
                'foo' => 'hello',
                'bar' => 'hey ho'
            ],
            [
                'foo' => 'hi',
                'bar' => 'let\'s go'
            ],
        ];

        $encoded = $this->encoder->encode($value, 'xlsx', [
            XlsxEncoder::HEADERS_KEY => [
                'foo' => 'a',
                'bar' => 'b'
            ]
        ]);
        $decoded = $this->encoder->decode($encoded, 'xlsx');

        $this->assertSame([
            [
                'a' => 'hello',
                'b' => 'hey ho'
            ],
            [
                'a' => 'hi',
                'b' => 'let\'s go'
            ],
        ], $decoded);
    }

    public function testSupportEncoding()
    {
        $this->assertTrue($this->encoder->supportsEncoding('xlsx'));
        $this->assertFalse($this->encoder->supportsEncoding('foo'));
    }

    public function testSupportDecoding()
    {
        $this->assertTrue($this->encoder->supportsDecoding('xlsx'));
        $this->assertFalse($this->encoder->supportsDecoding('foo'));
    }
}
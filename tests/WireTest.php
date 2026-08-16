<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Sketch;
use Sixty\Sql;

/**
 * The wire format, checked against the implementation that reads it.
 *
 * A roundtrip test proves the PHP writer agrees with the PHP reader, which is
 * exactly the property a wire-format bug preserves. These assert against bytes
 * and strings produced by packages/core — the code the collector actually
 * decodes and names with.
 */
final class WireTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixtures(): array
    {
        static $fixtures = null;
        $fixtures ??= json_decode((string) file_get_contents(__DIR__ . '/fixtures/wire.json'), true);

        return $fixtures;
    }

    public function testSketchesSerializeByteIdenticallyToTheJavaScriptWriter(): void
    {
        foreach (self::fixtures()['sketches'] as $case) {
            $sketch = new Sketch();
            foreach ($case['values'] as $value) {
                $sketch->add((float) $value);
            }

            $this->assertSame(
                $case['base64'],
                $sketch->toBase64(),
                "sketch bytes differ from the JavaScript writer for: {$case['name']}",
            );
        }
    }

    public function testQuantilesMatchTheJavaScriptReader(): void
    {
        foreach (self::fixtures()['sketches'] as $case) {
            if ($case['values'] === []) {
                continue;
            }
            $sketch = new Sketch();
            foreach ($case['values'] as $value) {
                $sketch->add((float) $value);
            }

            $this->assertEqualsWithDelta($case['p50'], $sketch->quantile(0.5), 1e-9, $case['name']);
            $this->assertEqualsWithDelta($case['p95'], $sketch->quantile(0.95), 1e-9, $case['name']);
            $this->assertEqualsWithDelta($case['count'], $sketch->count(), 1e-9, $case['name']);
            $this->assertEqualsWithDelta($case['sum'], $sketch->sum(), 1e-9, $case['name']);
        }
    }

    public function testSketchDecodesWhatItEncodes(): void
    {
        $sketch = new Sketch();
        foreach ([0, 0.5, 3, 17, 1200, 90000] as $value) {
            $sketch->add((float) $value);
        }

        $decoded = Sketch::fromBinary($sketch->toBinary());

        $this->assertSame($sketch->count(), $decoded->count());
        $this->assertEqualsWithDelta($sketch->sum(), $decoded->sum(), 1e-9);
        $this->assertEqualsWithDelta($sketch->quantile(0.95), $decoded->quantile(0.95), 1e-9);
    }

    /**
     * The property the cross-request buffer depends on: two requests that each
     * measured part of a window produce, when merged, exactly what one process
     * measuring both would have reported.
     */
    public function testMergeIsLossless(): void
    {
        $left = new Sketch();
        $right = new Sketch();
        $combined = new Sketch();

        for ($i = 1; $i <= 200; $i++) {
            $left->add($i * 1.5);
            $combined->add($i * 1.5);
        }
        for ($i = 1; $i <= 200; $i++) {
            $right->add($i * 7.25);
            $combined->add($i * 7.25);
        }

        $this->assertSame($combined->toBase64(), $left->merge($right)->toBase64());
        $this->assertSame(
            $combined->toBase64(),
            Sketch::mergeBase64([(new Sketch())->toBase64(), $combined->toBase64()])?->toBase64(),
        );
    }

    public function testNormalizationMatchesTheJavaScriptAgent(): void
    {
        foreach (self::fixtures()['sql'] as $case) {
            $this->assertSame(
                $case['normalized'],
                Sql::normalize($case['raw']),
                "normalization differs for: {$case['raw']}",
            );
            $this->assertSame(
                $case['name'],
                Sql::operationName($case['normalized']),
                "operation name differs for: {$case['normalized']}",
            );
        }
    }

    public function testMysqlNormalizationMatchesTheJavaScriptAgent(): void
    {
        foreach (self::fixtures()['mysql'] as $case) {
            $this->assertSame(
                $case['normalized'],
                Sql::normalize($case['raw'], Sql::MYSQL),
                "mysql normalization differs for: {$case['raw']}",
            );
            $this->assertSame($case['name'], Sql::operationName($case['normalized']));
        }
    }

    public function testPathTemplatingMatchesTheJavaScriptAgent(): void
    {
        foreach (self::fixtures()['paths'] as $case) {
            $this->assertSame($case['templated'], Sql::templatePath($case['path']), $case['path']);
        }
    }

    /**
     * The rule the whole privacy story rests on. If one of these ever fails, a
     * customer's data is leaving their network.
     */
    public function testNoLiteralSurvivesNormalization(): void
    {
        $statements = [
            "select * from users where email = 'alice@example.com'",
            "select * from cards where pan = '4111111111111111' and cvv = 123",
            "insert into audit (actor, note) values ('root', 'deleted account 51')",
            'select * from t where token = $$sk_live_abcdef$$',
            'select * from t where a = 1.5e10 and b = 0xdeadbeef',
        ];

        foreach ($statements as $sql) {
            $normalized = Sql::normalize($sql);
            $this->assertDoesNotMatchRegularExpression(
                '/alice|4111|root|deleted account|sk_live|deadbeef|123/',
                $normalized,
                $sql,
            );
        }
    }

    /**
     * The one that would be a data leak if the dialect were wrong: in MySQL's
     * default sql_mode these bytes are a string literal, not a column name.
     */
    public function testMysqlDoubleQuotedValuesAreDataNotIdentifiers(): void
    {
        $sql = 'select * from users where email = "alice@example.com"';

        $this->assertStringNotContainsString('alice', Sql::normalize($sql, Sql::MYSQL));
        $this->assertStringContainsString('alice', Sql::normalize($sql, Sql::POSTGRES));
    }

    public function testValueFreeSeesTheDifferenceBetweenAParameterAndAValue(): void
    {
        $this->assertTrue(Sql::valueFree('select * from orders where id = $1'));
        $this->assertTrue(Sql::valueFree('select count(*) from orders'));
        $this->assertFalse(Sql::valueFree("select * from orders where email = 'a@b.c'"));
        $this->assertFalse(Sql::valueFree('select * from orders where id = 42'));
    }

    public function testNormalizationNeverThrowsOnMalformedInput(): void
    {
        foreach (["select * from t where a = 'unterminated", 'with as as ((((', '', '/* unclosed', '$$'] as $sql) {
            $this->assertIsString(Sql::normalize($sql));
        }
    }
}

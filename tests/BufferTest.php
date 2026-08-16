<?php

declare(strict_types=1);

namespace Sixty\Tests;

use PHPUnit\Framework\TestCase;
use Sixty\Buffer;
use Sixty\Sketch;

/**
 * The part of this agent that exists only because PHP is share-nothing.
 *
 * Every other agent in this repository keeps a rollup in memory and flushes it
 * on a timer. A PHP worker cannot: what it learned dies with the request. So
 * windows go to shared memory and one request in each interval sends them —
 * and the merge that makes that legitimate is what these tests are about. If
 * merging were lossy, the whole design would be trading correct numbers for
 * fewer HTTP requests.
 */
final class BufferTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Buffer::available()) {
            $this->markTestSkipped('APCu is not enabled (apc.enable_cli=1 needed for the CLI)');
        }
        Buffer::clear();
    }

    protected function tearDown(): void
    {
        Buffer::clear();
    }

    public function testMergingWindowsIsLossless(): void
    {
        // Two requests that each saw the same operation, with the sketch each
        // of them built from its own half of the traffic.
        $left = new Sketch();
        $right = new Sketch();
        $both = new Sketch();
        foreach ([3.0, 4.0, 5.0] as $value) {
            $left->add($value);
            $both->add($value);
        }
        foreach ([50.0, 60.0] as $value) {
            $right->add($value);
            $both->add($value);
        }

        $merged = Buffer::merge([
            self::window([self::operation('http\x01GET /orders', 3, 30, $left)]),
            self::window([self::operation('http\x01GET /orders', 2, 20, $right)]),
        ]);

        $this->assertCount(1, $merged['operations'], 'one operation, seen by two requests');
        $operation = $merged['operations'][0];
        $this->assertSame(5, $operation['count']);
        $this->assertSame(50, $operation['rowsSum']);
        $this->assertSame(
            $both->toBase64(),
            $operation['durationSketch'],
            'the merged sketch is exactly what one process measuring both would have produced',
        );
    }

    public function testEdgesAndErrorSamplesSurviveTheMerge(): void
    {
        $merged = Buffer::merge([
            [
                'windowStart' => 1, 'windowEnd' => 2,
                'operations' => [self::operation('function\x01A#b', 1, 0, new Sketch(), [
                    ['message' => 'RuntimeException: boom', 'count' => 2],
                ])],
                'edges' => [['parentKey' => 'p', 'childKey' => 'c', 'count' => 1, 'rowsSum' => 5,
                    'durationSketch' => (new Sketch())->toBase64()]],
            ],
            [
                'windowStart' => 2, 'windowEnd' => 3,
                'operations' => [self::operation('function\x01A#b', 1, 0, new Sketch(), [
                    ['message' => 'RuntimeException: boom', 'count' => 3],
                ])],
                'edges' => [['parentKey' => 'p', 'childKey' => 'c', 'count' => 2, 'rowsSum' => 7,
                    'durationSketch' => (new Sketch())->toBase64()]],
            ],
        ]);

        $this->assertSame(1, $merged['windowStart'], 'the window spans both');
        $this->assertSame(3, $merged['windowEnd']);
        $this->assertSame(3, $merged['edges'][0]['count']);
        $this->assertSame(12, $merged['edges'][0]['rowsSum']);
        $this->assertSame(
            [['message' => 'RuntimeException: boom', 'count' => 5]],
            $merged['operations'][0]['errorSamples'],
        );
    }

    /**
     * The reason each request writes its own key rather than updating a shared
     * one: nothing here is read-modify-write, so two workers finishing at the
     * same moment cannot lose each other's window.
     */
    public function testEveryRequestsWindowSurvivesUntilItIsTaken(): void
    {
        for ($i = 0; $i < 10; $i++) {
            Buffer::add(self::window([self::operation('http\x01GET /orders', 1, 4, new Sketch())]), []);
        }

        $taken = Buffer::take();

        $this->assertNotNull($taken);
        $this->assertSame(10, $taken['window']['operations'][0]['count']);
        $this->assertSame(40, $taken['window']['operations'][0]['rowsSum']);
        $this->assertNull(Buffer::take(), 'and taking twice does not send the same window twice');
    }

    /**
     * One request sends; the others carry on. Losing this race has to be
     * harmless, because it is the common case under load.
     */
    public function testOnlyOneRequestFlushesAtATime(): void
    {
        Buffer::add(self::window([self::operation('http\x01GET /orders', 1, 1, new Sketch())]), []);

        // A lock that outlives this test would be a worker that died mid-flush;
        // it expires rather than stranding the buffer forever.
        $this->assertTrue(apcu_add('sixty:flushing', 1, 30));
        $this->assertNull(Buffer::take(), 'a second request must not send what the first is sending');

        apcu_delete('sixty:flushing');
        $this->assertNotNull(Buffer::take());
    }

    public function testExemplarsAreCappedOnTheWayOut(): void
    {
        for ($i = 0; $i < 60; $i++) {
            Buffer::add(self::window([]), array_fill(0, 5, ['traceId' => "t{$i}", 'spans' => []]));
        }

        $taken = Buffer::take();

        $this->assertNotNull($taken);
        $this->assertCount(100, $taken['exemplars'], 'the collector takes 100 per flush; the rest are not sent');
    }

    /** @param array<int, array<string, mixed>> $operations */
    private static function window(array $operations): array
    {
        return [
            'windowStart' => 1000,
            'windowEnd' => 2000,
            'operations' => $operations,
            'edges' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function operation(
        string $key,
        int $count,
        int $rows,
        Sketch $duration,
        array $errorSamples = [],
    ): array {
        return [
            'key' => $key,
            'kind' => 'http',
            'name' => 'GET /orders',
            'normalizedSql' => null,
            'sourceFile' => null,
            'sourceLine' => null,
            'frames' => null,
            'plan' => null,
            'count' => $count,
            'errors' => 0,
            'durationSketch' => $duration->toBase64(),
            'selfSketch' => $duration->toBase64(),
            'rowsSketch' => null,
            'rowsSum' => $rows,
            'dbCallsSum' => 0,
            'dbCallsMax' => 0,
            'bytesSketch' => null,
            'bytesSum' => 0,
            'roundTripsSum' => 0,
            'errorSamples' => $errorSamples,
        ];
    }
}

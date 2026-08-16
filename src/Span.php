<?php

declare(strict_types=1);

namespace Sixty;

/**
 * One measured operation.
 *
 * A plain object with public properties rather than a value object with
 * accessors: this is allocated on every instrumented call in the application,
 * and the difference between a property read and a method call is the
 * difference between an agent you can leave on and one you cannot.
 */
final class Span
{
    public string $id = '';
    /** The aggregator's key for this span, computed once and kept. */
    public ?string $key = null;
    public string $traceId = '';
    public ?string $parentId = null;
    public ?float $duration = null;
    public float $start = 0.0;
    public ?int $startWall = null;
    public float $childDuration = 0.0;
    public int $dbCalls = 0;
    public int $dbRows = 0;
    /** @var array{type: string, message: string}|null */
    public ?array $error = null;
    public int $depth = 0;
    /** @var Span[] */
    public array $children = [];
    public ?Span $root = null;
    public int $spanCount = 0;
    public bool $truncated = false;
    public bool $recording = false;

    /** @param array<string, mixed> $attrs */
    public function __construct(
        public string $kind,
        public string $name,
        public array $attrs = [],
        public ?Span $parent = null,
    ) {
    }
}

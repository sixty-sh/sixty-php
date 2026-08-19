<?php

declare(strict_types=1);

namespace Sixty\Instrument;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Sixty\Shape;
use Sixty\Sixty;
use Sixty\Stack;
use Sixty\Tracer;

/**
 * MongoDB.
 *
 * A document database has no statement to normalize, so identity is *built*
 * from the command's keys rather than stripped out of its text — see Sixty\Shape.
 * Everything downstream is unchanged: documents are rows, commands are calls,
 * and the feed, the detector and the MCP server need to know none of this.
 *
 * ── Command monitoring is the right hook here ────────────────────────────
 *
 * `@sixty-sh/node` refuses the driver's command events and patches the
 * collection instead, because in Node those events fire after the caller's
 * async context is gone — every operation would be measured correctly and
 * parented to nothing.
 *
 * PHP has no such problem. The extension publishes `commandStarted` and
 * `commandSucceeded` synchronously, inside the call that issued them, so the
 * current span is still the method that ran the query. That makes the official
 * monitoring API strictly better than monkey-patching: it is public, it covers
 * the `mongodb/mongodb` library and Doctrine ODM without knowing about either,
 * and it sees commands issued through paths a library-level patch would miss.
 *
 * ── One cursor is one operation, however many round trips it took ────────
 *
 * A `find` returning thirty thousand documents at the default batch size is the
 * initial command plus hundreds of `getMore` round trips, each a network wait.
 * Recording each as its own operation would tell the reader their method makes
 * three hundred database calls — the signature of an N+1 their code does not
 * contain — and send them looking for a loop when the fix is a batch size. So
 * the cursor's span stays open until it is exhausted, counting documents and
 * round trips, and is emitted once.
 *
 * ── No query plans ───────────────────────────────────────────────────────
 *
 * `explain` needs a filter that still has its values in it, and this file drops
 * values at the moment it sees them. Capturing a plan would mean retaining
 * somebody's query values to compose a command out of them — the same trade
 * refused for MySQL, refused again here.
 */
final class Mongo
{
    /** Commands the driver issues about itself. */
    private const IGNORED = [
        'ismaster' => true, 'isMaster' => true, 'hello' => true, 'ping' => true,
        'buildInfo' => true, 'getnonce' => true, 'authenticate' => true,
        'saslStart' => true, 'saslContinue' => true, 'logout' => true,
        'endSessions' => true, 'getLog' => true, 'hostInfo' => true,
        'listDatabases' => true, 'connectionStatus' => true, 'getParameter' => true,
    ];

    /** Commands that can hand back a cursor rather than an answer. */
    private const CURSOR_COMMANDS = [
        'find' => true, 'aggregate' => true, 'listIndexes' => true, 'listCollections' => true,
    ];

    /**
     * Keys the driver adds to every command. They describe the session and the
     * topology rather than the query.
     *
     * `cursor`, `batchSize` and `singleBatch` are here for a sharper reason
     * than tidiness: setting a batch size is precisely the change the round
     * trips signal exists to report, and an identity that moved with it would
     * re-identify the operation at the moment of the change — leaving the
     * detector a new operation with no history beside an old one that stopped
     * reporting. `limit` is *not* in this list: a limit changes what you asked
     * for.
     */
    private const ENVELOPE = [
        '$db' => true, 'lsid' => true, 'txnNumber' => true, '$clusterTime' => true,
        '$readPreference' => true, 'apiVersion' => true, 'apiStrict' => true,
        'apiDeprecationErrors' => true, 'signature' => true, 'startTransaction' => true,
        'autocommit' => true, 'readConcern' => true, 'writeConcern' => true,
        'comment' => true, 'cursor' => true, 'batchSize' => true, 'singleBatch' => true,
    ];

    private const MAX_IN_FLIGHT = 64;
    private const MAX_OPEN_CURSORS = 128;

    private static ?self $subscriber = null;
    private static ?object $driverSubscriber = null;

    /** @var array<string, array<string, mixed>> keyed by request id */
    private array $inFlight = [];
    /** @var array<string, array<string, mixed>> keyed by cursor id */
    private array $cursors = [];

    public static function install(): bool
    {
        if (self::$subscriber !== null || !class_exists(\MongoDB\Driver\Manager::class) || !interface_exists(CommandSubscriber::class)) {
            return false;
        }
        if (!function_exists('MongoDB\Driver\Monitoring\addSubscriber')) {
            return false;
        }

        $mongo = new self();
        $driverSubscriber = new class($mongo) implements CommandSubscriber {
            public function __construct(private Mongo $mongo) {}
            public function commandStarted(CommandStartedEvent $event): void { $this->mongo->commandStarted($event); }
            public function commandSucceeded(CommandSucceededEvent $event): void { $this->mongo->commandSucceeded($event); }
            public function commandFailed(CommandFailedEvent $event): void { $this->mongo->commandFailed($event); }
        };
        self::$subscriber = $mongo;
        self::$driverSubscriber = $driverSubscriber;
        \MongoDB\Driver\Monitoring\addSubscriber($driverSubscriber);

        return true;
    }

    public static function subscriber(): ?self
    {
        return self::$subscriber;
    }

    public static function reset(): void
    {
        if (self::$driverSubscriber !== null && function_exists('MongoDB\Driver\Monitoring\removeSubscriber')) {
            \MongoDB\Driver\Monitoring\removeSubscriber(self::$driverSubscriber);
        }
        self::$subscriber = null;
        self::$driverSubscriber = null;
    }

    public function commandStarted(CommandStartedEvent $event): void
    {
        if (!Sixty::enabled()) {
            return;
        }

        try {
            $name = $event->getCommandName();
            // Not an operation, but the end of one: the application stopped
            // reading a cursor and the driver is telling the server so.
            if ($name === 'killCursors') {
                $this->killCursors($event);

                return;
            }
            if (isset(self::IGNORED[$name])) {
                return;
            }

            if (count($this->inFlight) >= self::MAX_IN_FLIGHT) {
                $this->inFlight = [];
            }
            $this->inFlight[(string) $event->getRequestId()] = $this->describe($event);
        } catch (\Throwable) {
            // never raise into the driver
        }
    }

    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $this->finish((string) $event->getRequestId(), $event->getDurationMicros(), $event->getReply(), null);
    }

    public function commandFailed(CommandFailedEvent $event): void
    {
        $this->finish(
            (string) $event->getRequestId(),
            $event->getDurationMicros(),
            null,
            // The server's error text is used as the message and never as part
            // of the operation's identity, so a failure that quotes a value
            // cannot mint an operation from it.
            new \RuntimeException(substr($event->getError()->getMessage(), 0, 500)),
        );
    }

    /**
     * `getRequestId()` is a string rather than an int, because a request id is
     * a 64-bit counter and PHP cannot promise that fits in an int on every
     * build. Keyed and compared as one throughout.
     */
    private function finish(string $requestId, int $micros, mixed $reply, ?\Throwable $error): void
    {
        try {
            $state = $this->inFlight[$requestId] ?? null;
            if ($state === null) {
                return;
            }
            unset($this->inFlight[$requestId]);

            $document = self::toArray($reply);
            $durationMs = $micros / 1000;

            // A `getMore` belongs to the cursor that opened it, not to itself.
            if ($state['command'] === 'getMore') {
                if ($this->continueCursor((string) $state['cursorId'], $document, $durationMs, $error)) {
                    return;
                }
            } elseif ($error === null && isset(self::CURSOR_COMMANDS[$state['command']])) {
                $cursorId = self::cursorIdOf($document);
                if ($cursorId !== null && $cursorId !== '0') {
                    $this->openCursor($cursorId, $state, $document, $durationMs);

                    return;
                }
            }

            $attrs = ['normalizedSql' => $state['identity'], 'roundTrips' => 1];
            if ($state['frames'] !== null) {
                $attrs['frames'] = $state['frames'];
            }
            $rows = self::rowsFrom($document, $state['command']);
            if ($rows !== null) {
                $attrs['rows'] = $rows;
            }

            Tracer::record(Tracer::KIND_DB, $state['name'], $durationMs, $attrs, $error);
        } catch (\Throwable) {
            // never raise into the driver
        }
    }

    /** @param array<string, mixed> $state */
    private function openCursor(string $cursorId, array $state, array $reply, float $durationMs): void
    {
        $span = Tracer::startSpan(Tracer::KIND_DB, $state['name'], ['normalizedSql' => $state['identity']]);
        if ($state['frames'] !== null) {
            $span->attrs['frames'] = $state['frames'];
        }
        // Back-dated by the command's own duration, so the span covers the round
        // trip that opened the cursor as well as the ones that follow.
        $span->start -= $durationMs;

        if (count($this->cursors) >= self::MAX_OPEN_CURSORS) {
            $oldest = array_key_first($this->cursors);
            if ($oldest !== null) {
                $this->closeCursor($oldest, null);
            }
        }

        $this->cursors[$cursorId] = [
            'span' => $span,
            'rows' => self::batchLength($reply) ?? 0,
            'roundTrips' => 1,
        ];
    }

    /** @param array<string, mixed>|null $reply */
    private function continueCursor(string $cursorId, ?array $reply, float $durationMs, ?\Throwable $error): bool
    {
        if (!isset($this->cursors[$cursorId])) {
            return false;
        }

        $this->cursors[$cursorId]['roundTrips']++;
        $this->cursors[$cursorId]['rows'] += $reply !== null ? (self::batchLength($reply) ?? 0) : 0;

        $exhausted = $reply === null || self::cursorIdOf($reply) === null || self::cursorIdOf($reply) === '0';
        if ($error !== null || $exhausted) {
            $this->closeCursor($cursorId, $error);
        }

        return true;
    }

    private function closeCursor(string $cursorId, ?\Throwable $error): void
    {
        $entry = $this->cursors[$cursorId] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->cursors[$cursorId]);

        /** @var \Sixty\Span $span */
        $span = $entry['span'];
        $span->attrs['rows'] = $entry['rows'];
        $span->attrs['roundTrips'] = $entry['roundTrips'];
        Tracer::endSpan($span, $error);
        Tracer::emit($span);
    }

    private function killCursors(CommandStartedEvent $event): void
    {
        $command = self::toArray($event->getCommand());
        foreach ($command['cursors'] ?? [] as $id) {
            $this->closeCursor((string) self::scalar($id), null);
        }
    }

    /**
     * Everything about a command that has to be read before it runs.
     *
     * @return array<string, mixed>
     */
    private function describe(CommandStartedEvent $event): array
    {
        $command = self::toArray($event->getCommand());
        $name = $event->getCommandName();
        $collection = self::collectionOf($name, $command, $event->getDatabaseName());
        $identity = self::identityOf($name, $collection, $command);

        return [
            'command' => $name,
            'name' => "{$name}:{$collection}",
            'identity' => $identity,
            'frames' => Stack::capture($identity),
            'cursorId' => $name === 'getMore' ? (string) self::scalar($command['getMore'] ?? '') : null,
        ];
    }

    /**
     * The identity of the operation: the command, what it ran against, and the
     * *structure* of its arguments. No value in the command has a path into
     * this string.
     *
     * @param array<string, mixed> $command
     */
    private static function identityOf(string $name, string $collection, array $command): string
    {
        $parts = [];
        // A pipeline is ordered structure, so every stage counts and in order.
        if (isset($command['pipeline'])) {
            $parts[] = Shape::ofSequence($command['pipeline']);
        }

        $rest = [];
        foreach ($command as $key => $value) {
            if ($key === $name || $key === 'pipeline' || isset(self::ENVELOPE[$key])) {
                continue;
            }
            $rest[$key] = $value;
        }
        $shape = Shape::of($rest);
        if ($shape !== '') {
            $parts[] = "{{$shape}}";
        }

        return trim("{$name} {$collection} " . implode(' ', $parts));
    }

    /** @param array<string, mixed> $command */
    private static function collectionOf(string $name, array $command, string $database): string
    {
        $value = $name === 'getMore' ? ($command['collection'] ?? null) : ($command[$name] ?? null);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $database !== '' ? $database : 'collection';
    }

    /**
     * Documents returned for a read, documents affected for a write — the same
     * meaning `rows` has for every SQL client in this package.
     *
     * @param array<string, mixed>|null $reply
     */
    private static function rowsFrom(?array $reply, string $command): ?int
    {
        if ($reply === null) {
            return null;
        }

        $batch = self::batchLength($reply);
        if ($batch !== null) {
            return $batch;
        }

        return match ($command) {
            // A count returns one number, so one document came back. Reporting
            // the count itself would say that counting a million-document
            // collection returned a million documents, and the first collection
            // to grow would look like the regression this product exists to
            // report.
            'count', 'countDocuments' => 1,
            'distinct' => is_array($reply['values'] ?? null) ? count($reply['values']) : 1,
            'findAndModify' => ($reply['value'] ?? null) === null ? 0 : 1,
            'update' => self::intOf($reply['nModified'] ?? null) ?? self::intOf($reply['n'] ?? null),
            default => self::intOf($reply['n'] ?? null),
        };
    }

    /** @param array<string, mixed> $reply */
    private static function batchLength(array $reply): ?int
    {
        $cursor = self::toArray($reply['cursor'] ?? null);
        if ($cursor === null) {
            return null;
        }
        $batch = $cursor['firstBatch'] ?? $cursor['nextBatch'] ?? null;
        $batch = is_object($batch) ? self::toArray($batch) : $batch;

        return is_array($batch) ? count($batch) : null;
    }

    /** @param array<string, mixed> $reply */
    private static function cursorIdOf(array $reply): ?string
    {
        $cursor = self::toArray($reply['cursor'] ?? null);
        if ($cursor === null || !isset($cursor['id'])) {
            return null;
        }

        return (string) self::scalar($cursor['id']);
    }

    /**
     * BSON values arrive as objects — Int64, ObjectId — and comparing them as
     * strings is the only spelling that is stable across driver versions and
     * across 32-bit builds where an Int64 cannot be an int at all.
     */
    private static function scalar(mixed $value): string
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function intOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array<string, mixed>|null */
    private static function toArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \MongoDB\BSON\Document || $value instanceof \MongoDB\BSON\PackedArray) {
            $value = $value->toPHP();
        }

        return is_object($value) ? (array) $value : null;
    }
}

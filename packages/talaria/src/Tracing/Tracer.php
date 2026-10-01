<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Config;
use Talaria\Context\RuntimeContext;
use Talaria\Identity;

/**
 * Process-local tracer: one transaction (root span) plus child spans.
 *
 * Spans are recorded while tracing is enabled, then dropped or flushed as a
 * batch when the root ends. Dropped (unsampled, non-error) spans are not sent.
 */
final class Tracer
{
    /** Spans stored for one transaction, including the root. The server rejects more. */
    public const MAX_SPANS = 200;

    /** Automatic SQL spans. The rest of the budget stays available for other spans. */
    public const MAX_SQL_SPANS = 168;

    /** Non-SQL spans, including the root, that SQL cannot consume. */
    public const RESERVED_NON_SQL = 32;

    /** Queries at or above this stay their own span. Matches the product slow-query default. */
    public const SLOW_QUERY_MS = 200;

    private ?Span $root = null;

    /** @var list<Span> */
    private array $stack = [];

    /** @var list<Span> */
    private array $finished = [];

    private bool $headSampled = false;

    private bool $sawError = false;

    /** Keep the transaction even when head sampling would drop it (errors, attached events). */
    private bool $forceSend = false;

    private int $childCount = 0;

    private int $sqlCount = 0;

    private int $droppedCount = 0;

    /** When false, spans that carry db.query.text are not recorded. */
    private bool $recordQuerySpans = true;

    /**
     * Open SQL groups keyed by parent span id and db.query.text.
     *
     * @var array<string, Span>
     */
    private array $queryGroups = [];

    /**
     * Query spans whose budget is decided when they end, so a repeat can roll up.
     *
     * @var array<string, true>
     */
    private array $pendingQueries = [];

    private bool $ingestDisabled = false;

    /** @var array<string, string> */
    private array $requestAttributes = [];

    public function __construct(
        private readonly Config $config,
        private readonly SpanQueue $queue,
        private readonly Identity $identity = new Identity(),
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enableTracing && !$this->ingestDisabled;
    }

    /** Stop recording / flushing spans after a permanent ingest error. */
    public function disableIngest(): void
    {
        $this->ingestDisabled = true;
    }

    /**
     * Head-sampling decision for the active transaction (error override is separate).
     */
    public function isSampled(): bool
    {
        return $this->forceSend || $this->sawError || $this->headSampled;
    }

    public function currentSpan(): ?Span
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    public function rootSpan(): ?Span
    {
        return $this->root;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setRequestAttributes(array $attributes): void
    {
        $this->requestAttributes = array_merge($this->requestAttributes, $attributes);
    }

    /**
     * @return array<string, string>
     */
    public function requestAttributes(): array
    {
        return $this->requestAttributes;
    }

    public function markError(?string $message = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->sawError = true;
        $this->forceSend = true;
        $current = $this->currentSpan();
        $current?->setStatus(SpanStatus::Error, $message);
        if ($this->root !== null && $this->root !== $current) {
            $this->root->setStatus(SpanStatus::Error, $message);
        }
    }

    /**
     * Keep this transaction so a stamped event's "View trace" link resolves.
     */
    public function retain(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->forceSend = true;
    }

    public function recordsQuerySpans(): bool
    {
        return $this->recordQuerySpans;
    }

    /**
     * Lasts until the transaction commits or {@see reset()} / resetRequestState().
     */
    public function setRecordQuerySpans(bool $record): void
    {
        $this->recordQuerySpans = $record;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withoutQuerySpans(callable $fn): mixed
    {
        $previous = $this->recordQuerySpans;
        $this->recordQuerySpans = false;
        try {
            return $fn();
        } finally {
            $this->recordQuerySpans = $previous;
        }
    }

    /**
     * Start a root SERVER span, or a child if a transaction is already open.
     *
     * @param array<string, string> $attributes
     */
    public function startTransaction(
        string $name,
        string|SpanKind $kind = SpanKind::Server,
        array $attributes = [],
    ): Span {
        if ($this->root !== null && $this->root->hasEnded()) {
            $this->reset();
        }

        return $this->startSpan($name, $kind, $attributes);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function startSpan(
        string $name,
        string|SpanKind $kind = SpanKind::Internal,
        array $attributes = [],
    ): Span {
        if (!$this->isEnabled()) {
            return Span::noop();
        }

        $kindValue = SpanKind::fromMixed($kind)->value;
        $isRoot = $this->root === null;
        $incoming = $isRoot ? TraceContext::fromServer() : null;

        $queryText = $attributes['db.query.text'] ?? null;
        $isQuery = is_string($queryText) && $queryText !== '';

        if ($isRoot) {
            $traceId = $incoming?->traceId ?? TraceContext::generateTraceId();
            $parentSpanId = $incoming?->spanId;
            $this->headSampled = Sampling::head($this->config->tracesSampleRate, $incoming?->sampled);
        } else {
            if ($isQuery && !$this->recordQuerySpans) {
                return Span::noop();
            }
            if (!$isQuery && !$this->canAdmitOther()) {
                $this->droppedCount++;

                return Span::noop();
            }
            if (!$isQuery) {
                $this->childCount++;
            }
            $parent = $this->currentSpan() ?? $this->root;
            $traceId = $parent?->traceId ?? TraceContext::generateTraceId();
            $parentSpanId = $parent?->spanId;
        }

        $span = new Span(
            traceId: $traceId,
            spanId: TraceContext::generateSpanId(),
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kindValue,
            startTime: RuntimeContext::isoTimestamp(),
            attributes: $attributes,
            resource: $this->resource(),
            recording: true,
            onEnd: $this->onSpanEnded(...),
            release: $this->config->release,
            userId: $this->identity->userId,
            sessionId: $this->identity->sessionId !== '' ? $this->identity->sessionId : null,
            requestId: $incoming?->traceId,
            anonymousId: $this->identity->anonymousId,
        );

        $this->stack[] = $span;
        if ($isRoot) {
            $this->root = $span;
        } elseif ($isQuery) {
            $this->pendingQueries[$span->spanId] = true;
        }

        return $span;
    }

    public function reset(): void
    {
        $this->root = null;
        $this->stack = [];
        $this->finished = [];
        $this->headSampled = false;
        $this->sawError = false;
        $this->forceSend = false;
        $this->childCount = 0;
        $this->sqlCount = 0;
        $this->droppedCount = 0;
        $this->recordQuerySpans = true;
        $this->queryGroups = [];
        $this->pendingQueries = [];
        $this->requestAttributes = [];
    }

    private function onSpanEnded(Span $span): void
    {
        $this->pop($span);

        if ($this->root !== $span) {
            $this->acceptFinished($span);

            return;
        }

        $endTime = RuntimeContext::isoTimestamp();
        foreach ($this->stack as $open) {
            if (!$open->hasEnded()) {
                $open->forceEnd($endTime);
                $this->acceptFinished($open);
            }
        }
        $this->stack = [];
        if ($this->droppedCount > 0) {
            $span->setAttribute('dropped_span_count', (string) $this->droppedCount);
        }
        $this->finished[] = $span;
        $this->commitTransaction();
    }

    private function acceptFinished(Span $span): void
    {
        $pending = isset($this->pendingQueries[$span->spanId]);
        unset($this->pendingQueries[$span->spanId]);
        if (!$pending) {
            $this->finished[] = $span;

            return;
        }
        if ($this->absorbQuery($span)) {
            return;
        }
        if (!$this->canAdmitSql()) {
            $this->droppedCount++;

            return;
        }
        $this->sqlCount++;
        $this->childCount++;
        $text = $span->getAttribute('db.query.text') ?? '';
        $failed = $span->getStatus() === SpanStatus::Error->value;
        if (!$failed && $text !== '' && $span->durationMs() < self::SLOW_QUERY_MS) {
            $this->queryGroups[($span->parentSpanId ?? '') . "\0" . $text] = $span;
        }
        $this->finished[] = $span;
    }

    private function absorbQuery(Span $span): bool
    {
        $text = $span->getAttribute('db.query.text') ?? '';
        if ($text === '') {
            return false;
        }
        if ($span->getStatus() === SpanStatus::Error->value) {
            return false;
        }
        if ($span->durationMs() >= self::SLOW_QUERY_MS) {
            return false;
        }
        $group = $this->queryGroups[($span->parentSpanId ?? '') . "\0" . $text] ?? null;
        if ($group === null) {
            return false;
        }
        $group->absorbExecution($span);

        return true;
    }

    private function storedCount(): int
    {
        return $this->childCount + ($this->root !== null ? 1 : 0);
    }

    private function nonSqlStored(): int
    {
        return ($this->childCount - $this->sqlCount) + ($this->root !== null ? 1 : 0);
    }

    private function canAdmitSql(): bool
    {
        if ($this->sqlCount >= self::MAX_SQL_SPANS || $this->storedCount() >= self::MAX_SPANS) {
            return false;
        }
        $reserve = max(0, self::RESERVED_NON_SQL - $this->nonSqlStored());

        return ($this->storedCount() + $reserve) < self::MAX_SPANS;
    }

    private function canAdmitOther(): bool
    {
        return $this->storedCount() < self::MAX_SPANS;
    }

    private function commitTransaction(): void
    {
        $isError = $this->sawError || ($this->root !== null && $this->root->getStatus() === SpanStatus::Error->value);
        if (Sampling::shouldSend($this->headSampled, $isError || $this->forceSend)) {
            $toSend = [];
            if ($this->root !== null) {
                $toSend[] = $this->root;
            }
            foreach ($this->finished as $span) {
                if ($span !== $this->root) {
                    $toSend[] = $span;
                }
            }
            $this->queue->sendTransaction($toSend);
        }

        $this->reset();
    }

    private function pop(Span $span): void
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i] === $span) {
                array_splice($this->stack, $i, 1);

                return;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function resource(): array
    {
        $resource = [
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.name' => 'talaria-php',
        ];

        $service = $this->config->tags['service'] ?? null;
        if (is_string($service) && $service !== '') {
            $resource['service.name'] = $service;
        } else {
            $resource['service.name'] = 'php';
        }

        if ($this->config->release !== null && $this->config->release !== '') {
            $resource['service.version'] = $this->config->release;
        }

        return $resource;
    }
}

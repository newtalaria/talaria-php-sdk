<?php

declare(strict_types=1);

namespace Talaria\Analytics;

use Talaria\Config;
use Talaria\Context\RuntimeContext;
use Talaria\Identity;
use Talaria\Tracing\Tracer;
use Talaria\Tracing\UrlSanitizer;

/**
 * Server-side analytics. No autocapture and no cookie jar — callers pass
 * `userId` and/or `anonymousId` (browser ids forwarded on checkout APIs).
 */
final class Analytics
{
    private bool $loggedMissingIdentity = false;

    /** @var callable(): bool */
    private $isDisabled;

    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly AnalyticsQueue $queue,
        private readonly Tracer $tracer,
        callable $isDisabled,
    ) {
        $this->isDisabled = $isDisabled;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    public function track(string $name, array $properties = [], array $options = []): void
    {
        $this->enqueue(AnalyticsEventKind::Track, $name, $properties, $options);
    }

    /**
     * Associate subsequent events, spans, and errors with `$userId`.
     *
     * @param array<string, mixed> $traits
     * @param array<string, mixed> $options
     */
    public function identify(string $userId, array $traits = [], array $options = []): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            return;
        }
        $this->identity->setUser($userId);
        $anonymousId = $this->stringOption($options, 'anonymousId');
        if ($anonymousId !== null) {
            $this->identity->setAnonymousId($anonymousId);
        }

        $this->enqueue(AnalyticsEventKind::Identify, '$identify', $traits, $options);
    }

    /**
     * Explicit page view. PHP never auto-captures page views (bots/caches).
     *
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    public function page(?string $name = null, array $properties = [], array $options = []): void
    {
        $this->enqueue(AnalyticsEventKind::Page, $name ?? '$pageview', $properties, $options);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    public function screen(?string $name = null, array $properties = [], array $options = []): void
    {
        $this->enqueue(AnalyticsEventKind::Screen, $name ?? '$screen', $properties, $options);
    }

    /**
     * Clear identified user + visitor id and start a new in-memory session.
     * Does not write cookies.
     */
    public function reset(): void
    {
        $this->identity->resetAnalytics();
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     */
    private function enqueue(
        AnalyticsEventKind $kind,
        string $name,
        array $properties,
        array $options,
    ): void {
        if (($this->isDisabled)()) {
            return;
        }

        $name = trim($name);
        if ($kind === AnalyticsEventKind::Page && $name === '') {
            $name = '$pageview';
        }
        if ($kind === AnalyticsEventKind::Screen && $name === '') {
            $name = '$screen';
        }
        if ($kind === AnalyticsEventKind::Identify && $name === '') {
            $name = '$identify';
        }
        if ($name === '') {
            return;
        }

        $userId = $this->identity->wireUserId($this->stringOption($options, 'userId'));
        $anonymousId = $this->identity->wireAnonymousId($this->stringOption($options, 'anonymousId'));
        if ($anonymousId === null && $userId === null) {
            if (!$this->loggedMissingIdentity) {
                $this->loggedMissingIdentity = true;
                error_log('[Talaria] analytics event dropped: userId or anonymousId is required');
            }

            return;
        }
        if ($anonymousId === null) {
            $anonymousId = $userId;
        }

        $sessionId = $this->identity->wireSessionId($this->stringOption($options, 'sessionId'));
        $timestamp = $this->stringOption($options, 'timestamp') ?? RuntimeContext::isoTimestamp();
        $runtime = RuntimeContext::collect();

        $url = $this->stringOption($options, 'url') ?? $runtime['url'];
        if ($url !== null) {
            $url = UrlSanitizer::sanitize($url);
        }
        $referrer = $this->stringOption($options, 'referrer');
        if ($referrer === null && isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
        }
        if ($referrer !== null) {
            $referrer = UrlSanitizer::sanitize($referrer);
        }

        $path = $this->stringOption($options, 'path');
        if ($path === null && $url !== null) {
            $parsed = parse_url($url, PHP_URL_PATH);
            $path = is_string($parsed) && $parsed !== '' ? $parsed : null;
        }

        $utm = $this->utm($properties, $options);
        $propertiesJson = $this->encodeProperties($properties);

        $active = $this->tracer->currentSpan() ?? $this->tracer->rootSpan();
        $traceId = $active !== null && ($active->isRecording() || $active->hasEnded()) && $active->traceId !== str_repeat('0', 32)
            ? $active->traceId
            : null;
        $spanId = $active !== null && ($active->isRecording() || $active->hasEnded()) && $active->spanId !== str_repeat('0', 16)
            ? $active->spanId
            : null;

        $this->queue->enqueue(new AnalyticsEvent(
            name: $name,
            kind: $kind,
            anonymousId: $anonymousId,
            sessionId: $sessionId,
            timestamp: $timestamp,
            eventId: $this->stringOption($options, 'eventId') ?? RuntimeContext::newId(),
            userId: $userId,
            replayId: $this->stringOption($options, 'replayId'),
            traceId: $traceId,
            spanId: $spanId,
            requestId: $this->stringOption($options, 'requestId') ?? $runtime['requestId'],
            platform: $this->stringOption($options, 'platform') ?? 'php',
            environment: $this->config->environment,
            release: $this->config->release,
            url: $url,
            path: $path,
            title: $this->stringOption($options, 'title'),
            referrer: $referrer,
            utmSource: $utm['utmSource'],
            utmMedium: $utm['utmMedium'],
            utmCampaign: $utm['utmCampaign'],
            utmTerm: $utm['utmTerm'],
            utmContent: $utm['utmContent'],
            propertiesJson: $propertiesJson,
        ));
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $options
     * @return array{
     *   utmSource: ?string,
     *   utmMedium: ?string,
     *   utmCampaign: ?string,
     *   utmTerm: ?string,
     *   utmContent: ?string
     * }
     */
    private function utm(array $properties, array $options): array
    {
        $keys = [
            'utmSource' => ['utmSource', 'utm_source'],
            'utmMedium' => ['utmMedium', 'utm_medium'],
            'utmCampaign' => ['utmCampaign', 'utm_campaign'],
            'utmTerm' => ['utmTerm', 'utm_term'],
            'utmContent' => ['utmContent', 'utm_content'],
        ];
        $out = [];
        foreach ($keys as $field => $aliases) {
            $value = null;
            foreach ($aliases as $alias) {
                $fromOptions = $this->stringOption($options, $alias);
                if ($fromOptions !== null) {
                    $value = $fromOptions;
                    break;
                }
            }
            if ($value === null) {
                foreach ($aliases as $alias) {
                    $fromProps = $this->stringOption($properties, $alias);
                    if ($fromProps !== null) {
                        $value = $fromProps;
                        break;
                    }
                }
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function encodeProperties(array $properties): ?string
    {
        if ($properties === []) {
            return null;
        }
        try {
            return json_encode(
                $properties,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function stringOption(array $bag, string $key): ?string
    {
        if (!isset($bag[$key]) || !is_string($bag[$key])) {
            return null;
        }
        $trimmed = trim($bag[$key]);

        return $trimmed === '' ? null : $trimmed;
    }
}

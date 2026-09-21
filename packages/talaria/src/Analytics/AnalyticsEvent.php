<?php

declare(strict_types=1);

namespace Talaria\Analytics;

/**
 * In-memory analytics event ready to serialize as IngestAnalyticsEventInput.
 */
final class AnalyticsEvent
{
    public function __construct(
        public readonly string $name,
        public readonly AnalyticsEventKind $kind,
        public readonly string $anonymousId,
        public readonly string $sessionId,
        public readonly string $timestamp,
        public readonly ?string $eventId = null,
        public readonly ?string $userId = null,
        public readonly ?string $replayId = null,
        public readonly ?string $traceId = null,
        public readonly ?string $spanId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $platform = null,
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        public readonly ?string $url = null,
        public readonly ?string $path = null,
        public readonly ?string $title = null,
        public readonly ?string $referrer = null,
        public readonly ?string $utmSource = null,
        public readonly ?string $utmMedium = null,
        public readonly ?string $utmCampaign = null,
        public readonly ?string $utmTerm = null,
        public readonly ?string $utmContent = null,
        public readonly ?string $propertiesJson = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $wire = [
            '__className__' => 'IngestAnalyticsEventInput',
            'name' => $this->name,
            'kind' => $this->kind->value,
            'anonymousId' => $this->anonymousId,
            'sessionId' => $this->sessionId,
            'timestamp' => $this->timestamp,
        ];

        if ($this->eventId !== null && $this->eventId !== '') {
            $wire['eventId'] = $this->eventId;
        }
        if ($this->userId !== null && $this->userId !== '') {
            $wire['userId'] = $this->userId;
        }
        if ($this->replayId !== null && $this->replayId !== '') {
            $wire['replayId'] = $this->replayId;
        }
        if ($this->traceId !== null && $this->traceId !== '') {
            $wire['traceId'] = $this->traceId;
        }
        if ($this->spanId !== null && $this->spanId !== '') {
            $wire['spanId'] = $this->spanId;
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $wire['requestId'] = $this->requestId;
        }
        if ($this->platform !== null && $this->platform !== '') {
            $wire['platform'] = $this->platform;
        }
        if ($this->environment !== null && $this->environment !== '') {
            $wire['environment'] = $this->environment;
        }
        if ($this->release !== null && $this->release !== '') {
            $wire['release'] = $this->release;
        }
        if ($this->url !== null && $this->url !== '') {
            $wire['url'] = $this->url;
        }
        if ($this->path !== null && $this->path !== '') {
            $wire['path'] = $this->path;
        }
        if ($this->title !== null && $this->title !== '') {
            $wire['title'] = $this->title;
        }
        if ($this->referrer !== null && $this->referrer !== '') {
            $wire['referrer'] = $this->referrer;
        }
        if ($this->utmSource !== null && $this->utmSource !== '') {
            $wire['utmSource'] = $this->utmSource;
        }
        if ($this->utmMedium !== null && $this->utmMedium !== '') {
            $wire['utmMedium'] = $this->utmMedium;
        }
        if ($this->utmCampaign !== null && $this->utmCampaign !== '') {
            $wire['utmCampaign'] = $this->utmCampaign;
        }
        if ($this->utmTerm !== null && $this->utmTerm !== '') {
            $wire['utmTerm'] = $this->utmTerm;
        }
        if ($this->utmContent !== null && $this->utmContent !== '') {
            $wire['utmContent'] = $this->utmContent;
        }
        if ($this->propertiesJson !== null && $this->propertiesJson !== '') {
            $wire['propertiesJson'] = $this->propertiesJson;
        }

        return $wire;
    }
}

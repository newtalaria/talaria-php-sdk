<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;

final class AuthIntegration
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if (!filter_var(config('talaria.identify_users', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        Event::listen(Authenticated::class, function (Authenticated $event): void {
            $id = self::identifier($event->user);
            if ($id !== null) {
                $this->client->setUser($id);
            }
        });

        Event::listen(Logout::class, function (): void {
            $this->client->setUser(null);
        });

        try {
            $user = Auth::user();
            $id = self::identifier($user);
            if ($id !== null) {
                $this->client->setUser($id);
            }
        } catch (\Throwable) {
        }
    }

    private static function identifier(mixed $user): ?string
    {
        if (!is_object($user)) {
            return null;
        }
        if (method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }
}

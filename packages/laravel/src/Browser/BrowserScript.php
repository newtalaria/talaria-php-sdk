<?php

declare(strict_types=1);

namespace Talaria\Laravel\Browser;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;

/**
 * Loads @newtalaria/browser from jsDelivr and calls Talaria.init.
 *
 * Replay, heatmaps, and web vitals start only when the project config allows them.
 */
final class BrowserScript
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function html(?Authenticatable $user = null): ?string
    {
        if (!$this->config->get('talaria.browser', true)) {
            return null;
        }

        $apiKey = $this->stringConfig('talaria.browser_api_key');
        if (!\Talaria\Credential::isPublishableKey($apiKey)) {
            return null;
        }
        $credential = \Talaria\Credential::parseApiKey($apiKey);

        $url = rtrim($this->stringConfig('talaria.url'), '/');
        if ($url === '') {
            try {
                $url = $credential->ingestUrl();
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        $version = $this->stringConfig('talaria.browser_sdk_version');
        if ($version === '') {
            $version = '0.5.3';
        }

        $options = [
            'url' => $url,
            'apiKey' => $apiKey,
            'publicAnalytics' => true,
            'tags' => [
                'platform' => 'web',
                'runtime' => 'laravel',
            ],
        ];
        $release = $this->stringConfig('talaria.release');
        if ($release !== '') {
            $options['release'] = $release;
        }
        $commit = $this->stringConfig('talaria.commit_sha');
        if ($commit !== '') {
            $options['commitSha'] = $commit;
        }
        $service = $this->stringConfig('talaria.service');
        if ($service !== '') {
            $options['tags']['service'] = $service;
        }
        if ($user !== null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
            if (is_scalar($id) && (string) $id !== '') {
                $options['userId'] = (string) $id;
            }
        }

        $json = json_encode(
            $options,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            return null;
        }

        $importUrl = 'https://cdn.jsdelivr.net/npm/@newtalaria/browser@'
            . rawurlencode($version)
            . '/+esm';
        $import = json_encode($importUrl, JSON_UNESCAPED_SLASHES);
        if ($import === false) {
            return null;
        }

        return '<script type="module">'
            . 'import { Talaria } from ' . $import . ';'
            . 'var cfg = ' . $json . ';'
            . 'if (cfg && Talaria && typeof Talaria.init === "function") {'
            . '  try { Talaria.init(cfg); window.Talaria = Talaria; }'
            . '  catch (err) { if (typeof console !== "undefined" && console.warn) console.warn("[Talaria] browser init failed", err); }'
            . '}'
            . '</script>';
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}

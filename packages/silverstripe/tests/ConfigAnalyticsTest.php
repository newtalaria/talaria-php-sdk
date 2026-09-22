<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;

final class ConfigAnalyticsTest extends TestCase
{
    public function testEnableAnalyticsReadsEnv(): void
    {
        if (!trait_exists(\SilverStripe\Core\Config\Configurable::class)) {
            self::markTestSkipped('Silverstripe framework is not installed in this test run.');
        }

        $this->setAnalyticsEnv('true');
        try {
            self::assertTrue(\Talaria\SilverStripe\Config::enableAnalytics());
            $this->setAnalyticsEnv('false');
            self::assertFalse(\Talaria\SilverStripe\Config::enableAnalytics());
        } finally {
            $this->clearAnalyticsEnv();
        }
    }

    private function setAnalyticsEnv(string $value): void
    {
        putenv('TALARIA_ENABLE_ANALYTICS=' . $value);
        $_ENV['TALARIA_ENABLE_ANALYTICS'] = $value;
        $_SERVER['TALARIA_ENABLE_ANALYTICS'] = $value;
        if (class_exists(\SilverStripe\Core\Environment::class)) {
            \SilverStripe\Core\Environment::setEnv('TALARIA_ENABLE_ANALYTICS', $value);
        }
    }

    private function clearAnalyticsEnv(): void
    {
        putenv('TALARIA_ENABLE_ANALYTICS');
        unset($_ENV['TALARIA_ENABLE_ANALYTICS'], $_SERVER['TALARIA_ENABLE_ANALYTICS']);
        if (class_exists(\SilverStripe\Core\Environment::class)) {
            \SilverStripe\Core\Environment::setEnv('TALARIA_ENABLE_ANALYTICS', null);
        }
    }
}

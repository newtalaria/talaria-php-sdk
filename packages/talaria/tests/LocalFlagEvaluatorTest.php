<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Flags\LocalFlagEvaluator;

final class LocalFlagEvaluatorTest extends TestCase
{
    /**
     * Known fixture shared with server FlagEvaluator and Dart LocalFlagEvaluator:
     * sha256("new-checkout\nstable-user") first 4 bytes BE % 100 = 59.
     */
    public function testBucketMatchesServerHashFixture(): void
    {
        self::assertSame(59, LocalFlagEvaluator::bucket('new-checkout', 'stable-user'));
    }

    public function testPercentUsersUsesSameBucketAsServer(): void
    {
        $evaluator = new LocalFlagEvaluator();
        $flag = [
            'key' => 'new-checkout',
            'kind' => 'boolean',
            'variations' => [
                ['key' => 'on', 'valueJson' => 'true'],
                ['key' => 'off', 'valueJson' => 'false'],
            ],
            'rules' => [
                [
                    'kind' => 'percentUsers',
                    'variationKey' => 'on',
                    'percent' => 60,
                ],
            ],
            'defaultVariationKey' => 'off',
            'offVariationKey' => 'off',
            'enabled' => true,
            'version' => 1,
        ];

        $in = $evaluator->evaluate($flag, ['userId' => 'stable-user']);
        self::assertSame('on', $in['variationKey']);
        self::assertSame('rule:0', $in['reason']);

        $flag['rules'][0]['percent'] = 50;
        $out = $evaluator->evaluate($flag, ['userId' => 'stable-user']);
        self::assertSame('off', $out['variationKey']);
        self::assertSame('default', $out['reason']);
    }

    public function testAnonymousIdAndUserIdSameStringBucketIdentically(): void
    {
        $evaluator = new LocalFlagEvaluator();
        $flag = [
            'key' => 'new-checkout',
            'variations' => [
                ['key' => 'on', 'valueJson' => 'true'],
                ['key' => 'off', 'valueJson' => 'false'],
            ],
            'rules' => [
                [
                    'kind' => 'percentUsers',
                    'variationKey' => 'on',
                    'percent' => 100,
                ],
            ],
            'defaultVariationKey' => 'off',
            'offVariationKey' => 'off',
            'enabled' => true,
            'version' => 1,
        ];
        $a = $evaluator->evaluate($flag, ['anonymousId' => 'anon-1']);
        $b = $evaluator->evaluate($flag, ['userId' => 'anon-1']);
        self::assertSame($a['variationKey'], $b['variationKey']);
        self::assertSame($a['reason'], $b['reason']);
    }
}

<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/packages/talaria/src')
    ->in(__DIR__ . '/packages/talaria/tests')
    ->in(__DIR__ . '/packages/silverstripe/src')
    ->in(__DIR__ . '/packages/silverstripe/tests')
    ->in(__DIR__ . '/packages/laravel/src')
    ->in(__DIR__ . '/packages/laravel/tests')
    ->in(__DIR__ . '/packages/laravel/config');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);

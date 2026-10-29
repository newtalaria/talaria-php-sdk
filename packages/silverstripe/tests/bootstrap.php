<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Package CI removes silverstripe/framework. The adapter still needs Configurable
// so browser-key tests can call Config without a full Silverstripe install.
if (!trait_exists(\SilverStripe\Core\Config\Configurable::class)) {
    require __DIR__ . '/support/configurable_stub.php';
} elseif (class_exists(\SilverStripe\Config\Collections\MemoryConfigCollection::class)) {
    $loader = \SilverStripe\Core\Config\ConfigLoader::inst();
    if (!$loader->hasManifest()) {
        $loader->pushManifest(\SilverStripe\Config\Collections\MemoryConfigCollection::create());
        $loader->activate();
    }
}

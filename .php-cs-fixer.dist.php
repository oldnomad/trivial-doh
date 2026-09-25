<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
       '@auto' => true,
       '@PSR12' => true
    ])
    ->setUsingCache(false)
    ->setFinder(
        (new Finder())
            ->in(__DIR__.'/src')
    )
;

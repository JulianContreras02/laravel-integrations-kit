<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

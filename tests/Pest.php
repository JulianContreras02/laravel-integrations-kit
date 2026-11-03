<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application against an in-memory SQLite database
| and Laravel's fakes (HTTP, storage, mail), so they run without Docker.
| A stray real HTTP request fails the test instead of leaving the machine.
| Integration tests talk to the docker compose services instead.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('integration')
    ->in('Integration');

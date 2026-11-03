<?php

declare(strict_types=1);

/*
 * Router script for PHP's built-in web server, used by the token refresh
 * concurrency test as a minimal OAuth2 token endpoint.
 *
 * Every request is slow on purpose, so concurrent workers really overlap,
 * and is appended to a log file, so the test can count how many tokens the
 * "partner" had to issue.
 */

usleep(300_000);

file_put_contents((string) getenv('TOKEN_SERVER_HITS'), date(DATE_ATOM).PHP_EOL, FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');

echo json_encode([
    'access_token' => bin2hex(random_bytes(20)),
    'token_type' => 'Bearer',
    'expires_in' => 3600,
]);

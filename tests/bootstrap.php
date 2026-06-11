<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (empty($_ENV['JWT_SECRET']) && empty(getenv('JWT_SECRET'))) {
    $_ENV['JWT_SECRET'] = 'test-jwt-secret-for-phpunit-only';
    putenv('JWT_SECRET=test-jwt-secret-for-phpunit-only');
}

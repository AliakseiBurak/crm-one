<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/config/bootstrap.php')) {
    require dirname(__DIR__) . '/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    // bootEnv() загружает .env (APP_ENV=dev) и .env.test (APP_ENV=test),
    // но с $overrideExistingVars=false — .env.test НЕ перезаписывает
    // DATABASE_URL из .env (MySQL вместо SQLite). Решение: загрузить
    // .env.test повторно через overload() с $overrideExistingVars=true.
    new Dotenv()->bootEnv(dirname(__DIR__) . '/.env');
    if ('test' === ($_SERVER['APP_ENV'] ?? null)) {
        new Dotenv()->overload(dirname(__DIR__) . '/.env.test');
    }
}

$appEnv = $_SERVER['APP_ENV'] ?? null;
if (null !== $appEnv && ($_SERVER['BOOTSTRAP_CLEAR_CACHE_ENV'] ?? null) === $appEnv) {
    (new Filesystem())->remove(dirname(__DIR__) . '/var/cache/' . $appEnv);
}

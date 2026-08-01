<?php
/*
 * Loads environment variables from a .env file using vlucas/phpdotenv, so
 * config.php (and anything else) can read secrets/settings without
 * hardcoding them in a version-controlled PHP file.
 *
 * .env is git-ignored and holds real secrets locally; .env.example
 * documents the expected keys with placeholder values and is safe to
 * commit.
 */
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

/**
 * @param string $dir Directory containing the .env file.
 */
function loadEnv(string $dir): void
{
    // createImmutable() + safeLoad(): "immutable" means it never overwrites
    // a variable that's already set in the real environment (e.g. by the
    // web server, Docker, or a process manager) - the .env file is only a
    // local fallback. "safeLoad" means it silently does nothing if no .env
    // file is present, instead of throwing.
    Dotenv::createImmutable($dir)->safeLoad();
}

/**
 * Reads an env var with a default fallback.
 *
 * vlucas/phpdotenv (v5) populates $_ENV and $_SERVER by default but does
 * NOT call putenv() (putenv() is process-global and not thread-safe under
 * some SAPIs, e.g. php-fpm workers sharing a process), so getenv() alone
 * would miss anything loaded from .env. Check $_ENV/$_SERVER first, then
 * fall back to getenv() to still pick up real process/webserver-level
 * environment variables set outside of .env.
 *
 * @param mixed $default
 * @return mixed
 */
function env(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
}

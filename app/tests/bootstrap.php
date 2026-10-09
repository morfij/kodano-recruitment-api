<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// No secret is committed for the test env; tests do not depend on its value.
if ('' === ($_SERVER['APP_SECRET'] ?? '')) {
    $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = bin2hex(random_bytes(16));
}

// Same for the API token; the functional tests send whatever value is set here.
if ('' === ($_SERVER['API_TOKEN'] ?? '')) {
    $_SERVER['API_TOKEN'] = $_ENV['API_TOKEN'] = bin2hex(random_bytes(32));
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

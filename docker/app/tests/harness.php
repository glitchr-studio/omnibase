<?php

// The PHPUnit bootstrap of `demo test <suite>`: the application's environment
// first (.env, then .env.test - the variables the bundles' configuration reads
// and KERNEL_CLASS for the suites that boot the kernel), as the application's
// own tests/bootstrap.php would; then the suite's own bootstrap, which
// registers its test namespace (a host's autoloader never reads a
// dependency's autoload-dev). demo.sh names it in HARNESS_SUITE_BOOTSTRAP.

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env', 'test');

$suite = getenv('HARNESS_SUITE_BOOTSTRAP') ?: null;
if ($suite && is_file($suite)) {
    require $suite;
}

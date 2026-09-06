<?php

declare(strict_types=1);

$core = getenv('WEEWX_PHP_ROOT');
if ($core === false || !is_file($core . '/tests/bootstrap.php')) {
    throw new RuntimeException('Set WEEWX_PHP_ROOT to a weewx-php checkout with extension API 2 and its test dependencies.');
}
require $core . '/tests/bootstrap.php';

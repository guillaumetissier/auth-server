<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// The test kernel runs without debug, so it never notices new or changed files (classes, services,
// templates) and would keep using a container compiled from older code: start each run from scratch.
(new Filesystem())->remove(dirname(__DIR__).'/var/cache/test');

// Token signing keys for the test environment, generated once.
$keyDir = dirname(__DIR__).'/var/test-keys';
if (!is_file($keyDir.'/private.pem')) {
    @mkdir($keyDir, 0700, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    $details = false === $key ? false : openssl_pkey_get_details($key);
    if (false === $key || false === $details || !openssl_pkey_export($key, $privatePem) || !is_string($privatePem)) {
        throw new RuntimeException('Cannot generate the test signing keys: '.openssl_error_string());
    }
    file_put_contents($keyDir.'/private.pem', $privatePem);
    chmod($keyDir.'/private.pem', 0600);
    file_put_contents($keyDir.'/public.pem', $details['key']);
}

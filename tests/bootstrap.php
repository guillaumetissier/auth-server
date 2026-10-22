<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Token signing keys for the test environment, generated once.
$keyDir = dirname(__DIR__).'/var/test-keys';
if (!is_file($keyDir.'/private.pem')) {
    @mkdir($keyDir, 0700, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    file_put_contents($keyDir.'/private.pem', $privatePem);
    chmod($keyDir.'/private.pem', 0600);
    file_put_contents($keyDir.'/public.pem', openssl_pkey_get_details($key)['key']);
}

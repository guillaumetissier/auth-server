<?php

declare(strict_types=1);

/**
 * Minimal webhook receiver for manual tests. Verifies the signature and logs each event.
 *
 *   WEBHOOK_SECRET=... php -S 0.0.0.0:9000 tools/webhook-receiver.php
 */

require __DIR__.'/../vendor/autoload.php';

use GuillaumeTissier\ResourceGuard\Webhook\WebhookEvent;
use GuillaumeTissier\ResourceGuard\Webhook\WebhookSignature;

$payload = (string) file_get_contents('php://input');
$header = $_SERVER['HTTP_X_AUTH_SERVER_SIGNATURE'] ?? null;
$valid = (new WebhookSignature((string) getenv('WEBHOOK_SECRET')))->verify($payload, $header);

$line = $valid
    ? sprintf('VALID   %s user=%s %s', ($e = WebhookEvent::fromJson($payload))->type, $e->userId() ?? '-', json_encode($e->data))
    : 'INVALID signature: '.$payload;

file_put_contents('php://stderr', '['.date('H:i:s').'] '.$line."\n");
http_response_code($valid ? 204 : 401);

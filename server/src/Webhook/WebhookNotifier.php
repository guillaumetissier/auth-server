<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Event\AccountEvent;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookEvent;
use GuillaumeTissier\OAuthResourceGuard\Webhook\WebhookSignature;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Forwards account events to the attached applications as HMAC-signed webhooks.
 *
 * Events are queued during the request and delivered on kernel.terminate, i.e. after the
 * response has been sent to the browser, so a slow receiver never delays a login.
 * Failures are logged, never thrown. For guaranteed delivery (retries), move the sending
 * part to Symfony Messenger.
 */
final class WebhookNotifier implements ResetInterface
{
    private ?WebhookSignature $signature = null;

    /** @var list<string> */
    private array $pending = [];

    /**
     * @param list<string> $urls
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly array $urls,
        #[\SensitiveParameter] private readonly string $secret,
    ) {
    }

    #[AsEventListener(event: AccountEvent::class)]
    public function queue(AccountEvent $event): void
    {
        if ([] === $this->targets()) {
            return;
        }

        $this->pending[] = (new WebhookEvent(
            id: Uuid::v7()->toRfc4122(),
            type: $event->type,
            occurredAt: $event->occurredAt,
            data: ['user_id' => $event->user->getExternalUserId(), 'username' => $event->user->getUsername()] + $event->context,
        ))->toJson();
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function flush(): void
    {
        if ([] === $this->pending) {
            return;
        }

        $payloads = $this->pending;
        $this->pending = [];
        $this->signature ??= new WebhookSignature($this->secret);

        $responses = [];
        foreach ($payloads as $payload) {
            foreach ($this->targets() as $url) {
                $responses[] = [$url, $this->httpClient->request('POST', $url, [
                    'body' => $payload,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        WebhookSignature::HEADER => $this->signature->sign($payload),
                    ],
                ])];
            }
        }

        // Requests run concurrently; reading the status codes waits for all of them.
        foreach ($responses as [$url, $response]) {
            try {
                $status = $response->getStatusCode();
                if ($status >= 300) {
                    $this->logger->warning('Webhook delivery rejected', ['url' => $url, 'status' => $status]);
                }
            } catch (ExceptionInterface $e) {
                $this->logger->error('Webhook delivery failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * @return list<string>
     */
    private function targets(): array
    {
        return array_values(array_filter(array_map('trim', $this->urls)));
    }
}

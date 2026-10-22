<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Captures outgoing HTTP requests (webhooks) in tests. Static storage survives the kernel
 * reboots the test client performs between requests.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public static array $requests = [];

    private MockHttpClient $mock;

    public function __construct()
    {
        $this->mock = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204]));
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return $this->mock->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->mock->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

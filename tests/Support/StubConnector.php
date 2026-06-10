<?php

namespace LEClient\Tests\Support;

class StubConnector
{
    public string $accountURL = 'https://acme.test/account/1';
    public array $accountKeys = [];
    public string $newOrder = 'https://acme.test/acme/new-order';
    public string $newAccount = 'https://acme.test/acme/new-account';
    public string $revokeCert = 'https://acme.test/acme/revoke-cert';
    public string $keyChange = 'https://acme.test/acme/key-change';
    public bool $accountDeactivated = false;

    /** @var array<string, array> */
    private array $responses = [];

    /** @var list<array{method: string, url: string}> */
    public array $calls = [];

    public function __construct(array $accountKeys = [])
    {
        $this->accountKeys = $accountKeys;
    }

    public function queueResponse(string $method, string $url, array $response): void
    {
        $this->responses[strtoupper($method) . ' ' . $url][] = $response;
    }

    public function get(string $url): array
    {
        return $this->respond('GET', $url);
    }

    public function post(string $url, $data = null): array
    {
        return $this->respond('POST', $url);
    }

    public function head(string $url): array
    {
        return $this->respond('HEAD', $url);
    }

    public function signRequestJWK($payload, $url, $privateKeyFile = ''): string
    {
        return json_encode(['protected' => 'stub', 'payload' => 'stub', 'signature' => 'stub']);
    }

    public function signRequestKid($payload, $kid, $url, $privateKeyFile = ''): string
    {
        return json_encode(['protected' => 'stub', 'payload' => 'stub', 'signature' => 'stub']);
    }

    private function respond(string $method, string $url): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url];
        $key = $method . ' ' . $url;

        if (!isset($this->responses[$key]) || count($this->responses[$key]) === 0) {
            return [
                'request' => $method . ' ' . $url,
                'header' => '',
                'status' => 404,
                'body' => ['type' => 'about:blank', 'detail' => 'No stub response'],
            ];
        }

        return array_shift($this->responses[$key]);
    }
}

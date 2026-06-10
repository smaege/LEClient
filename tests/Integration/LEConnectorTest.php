<?php

namespace LEClient\Tests\Integration;

use LEClient\Exceptions\LEConnectorException;
use LEClient\LEClient;
use LEClient\LEConnector;
use LEClient\Tests\Support\IntegrationTestCase;
use LEClient\Tests\Support\KeyFactory;
use ReflectionClass;

class LEConnectorTest extends IntegrationTestCase
{
    public function testDirectoryFetchPopulatesEndpoints(): void
    {
        $connector = $this->createConnector();

        $this->assertStringContainsString('/acme/new-account', $connector->newAccount);
        $this->assertStringContainsString('/acme/new-order', $connector->newOrder);
        $this->assertStringContainsString('/acme/revoke-cert', $connector->revokeCert);
        $this->assertStringContainsString('/acme/key-change', $connector->keyChange);
        $this->assertStringContainsString('/acme/new-nonce', $connector->newNonce);
    }

    public function testSignRequestJwkProducesValidJws(): void
    {
        $connector = $this->createConnector();
        $signed = $connector->signRequestJWK(['contact' => ['mailto:test@example.com']], $connector->newAccount);
        $decoded = json_decode($signed, true);

        $this->assertArrayHasKey('protected', $decoded);
        $this->assertArrayHasKey('payload', $decoded);
        $this->assertArrayHasKey('signature', $decoded);
    }

    public function testSignRequestKidProducesValidJws(): void
    {
        $connector = $this->createConnector();
        $connector->accountURL = $this->baseUrl() . '/acme/account/account-1';
        $signed = $connector->signRequestKid(['status' => 'valid'], $connector->accountURL, $connector->accountURL);
        $decoded = json_decode($signed, true);

        $this->assertArrayHasKey('protected', $decoded);
        $this->assertArrayHasKey('payload', $decoded);
        $this->assertArrayHasKey('signature', $decoded);
    }

    public function testInvalidStatusCodeThrowsInvalidResponseException(): void
    {
        $connector = $this->createConnector();

        $this->expectException(LEConnectorException::class);

        $connector->get('/error/500');
    }

    public function testDeactivatedAccountThrows(): void
    {
        $connector = $this->createConnector();
        $connector->accountDeactivated = true;

        $this->expectException(LEConnectorException::class);
        $this->expectExceptionMessage('The account was deactivated. No further requests can be made.');

        $connector->get('/directory');
    }

    public function testUnsupportedHttpMethodThrows(): void
    {
        $connector = $this->createConnector();
        $reflection = new ReflectionClass($connector);
        $method = $reflection->getMethod('request');
        $method->setAccessible(true);

        $this->expectException(LEConnectorException::class);

        $method->invoke($connector, 'PATCH', '/directory');
    }

    private function createConnector(): LEConnector
    {
        return new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $this->accountKeys());
    }
}

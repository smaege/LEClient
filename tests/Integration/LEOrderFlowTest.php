<?php

namespace LEClient\Tests\Integration;

use LEClient\LEClient;
use LEClient\LEOrder;
use LEClient\Tests\Support\IntegrationTestCase;
use LEClient\Tests\Support\KeyFactory;

class LEOrderFlowTest extends IntegrationTestCase
{
    private function bootstrapOrder(array $domains = ['example.com']): LEOrder
    {
        $connector = $this->createConnectorWithAccount();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        return new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            $domains,
            'rsa-2048',
            '',
            ''
        );
    }

    public function testCreateOrderStoresOrderUrl(): void
    {
        $order = $this->bootstrapOrder();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        $this->assertSame('pending', $order->status);
        $this->assertFileExists($certificateKeys['order']);
        $this->assertNotEmpty(trim(file_get_contents($certificateKeys['order'])));
        $this->assertFileExists($certificateKeys['private_key']);
        $this->assertFileExists($certificateKeys['public_key']);
    }

    public function testGetPendingAuthorizationsReturnsChallengeData(): void
    {
        $order = $this->bootstrapOrder();
        $pending = $order->getPendingAuthorizations(LEOrder::CHALLENGE_TYPE_HTTP);

        $this->assertIsArray($pending);
        $this->assertSame(LEOrder::CHALLENGE_TYPE_HTTP, $pending[0]['type']);
        $this->assertSame('example.com', $pending[0]['identifier']);
        $this->assertNotEmpty($pending[0]['filename']);
        $this->assertNotEmpty($pending[0]['content']);
    }

    public function testVerifyAuthorizationWithoutLocalCheck(): void
    {
        $order = $this->bootstrapOrder();

        $this->assertTrue($order->verifyPendingOrderAuthorization('example.com', LEOrder::CHALLENGE_TYPE_HTTP, false));
        $this->assertTrue($order->allAuthorizationsValid());
    }

    public function testFinalizeOrderWhenReady(): void
    {
        $order = $this->bootstrapOrder();
        $order->verifyPendingOrderAuthorization('example.com', LEOrder::CHALLENGE_TYPE_HTTP, false);

        $this->assertTrue($order->finalizeOrder());
        $this->assertTrue($order->isFinalized());
        $this->assertSame('valid', $order->status);
    }

    public function testGetCertificateWritesFiles(): void
    {
        $order = $this->bootstrapOrder();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        $order->verifyPendingOrderAuthorization('example.com', LEOrder::CHALLENGE_TYPE_HTTP, false);
        $order->finalizeOrder();

        $this->assertTrue($order->getCertificate());
        $this->assertFileExists($certificateKeys['certificate']);
        $this->assertFileExists($certificateKeys['fullchain_certificate']);
        $this->assertNotFalse(openssl_x509_parse(file_get_contents($certificateKeys['certificate'])));

        $this->assertSame(1, substr_count(file_get_contents($certificateKeys['certificate']), 'BEGIN CERTIFICATE'));
        $this->assertSame(
            3,
            substr_count(file_get_contents($certificateKeys['fullchain_certificate']), 'BEGIN CERTIFICATE')
        );
    }

    public function testDomainMismatchCreatesNewOrder(): void
    {
        $connector = $this->createConnectorWithAccount();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['example.com'],
            'rsa-2048',
            '',
            ''
        );

        $firstOrderUrl = trim(file_get_contents($certificateKeys['order']));

        new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['other.example.com'],
            'rsa-2048',
            '',
            ''
        );

        $secondOrderUrl = trim(file_get_contents($certificateKeys['order']));

        $this->assertNotSame($firstOrderUrl, $secondOrderUrl);
        $this->assertFileExists($certificateKeys['private_key'] . '.old');
    }

    public function testRevokeCertificate(): void
    {
        $order = $this->bootstrapOrder();
        $order->verifyPendingOrderAuthorization('example.com', LEOrder::CHALLENGE_TYPE_HTTP, false);
        $order->finalizeOrder();
        $order->getCertificate();

        $this->assertTrue($order->revokeCertificate());
    }
}

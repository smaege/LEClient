<?php

namespace LEClient\Tests\Unit;

use LEClient\LEClient;
use LEClient\LEOrder;
use LEClient\Tests\Support\AcmeResponseFactory;
use LEClient\Tests\Support\KeyFactory;
use LEClient\Tests\Support\StubConnector;
use LEClient\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class CertificateParsingTest extends TestCase
{
    private TempDirectory $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tempDir->cleanup();
    }

    public function testGetCertificateParsesAndSavesPemFiles(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());
        $intermediate = trim(AcmeResponseFactory::chainPem());

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n" . $intermediate . "\n",
        ]);

        $this->assertTrue($order->getCertificate());
        $this->assertFileExists($this->certificateKeys['certificate']);
        $this->assertFileExists($this->certificateKeys['fullchain_certificate']);

        $this->assertSame($leaf, trim(file_get_contents($this->certificateKeys['certificate'])));

        $fullchain = file_get_contents($this->certificateKeys['fullchain_certificate']);
        $this->assertSame(2, substr_count($fullchain, 'BEGIN CERTIFICATE'));
        $this->assertStringContainsString($leaf, $fullchain);
        $this->assertStringContainsString($intermediate, $fullchain);
    }

    public function testGetCertificateSavesEntireGenerationYChain(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());
        $intermediate = trim(AcmeResponseFactory::chainPem());
        $root = trim(AcmeResponseFactory::rootPem());

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n" . $intermediate . "\n" . $root . "\n",
        ]);

        $this->assertTrue($order->getCertificate());

        $certificate = file_get_contents($this->certificateKeys['certificate']);
        $this->assertSame(1, substr_count($certificate, 'BEGIN CERTIFICATE'));
        $this->assertSame($leaf, trim($certificate));

        $fullchain = file_get_contents($this->certificateKeys['fullchain_certificate']);
        $this->assertSame(3, substr_count($fullchain, 'BEGIN CERTIFICATE'));
        $this->assertStringContainsString($leaf, $fullchain);
        $this->assertStringContainsString($intermediate, $fullchain);
        $this->assertStringContainsString($root, $fullchain);
    }

    public function testGetCertificateSavesSingleCertificateResponse(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n",
        ]);

        $this->assertTrue($order->getCertificate());
        $this->assertSame($leaf, trim(file_get_contents($this->certificateKeys['certificate'])));
        $this->assertSame($leaf, trim(file_get_contents($this->certificateKeys['fullchain_certificate'])));
    }

    public function testGetCertificateSavesFullchainOnlySingleCertificateResponse(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());
        $certificateKeys = $this->certificateKeys();
        unset($certificateKeys['certificate']);

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n",
        ], $certificateKeys);

        $this->assertTrue($order->getCertificate());
        $this->assertSame($leaf, trim(file_get_contents($this->certificateKeys['fullchain_certificate'])));
    }

    public function testGetCertificateReturnsFalseWhenCertificateCannotBeWritten(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());
        $certificateKeys = $this->certificateKeys();
        $blockedPath = $this->tempDir->path() . '/not-a-directory';
        file_put_contents($blockedPath, 'blocking file');
        $certificateKeys['certificate'] = $blockedPath . '/certificate.crt';

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n",
        ], $certificateKeys);

        $this->assertFalse($order->getCertificate());
    }

    public function testGetCertificateRestoresCertificateWhenFullchainCannotBeWritten(): void
    {
        $leaf = trim(AcmeResponseFactory::certificatePem());
        $certificateKeys = $this->certificateKeys();
        $previousCertificate = 'previous certificate contents';
        file_put_contents($certificateKeys['certificate'], $previousCertificate);

        $blockedPath = $this->tempDir->path() . '/not-a-directory';
        file_put_contents($blockedPath, 'blocking file');
        $certificateKeys['fullchain_certificate'] = $blockedPath . '/fullchain.crt';

        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => $leaf . "\n",
        ], $certificateKeys);

        $this->assertFalse($order->getCertificate());
        $this->assertSame($previousCertificate, file_get_contents($certificateKeys['certificate']));
        $this->assertFileDoesNotExist($certificateKeys['fullchain_certificate']);
    }

    public function testGetCertificateReturnsFalseForInvalidPem(): void
    {
        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 200,
            'body' => 'not a certificate',
        ]);

        $this->assertFalse($order->getCertificate());
    }

    public function testGetCertificateReturnsFalseForNon200Response(): void
    {
        $order = $this->createValidOrderWithStub([
            'request' => 'POST',
            'header' => '',
            'status' => 500,
            'body' => ['type' => 'about:blank'],
        ]);

        $this->assertFalse($order->getCertificate());
    }

    private function certificateKeys(): array
    {
        return KeyFactory::certificateKeyPaths($this->tempDir->path());
    }

    private function createValidOrderWithStub(array $certResponse, ?array $certificateKeys = null): LEOrder
    {
        $accountKeys = KeyFactory::generateAccountKeys($this->tempDir->path());
        $certificateKeys = $certificateKeys ?? $this->certificateKeys();
        \LEClient\LEFunctions::RSAGenerateKeys(null, $certificateKeys['private_key'], $certificateKeys['public_key'], 2048);

        $orderUrl = 'https://acme.test/order/1';
        $certUrl = 'https://acme.test/acme/cert/1';
        file_put_contents($certificateKeys['order'], $orderUrl);

        $connector = new StubConnector($accountKeys);
        $connector->queueResponse('POST', $orderUrl, [
            'request' => 'POST ' . $orderUrl,
            'header' => '',
            'status' => 200,
            'body' => [
                'status' => 'valid',
                'expires' => gmdate('Y-m-d\TH:i:s\Z'),
                'identifiers' => [['type' => 'dns', 'value' => 'example.com']],
                'authorizations' => [],
                'finalize' => 'https://acme.test/finalize/1',
                'certificate' => $certUrl,
            ],
        ]);
        $connector->queueResponse('POST', $certUrl, $certResponse);

        $order = new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['example.com'],
            'rsa-2048',
            '',
            ''
        );

        $this->certificateKeys = $certificateKeys;

        return $order;
    }

    private array $certificateKeys = [];
}

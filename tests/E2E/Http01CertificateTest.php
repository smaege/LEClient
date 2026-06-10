<?php

namespace LEClient\Tests\E2E;

use LEClient\LEClient;
use LEClient\LEOrder;
use LEClient\Tests\Support\KeyFactory;
use LEClient\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

/**
 * @group e2e
 */
class Http01CertificateTest extends TestCase
{
    private ?TempDirectory $tempDir = null;

    protected function setUp(): void
    {
        if (!getenv('PEBBLE_URL')) {
            $this->markTestSkipped('PEBBLE_URL environment variable is not set.');
        }

        $this->tempDir = new TempDirectory('leclient-e2e-');
    }

    protected function tearDown(): void
    {
        $this->tempDir?->cleanup();
    }

    public function testHttp01CertificateIssuance(): void
    {
        $pebbleUrl = rtrim(getenv('PEBBLE_URL'), '/');
        $domain = getenv('PEBBLE_DOMAIN') ?: 'localhost';
        $challTestSrv = rtrim(getenv('CHALLTESTSRV_URL') ?: 'http://127.0.0.1:8055', '/');

        $keysDir = $this->tempDir->path() . '/keys/';
        mkdir($keysDir, 0755, true);

        $client = new LEClient(
            ['e2e-test@example.com'],
            $pebbleUrl,
            LEClient::LOG_OFF,
            $keysDir,
            '__account/',
            false,
            false
        );

        $order = $client->getOrCreateOrder($domain, [$domain], 'rsa-2048');
        $pending = $order->getPendingAuthorizations(LEOrder::CHALLENGE_TYPE_HTTP);

        $this->assertIsArray($pending);
        $this->configureChallengeServer($challTestSrv, $domain, $pending[0]);

        $this->assertTrue($order->verifyPendingOrderAuthorization($domain, LEOrder::CHALLENGE_TYPE_HTTP, false));
        $this->assertTrue($order->finalizeOrder());
        $this->assertTrue($order->getCertificate());

        $certificatePath = $keysDir . 'certificate.crt';
        $this->assertFileExists($certificatePath);
        $this->assertNotFalse(openssl_x509_parse(file_get_contents($certificatePath)));
    }

    private function configureChallengeServer(string $challTestSrv, string $domain, array $authorization): void
    {
        $payload = json_encode([
            'token' => $authorization['filename'],
            'content' => $authorization['content'],
        ]);

        $handle = curl_init($challTestSrv . '/add-http01');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            $this->markTestSkipped('Challenge test server is not available: ' . (string) $response);
        }
    }
}

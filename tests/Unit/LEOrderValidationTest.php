<?php

namespace LEClient\Tests\Unit;

use LEClient\Exceptions\LEOrderException;
use LEClient\LEClient;
use LEClient\LEOrder;
use LEClient\Tests\Support\KeyFactory;
use LEClient\Tests\Support\StubConnector;
use LEClient\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class LEOrderValidationTest extends TestCase
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

    /**
     * @dataProvider validKeyTypeProvider
     */
    public function testAcceptsValidKeyTypes(string $keyType): void
    {
        $order = $this->createOrder($keyType);
        $this->assertInstanceOf(LEOrder::class, $order);
    }

    public static function validKeyTypeProvider(): array
    {
        return [
            ['rsa'],
            ['ec'],
            ['rsa-4096'],
            ['ec-256'],
        ];
    }

    public function testRejectsInvalidKeyType(): void
    {
        $this->expectException(LEOrderException::class);

        $this->createOrder('foo-123');
    }

    public function testRejectsInvalidNotBeforeFormat(): void
    {
        $this->expectException(LEOrderException::class);
        $this->expectExceptionMessage('notBefore and notAfter fields must be empty or be a string similar to 0000-00-00T00:00:00Z');

        $this->createOrder('rsa-4096', 'invalid-date', '');
    }

    public function testAcceptsValidDateFormat(): void
    {
        $order = $this->createOrder('rsa-4096', '2026-01-01T00:00:00Z', '2026-04-01T00:00:00Z');
        $this->assertInstanceOf(LEOrder::class, $order);
    }

    public function testRejectsMultipleWildcardsInDomain(): void
    {
        $connector = new StubConnector();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        $connector->queueResponse('POST', $connector->newOrder, [
            'request' => 'POST',
            'header' => 'Location: https://acme.test/order/1',
            'status' => 201,
            'body' => [
                'status' => 'pending',
                'expires' => gmdate('Y-m-d\TH:i:s\Z'),
                'identifiers' => [['type' => 'dns', 'value' => '*.*.example.com']],
                'authorizations' => [],
                'finalize' => 'https://acme.test/finalize/1',
            ],
        ]);

        $this->expectException(LEOrderException::class);
        $this->expectExceptionMessage('Cannot create orders with multiple wildcards in one domain.');

        new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['*.*.example.com'],
            'rsa-4096',
            '',
            ''
        );
    }

    private function createOrder(string $keyType, string $notBefore = '', string $notAfter = ''): LEOrder
    {
        $connector = new StubConnector();
        $certificateKeys = KeyFactory::certificateKeyPaths($this->tempDir->path());

        $connector->queueResponse('POST', $connector->newOrder, [
            'request' => 'POST',
            'header' => 'Location: https://acme.test/order/1',
            'status' => 201,
            'body' => [
                'status' => 'pending',
                'expires' => gmdate('Y-m-d\TH:i:s\Z'),
                'identifiers' => [['type' => 'dns', 'value' => 'example.com']],
                'authorizations' => [],
                'finalize' => 'https://acme.test/finalize/1',
            ],
        ]);

        return new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['example.com'],
            $keyType,
            $notBefore,
            $notAfter
        );
    }
}

<?php

namespace LEClient\Tests\Unit;

use LEClient\Exceptions\LEClientException;
use LEClient\LEClient;
use LEClient\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class LEClientConfigTest extends TestCase
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

    public function testInvalidAcmeUrlTypeThrows(): void
    {
        $this->expectException(LEClientException::class);
        $this->expectExceptionMessage('acmeURL must be set to string or bool (legacy).');

        new LEClient(['test@example.com'], 123);
    }

    public function testMixedCertificateAndAccountKeyTypesThrow(): void
    {
        $this->expectException(LEClientException::class);
        $this->expectExceptionMessage('When certificateKeys is array, accountKeys must be array too.');

        new LEClient(
            ['test@example.com'],
            'http://127.0.0.1:1',
            LEClient::LOG_OFF,
            ['private_key' => '/tmp/private.pem'],
            '__account/'
        );
    }

    public function testArrayCertificateKeysMissingPrivateKeyThrows(): void
    {
        $this->expectException(LEClientException::class);
        $this->expectExceptionMessage('certificateKeys[private_key] file path must be set.');

        new LEClient(
            ['test@example.com'],
            'http://127.0.0.1:1',
            LEClient::LOG_OFF,
            ['certificate' => $this->tempDir->file('cert.crt')],
            ['private_key' => $this->tempDir->file('account.pem'), 'public_key' => $this->tempDir->file('account.pub')]
        );
    }

    public function testArrayCertificateKeysMissingCertificatePathThrows(): void
    {
        $this->expectException(LEClientException::class);
        $this->expectExceptionMessage('certificateKeys[certificate] or certificateKeys[fullchain_certificate] file path must be set.');

        new LEClient(
            ['test@example.com'],
            'http://127.0.0.1:1',
            LEClient::LOG_OFF,
            ['private_key' => $this->tempDir->file('private.pem')],
            ['private_key' => $this->tempDir->file('account.pem'), 'public_key' => $this->tempDir->file('account.pub')]
        );
    }

    public function testArrayCertificateKeysWithMissingParentDirectoryThrows(): void
    {
        $this->expectException(LEClientException::class);

        new LEClient(
            ['test@example.com'],
            'http://127.0.0.1:1',
            LEClient::LOG_OFF,
            [
                'private_key' => '/nonexistent-dir-' . bin2hex(random_bytes(4)) . '/private.pem',
                'certificate' => '/nonexistent-dir-' . bin2hex(random_bytes(4)) . '/cert.crt',
            ],
            [
                'private_key' => $this->tempDir->file('account.pem'),
                'public_key' => $this->tempDir->file('account.pub'),
            ]
        );
    }

    public function testInvalidCertificateKeysTypeThrows(): void
    {
        $this->expectException(LEClientException::class);
        $this->expectExceptionMessage('certificateKeys must be string or array.');

        new LEClient(['test@example.com'], 'http://127.0.0.1:1', LEClient::LOG_OFF, 123);
    }
}

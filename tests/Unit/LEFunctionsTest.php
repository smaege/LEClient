<?php

namespace LEClient\Tests\Unit;

use LEClient\Exceptions\LEFunctionsException;
use LEClient\LEFunctions;
use LEClient\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class LEFunctionsTest extends TestCase
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

    public function testBase64UrlSafeEncodeDecodeRoundTrip(): void
    {
        $input = 'hello acme challenge';
        $encoded = LEFunctions::Base64UrlSafeEncode($input);
        $decoded = LEFunctions::Base64UrlSafeDecode($encoded);

        $this->assertSame($input, $decoded);
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
    }

    public function testBase64UrlSafeDecodeHandlesMissingPadding(): void
    {
        $encoded = LEFunctions::Base64UrlSafeEncode('test');
        $withoutPadding = rtrim($encoded, '=');

        $this->assertSame('test', LEFunctions::Base64UrlSafeDecode($withoutPadding));
    }

    public function testRSAGenerateKeysWritesKeyFiles(): void
    {
        $private = $this->tempDir->file('private.pem');
        $public = $this->tempDir->file('public.pem');

        LEFunctions::RSAGenerateKeys(null, $private, $public, 2048);

        $this->assertFileExists($private);
        $this->assertFileExists($public);
        $this->assertStringContainsString('BEGIN', file_get_contents($private));
        $this->assertStringContainsString('BEGIN', file_get_contents($public));
    }

    public function testRSAGenerateKeysRejectsInvalidSize(): void
    {
        $this->expectException(LEFunctionsException::class);
        $this->expectExceptionMessage('RSA key size must be between 2048 and 4096.');

        LEFunctions::RSAGenerateKeys($this->tempDir->path(), 'private.pem', 'public.pem', 1024);
    }

    public function testECGenerateKeysWritesKeyFiles(): void
    {
        $private = $this->tempDir->file('ec-private.pem');
        $public = $this->tempDir->file('ec-public.pem');

        LEFunctions::ECGenerateKeys(null, $private, $public, 256);

        $this->assertFileExists($private);
        $this->assertFileExists($public);
    }

    public function testECGenerateKeysRejectsInvalidSize(): void
    {
        $this->expectException(LEFunctionsException::class);
        $this->expectExceptionMessage('EC key size must be 256 or 384.');

        LEFunctions::ECGenerateKeys($this->tempDir->path(), 'private.pem', 'public.pem', 512);
    }

    public function testCreateHtaccessWritesExpectedContent(): void
    {
        LEFunctions::createhtaccess($this->tempDir->path() . '/');

        $path = $this->tempDir->file('.htaccess');
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('Require all denied', $content);
        $this->assertStringContainsString('Deny from all', $content);
    }
}

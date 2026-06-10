<?php

namespace LEClient\Tests\Support;

use LEClient\LEAccount;
use LEClient\LEClient;
use LEClient\LEConnector;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected static MockAcmeServer $server;
    protected TempDirectory $tempDir;

    public static function setUpBeforeClass(): void
    {
        self::$server = MockAcmeServer::getInstance();
        self::$server->resetState();
    }

    protected function setUp(): void
    {
        self::$server->resetState();
        $this->tempDir = new TempDirectory();
    }

    protected function tearDown(): void
    {
        $this->tempDir->cleanup();
    }

    protected function baseUrl(): string
    {
        return self::$server->getBaseUrl();
    }

    protected function accountKeys(): array
    {
        return KeyFactory::generateAccountKeys($this->tempDir->path());
    }

    protected function certificateKeys(): array
    {
        return KeyFactory::certificateKeyPaths($this->tempDir->path());
    }

    protected function createConnectorWithAccount(?array $accountKeys = null): LEConnector
    {
        if ($accountKeys === null) {
            $accountKeys = [
                'private_key' => $this->tempDir->file('account-private.pem'),
                'public_key' => $this->tempDir->file('account-public.pem'),
            ];
        }

        $connector = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);
        new LEAccount($connector, LEClient::LOG_OFF, ['test@example.com'], $accountKeys);

        return $connector;
    }
}

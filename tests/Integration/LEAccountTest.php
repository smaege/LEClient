<?php

namespace LEClient\Tests\Integration;

use LEClient\Exceptions\LEAccountException;
use LEClient\LEAccount;
use LEClient\LEClient;
use LEClient\LEConnector;
use LEClient\Tests\Support\IntegrationTestCase;
use LEClient\Tests\Support\KeyFactory;

class LEAccountTest extends IntegrationTestCase
{
    public function testCreatesNewAccountWhenKeysMissing(): void
    {
        $accountKeys = [
            'private_key' => $this->tempDir->file('account-private.pem'),
            'public_key' => $this->tempDir->file('account-public.pem'),
        ];

        $connector = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);
        $account = new LEAccount($connector, LEClient::LOG_OFF, ['test@example.com'], $accountKeys);

        $this->assertNotEmpty($connector->accountURL);
        $this->assertSame('valid', $account->status);
        $this->assertFileExists($accountKeys['private_key']);
        $this->assertFileExists($accountKeys['public_key']);
    }

    public function testLoadsExistingAccount(): void
    {
        $accountKeys = [
            'private_key' => $this->tempDir->file('account-private.pem'),
            'public_key' => $this->tempDir->file('account-public.pem'),
        ];

        $connector = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);
        $first = new LEAccount($connector, LEClient::LOG_OFF, ['test@example.com'], $accountKeys);
        $accountUrl = $connector->accountURL;

        $connector2 = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);
        $second = new LEAccount($connector2, LEClient::LOG_OFF, ['test@example.com'], $accountKeys);

        $this->assertSame($accountUrl, $connector2->accountURL);
        $this->assertSame('valid', $second->status);
        $this->assertSame($first->contact, $second->contact);
    }

    public function testNormalizesContactEmailPrefix(): void
    {
        $accountKeys = [
            'private_key' => $this->tempDir->file('account-private.pem'),
            'public_key' => $this->tempDir->file('account-public.pem'),
        ];

        $connector = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);
        $account = new LEAccount($connector, LEClient::LOG_OFF, ['admin@example.com'], $accountKeys);

        $this->assertContains('mailto:admin@example.com', $account->contact);
    }

    public function testAccountNotFoundThrows(): void
    {
        self::$server->resetState();

        $accountKeys = KeyFactory::generateAccountKeys($this->tempDir->path());
        $connector = new LEConnector(LEClient::LOG_OFF, $this->baseUrl(), $accountKeys);

        $this->expectException(LEAccountException::class);

        new LEAccount($connector, LEClient::LOG_OFF, ['test@example.com'], $accountKeys);
    }
}

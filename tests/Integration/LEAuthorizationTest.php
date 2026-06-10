<?php

namespace LEClient\Tests\Integration;

use LEClient\Exceptions\LEAuthorizationException;
use LEClient\LEClient;
use LEClient\LEOrder;
use LEClient\Tests\Support\IntegrationTestCase;

class LEAuthorizationTest extends IntegrationTestCase
{
    public function testGetChallengeReturnsHttp01Data(): void
    {
        $order = $this->createOrder();
        $auth = $order->authorizations[0];

        $challenge = $auth->getChallenge(LEOrder::CHALLENGE_TYPE_HTTP);

        $this->assertSame('http-01', $challenge['type']);
        $this->assertNotEmpty($challenge['token']);
        $this->assertNotEmpty($challenge['url']);
    }

    public function testGetChallengeReturnsDns01Data(): void
    {
        $order = $this->createOrder();
        $auth = $order->authorizations[0];

        $challenge = $auth->getChallenge(LEOrder::CHALLENGE_TYPE_DNS);

        $this->assertSame('dns-01', $challenge['type']);
        $this->assertNotEmpty($challenge['token']);
    }

    public function testMissingChallengeTypeThrows(): void
    {
        $order = $this->createOrder();
        $auth = $order->authorizations[0];
        $auth->challenges = [$auth->getChallenge(LEOrder::CHALLENGE_TYPE_HTTP)];

        $this->expectException(LEAuthorizationException::class);

        $auth->getChallenge('tls-alpn-01');
    }

    private function createOrder(): LEOrder
    {
        $connector = $this->createConnectorWithAccount();
        $certificateKeys = $this->certificateKeys();

        return new LEOrder(
            $connector,
            LEClient::LOG_OFF,
            $certificateKeys,
            'example.com',
            ['example.com'],
            'rsa-2048',
            '',
            ''
        );
    }
}

<?php

namespace LEClient\Tests\Support;

class AcmeResponseFactory
{
    public static function directory(string $baseUrl): array
    {
        $template = json_decode(
            file_get_contents(dirname(__DIR__) . '/fixtures/acme/directory.json'),
            true
        );

        return [
            'newNonce' => $baseUrl . $template['newNonce'],
            'newAccount' => $baseUrl . $template['newAccount'],
            'newOrder' => $baseUrl . $template['newOrder'],
            'revokeCert' => $baseUrl . $template['revokeCert'],
            'keyChange' => $baseUrl . $template['keyChange'],
        ];
    }

    public static function orderBody(
        string $baseUrl,
        string $orderId,
        array $domains,
        string $status = 'pending',
        ?string $certificateUrl = null
    ): array {
        $authorizations = [];
        foreach ($domains as $domain) {
            $authorizations[] = $baseUrl . '/acme/authz/' . $orderId . '/' . self::domainSlug($domain);
        }

        $body = [
            'status' => $status,
            'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'identifiers' => array_map(fn ($domain) => ['type' => 'dns', 'value' => $domain], $domains),
            'authorizations' => $authorizations,
            'finalize' => $baseUrl . '/acme/finalize/' . $orderId,
        ];

        if ($certificateUrl !== null) {
            $body['certificate'] = $certificateUrl;
        }

        return $body;
    }

    public static function authorizationBody(string $domain, string $status = 'pending', string $challengeStatus = 'pending'): array
    {
        $token = 'test-token-' . self::domainSlug($domain);

        return [
            'identifier' => ['type' => 'dns', 'value' => $domain],
            'status' => $status,
            'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'challenges' => [
                [
                    'type' => 'http-01',
                    'status' => $challengeStatus,
                    'url' => 'http://mock.invalid/challenge/http/' . $token,
                    'token' => $token,
                ],
                [
                    'type' => 'dns-01',
                    'status' => $challengeStatus,
                    'url' => 'http://mock.invalid/challenge/dns/' . $token,
                    'token' => $token,
                ],
            ],
        ];
    }

    public static function accountBody(array $contact = ['mailto:test@example.com']): array
    {
        return [
            'status' => 'valid',
            'contact' => $contact,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'key' => [
                'kty' => 'RSA',
                'n' => 'test',
                'e' => 'AQAB',
            ],
        ];
    }

    public static function certificatePem(): string
    {
        $path = dirname(__DIR__) . '/fixtures/certificates/leaf.pem';
        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return self::generateCertificatePem();
    }

    public static function chainPem(): string
    {
        $path = dirname(__DIR__) . '/fixtures/certificates/chain.pem';
        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return self::generateCertificatePem('LEClient Test CA');
    }

    public static function rootPem(): string
    {
        $path = dirname(__DIR__) . '/fixtures/certificates/root.pem';
        if (file_exists($path)) {
            return file_get_contents($path);
        }

        return self::generateCertificatePem('ISRG Root YR');
    }

    private static function generateCertificatePem(string $cn = 'localhost'): string
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privkey = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => $cn], $privkey, $config);
        $cert = openssl_csr_sign($csr, null, $privkey, 1, $config);
        openssl_x509_export($cert, $pem);

        return $pem;
    }

    public static function domainSlug(string $domain): string
    {
        return preg_replace('/[^a-z0-9]+/i', '-', $domain);
    }
}

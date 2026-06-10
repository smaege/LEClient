<?php

namespace LEClient\Tests\Support;

use LEClient\LEFunctions;

class KeyFactory
{
    public static function generateAccountKeys(string $directory): array
    {
        $keys = [
            'private_key' => $directory . '/account-private.pem',
            'public_key' => $directory . '/account-public.pem',
        ];

        LEFunctions::RSAGenerateKeys(null, $keys['private_key'], $keys['public_key'], 2048);

        return $keys;
    }

    public static function certificateKeyPaths(string $directory): array
    {
        return [
            'public_key' => $directory . '/public.pem',
            'private_key' => $directory . '/private.pem',
            'certificate' => $directory . '/certificate.crt',
            'fullchain_certificate' => $directory . '/fullchain.crt',
            'order' => $directory . '/order',
        ];
    }
}

<?php
// HTTP-01 authorization example. Requires PHP 8.1+.
// Replace example.org with your domain and configure the challenge path below.

ini_set('max_execution_time', 120);

require __DIR__ . '/../vendor/autoload.php';

use LEClient\LEClient;
use LEClient\LEOrder;

$email = ['info@example.org'];
$basename = 'example.org';
$domains = ['example.org', 'test.example.org'];

$client = new LEClient($email, LEClient::LE_STAGING, LEClient::LOG_STATUS);
$order = $client->getOrCreateOrder($basename, $domains);

if (!$order->allAuthorizationsValid()) {
	$pending = $order->getPendingAuthorizations(LEOrder::CHALLENGE_TYPE_HTTP);

	if (!empty($pending)) {
		foreach ($pending as $challenge) {
			// Serve this file at http://{identifier}/.well-known/acme-challenge/{filename}
			$folder = '/path/to/' . $challenge['identifier'] . '/.well-known/acme-challenge/';
			if (!file_exists($folder)) {
				mkdir($folder, 0777, true);
			}
			file_put_contents($folder . $challenge['filename'], $challenge['content']);

			if (!$order->verifyPendingOrderAuthorization($challenge['identifier'], LEOrder::CHALLENGE_TYPE_HTTP)) {
				throw new RuntimeException('HTTP authorization failed for ' . $challenge['identifier']);
			}
		}
	}
}

if ($order->allAuthorizationsValid()) {
	if (!$order->isFinalized() && !$order->finalizeOrder()) {
		throw new RuntimeException('Order finalization failed.');
	}

	if ($order->isFinalized() && $order->getCertificate()) {
		// certificate.crt contains the leaf; fullchain.crt contains the complete chain
		// (including any Let's Encrypt Generation Y cross-signed root).
	}
}

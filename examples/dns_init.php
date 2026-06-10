<?php
// DNS-01 authorization (part 1): create the required TXT records, then run dns_finish.php.
// Requires PHP 8.1+. Replace example.org with your domain.

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
	$pending = $order->getPendingAuthorizations(LEOrder::CHALLENGE_TYPE_DNS);

	if (!empty($pending)) {
		foreach ($pending as $challenge) {
			// Create a TXT record at _acme-challenge.{identifier} with the value below.
			// Implement setDNSRecord() for your DNS provider, or create the records manually.
			setDNSRecord($challenge['identifier'], $challenge['DNSDigest']);
		}
	}
}

/**
 * @param string $identifier The domain name being authorized.
 * @param string $dnsDigest  The TXT record value from getPendingAuthorizations().
 */
function setDNSRecord(string $identifier, string $dnsDigest): void
{
	throw new RuntimeException('Implement setDNSRecord() for your DNS provider.');
}

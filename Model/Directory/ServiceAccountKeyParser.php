<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

use Jose\Component\KeyManagement\JWKFactory;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Parses and validates a Google service account JSON key file.
 *
 * `token_uri` is ignored: the token endpoint is fixed.
 */
class ServiceAccountKeyParser
{
    /**
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Parse the key JSON.
     *
     * @param string $json
     * @return ServiceAccountKey
     * @throws \InvalidArgumentException
     */
    public function parse(string $json): ServiceAccountKey
    {
        try {
            $data = $this->serializer->unserialize($json);
        } catch (\Throwable $e) {
            $data = null;
        }

        if (!is_array($data) || !str_starts_with(ltrim($json), '{')) {
            throw new \InvalidArgumentException('The service account key is not a JSON object.');
        }

        if (($data['type'] ?? null) !== 'service_account') {
            throw new \InvalidArgumentException(
                'The key is not a service account key (type must be "service_account").'
            );
        }

        $clientEmail = $data['client_email'] ?? null;
        if (!is_string($clientEmail) || filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('The service account key has no valid client_email.');
        }

        $privateKey = $data['private_key'] ?? null;
        if (!is_string($privateKey) || trim($privateKey) === '') {
            throw new \InvalidArgumentException('The service account key has no private_key.');
        }

        if (!$this->isRsaPrivateKey($privateKey)) {
            throw new \InvalidArgumentException('The service account private_key is not a valid RSA private key.');
        }

        $keyId = $data['private_key_id'] ?? null;
        $keyId = is_string($keyId) && trim($keyId) !== '' ? trim($keyId) : null;

        return new ServiceAccountKey($clientEmail, $privateKey, $keyId);
    }

    /**
     * Whether the PEM loads as an RSA private key.
     *
     * @param string $pem
     * @return bool
     */
    private function isRsaPrivateKey(string $pem): bool
    {
        try {
            $jwk = JWKFactory::createFromKey($pem);
        } catch (\Throwable $e) {
            return false;
        }

        return $jwk->get('kty') === 'RSA' && $jwk->has('d');
    }
}

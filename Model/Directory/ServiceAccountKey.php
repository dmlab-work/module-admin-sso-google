<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

/**
 * Parsed Google service account JSON key.
 */
class ServiceAccountKey
{
    /**
     * @param string $clientEmail
     * @param string $privateKey PEM-encoded RSA private key
     * @param string|null $privateKeyId
     */
    public function __construct(
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly ?string $privateKeyId = null
    ) {
    }

    /**
     * Service account email, the assertion issuer.
     */
    public function getClientEmail(): string
    {
        return $this->clientEmail;
    }

    /**
     * PEM-encoded RSA private key.
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * Key id for the JWS `kid` header, or null when absent.
     */
    public function getPrivateKeyId(): ?string
    {
        return $this->privateKeyId;
    }
}

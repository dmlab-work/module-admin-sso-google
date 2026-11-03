<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Typed reader over the Google-specific admin configuration.
 *
 * Holds the required allowed Workspace domain, enforced against the `hd` claim
 * on callback ({@see \DmLab\AdminSsoGoogle\Plugin\EnforceHostedDomain}), and
 * the optional Directory API group lookup settings. It lives under the
 * admin-sso section (group `google`). The OIDC client id/secret are
 * shared across providers and read from admin-sso's own `general` config, not
 * re-declared here.
 */
class Config
{
    /** Required Workspace domain restricting who may sign in (the `hd` claim). */
    public const XML_PATH_ALLOWED_DOMAIN = 'dmlab_admin_sso/google/allowed_domain';

    /** Workspace group lookup via the Directory API at sign-in (off by default). */
    public const XML_PATH_DIRECTORY_GROUPS_ENABLED = 'dmlab_admin_sso/google/directory_groups_enabled';

    /** Service account JSON key, stored encrypted. */
    public const XML_PATH_SERVICE_ACCOUNT_KEY = 'dmlab_admin_sso/google/service_account_key';

    /** Workspace admin the service account impersonates for the Directory API. */
    public const XML_PATH_DIRECTORY_ADMIN_SUBJECT = 'dmlab_admin_sso/google/directory_admin_subject';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Configured allowed Workspace domain, or null when unset.
     *
     * Null means Google sign-in is misconfigured and every login is refused.
     * Trimmed; a blank value reads as null.
     */
    public function getAllowedDomain(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_DOMAIN);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Whether Workspace groups are read from the Directory API at sign-in.
     */
    public function isDirectoryGroupsEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DIRECTORY_GROUPS_ENABLED);
    }

    /**
     * Decrypted service account JSON key, or null when unset.
     */
    public function getServiceAccountKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_SERVICE_ACCOUNT_KEY);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim((string)$this->encryptor->decrypt($value));

        return $value === '' ? null : $value;
    }

    /**
     * Workspace admin email to impersonate, or null when unset.
     */
    public function getDirectoryAdminSubject(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_DIRECTORY_ADMIN_SUBJECT);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}

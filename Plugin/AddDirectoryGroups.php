<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Plugin;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\Directory\DirectoryGroupsIdentity;
use DmLab\AdminSsoGoogle\Model\Directory\GroupResolver;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Adds the user's Workspace groups (Directory API) to a Google identity when group mapping is on.
 *
 * Fails closed: a failed lookup refuses the login, since a default-role fallback would silently change roles.
 */
class AddDirectoryGroups
{
    /**
     * @param Config $config
     * @param GroupResolver $groupResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly GroupResolver $groupResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Wrap the Google identity with its Directory groups.
     *
     * @param IdentityFactory $subject
     * @param IdentityInterface $result
     * @param array<string,mixed> $claims
     * @param ProviderPresetInterface $preset
     * @return IdentityInterface
     * @throws LocalizedException when the groups cannot be read
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCreate(
        IdentityFactory $subject,
        IdentityInterface $result,
        array $claims,
        ProviderPresetInterface $preset
    ): IdentityInterface {
        if (!$preset instanceof GooglePreset || !$this->config->isDirectoryGroupsEnabled()) {
            return $result;
        }

        $email = trim((string)$result->getEmail());
        if ($email === '') {
            $this->refuse('(no email)', 'The identity has no email.');
        }

        try {
            $groups = $this->groupResolver->resolve($email);
        } catch (DirectoryException $e) {
            $this->refuse($email, $e->getMessage());
        }

        return new DirectoryGroupsIdentity($result, $groups);
    }

    /**
     * Log the operator detail and refuse the login with a generic message.
     *
     * @param string $email
     * @param string $reason
     * @return never
     * @throws LocalizedException
     */
    private function refuse(string $email, string $reason): never
    {
        $this->logger->error(sprintf('DmLab_AdminSsoGoogle: Google group lookup failed for %s: %s', $email, $reason));

        throw new LocalizedException(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            __('Could not read your Google Workspace groups to determine your admin role. Contact your administrator.')
        );
    }
}

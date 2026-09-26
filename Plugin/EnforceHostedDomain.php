<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Plugin;

use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Enforces the optional Workspace-domain restriction on a Google admin login.
 *
 * Google emits an `hd` (hosted-domain) claim for Workspace accounts. When an
 * allowed domain is configured, this before-plugin rejects any login whose `hd`
 * does not match — including personal Google accounts, which carry no `hd` at
 * all. It runs at identity creation (the point where validated claims are still
 * available) and only for the Google preset, so other providers are untouched.
 *
 * Implemented as an interceptor rather than a core change: `sso-core` and
 * `admin-sso` stay provider-agnostic; the Google-specific policy lives entirely
 * in this plugin (the open/closed seam).
 */
class EnforceHostedDomain
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Reject the login before an identity is built on a hosted-domain mismatch.
     *
     * When a domain is configured, the account's `hd` claim must match it.
     * Only Google logins are checked; the plugin fires on every provider (the
     * factory is shared) but short-circuits unless the active preset is Google.
     * The comparison is case-insensitive, as DNS domains are.
     *
     * @param IdentityFactory $subject
     * @param array<string,mixed> $claims validated ID-token claims
     * @param ProviderPresetInterface $preset active provider preset
     * @return array{0: array<string,mixed>, 1: ProviderPresetInterface}
     * @throws LocalizedException when a domain is configured and the `hd` claim
     *         is missing or does not match it
     */
    public function beforeCreate(
        IdentityFactory $subject,
        array $claims,
        ProviderPresetInterface $preset
    ): array {
        if (!$preset instanceof GooglePreset) {
            return [$claims, $preset];
        }

        $allowedDomain = $this->config->getAllowedDomain();
        if ($allowedDomain === null) {
            return [$claims, $preset];
        }

        $hostedDomain = isset($claims['hd']) && is_scalar($claims['hd'])
            ? trim((string)$claims['hd'])
            : '';

        if ($hostedDomain === '' || strcasecmp($hostedDomain, $allowedDomain) !== 0) {
            throw new LocalizedException(
                __('Your Google account is not permitted to sign in to this admin panel.')
            );
        }

        return [$claims, $preset];
    }
}

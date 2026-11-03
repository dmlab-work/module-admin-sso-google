<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model;

use DmLab\SsoCore\Api\AuthorizationParamsProviderInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * Google (Google Identity / Workspace) OIDC preset for the admin-sso capability.
 *
 * Google is standard OIDC with a single, tenant-independent discovery document,
 * so this preset is intentionally small: a fixed discovery URL, the default
 * scopes, and identity/branding metadata. All protocol work lives in `sso-core`,
 * all admin logic in `admin-sso`; a plugin registers this preset into
 * `admin-sso`'s PresetRegistry (the open/closed seam).
 *
 * Google does not emit Workspace group memberships in the OIDC ID token, so the
 * groups claim is null; groups are optionally read from the Directory API by
 * Plugin/AddDirectoryGroups. Who may sign in is restricted via the `hd`
 * hosted-domain claim (see Plugin/EnforceHostedDomain).
 */
class GooglePreset implements ProviderPresetInterface, AuthorizationParamsProviderInterface
{
    /** Stable machine code identifying Google across the suite. */
    private const CODE = 'google';

    /** Human-readable IdP name for the admin UI. */
    private const LABEL = 'Google Workspace';

    /**
     * Fixed OIDC discovery document — Google exposes a single tenant-independent
     * endpoint, so no per-install config feeds into it.
     */
    private const DISCOVERY_URL = 'https://accounts.google.com/.well-known/openid-configuration';

    /** Login-button label shown on the admin login page. */
    private const BUTTON_LABEL = 'Sign in with Google';

    /** Module-relative asset id of the login-button logo. */
    private const ICON_ASSET = 'DmLab_AdminSsoGoogle::images/google.svg';

    /**
     * @param AssetRepository $assetRepository
     * @param Config $config
     */
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return self::LABEL;
    }

    /**
     * Build the OIDC discovery URL for the given config.
     *
     * Google's discovery URL is fixed and tenant-independent, so the passed
     * config is ignored and the constant endpoint is always returned.
     *
     * @param array<string,mixed> $config
     */
    public function buildDiscoveryUrl(array $config): string
    {
        return self::DISCOVERY_URL;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultScopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    /**
     * @inheritDoc
     *
     * Null: Google does not emit group memberships in the OIDC ID token.
     */
    public function getGroupsClaim(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getButtonLabel(): string
    {
        return self::BUTTON_LABEL;
    }

    /**
     * @inheritDoc
     *
     * Resolves the shipped Google logo through the asset repository so the URL is
     * theme/area-correct on the admin login page.
     */
    public function getButtonIconUrl(): ?string
    {
        return $this->assetRepository->getUrl(self::ICON_ASSET);
    }

    /**
     * Send the allowed Workspace domain as the `hd` authorization param.
     *
     * `hd` in the request only narrows Google's account chooser and is user-editable;
     * {@see \DmLab\AdminSsoGoogle\Plugin\EnforceHostedDomain} stays the authority. The
     * passed config carries only generic fields, so the domain comes from this module's Config.
     *
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    public function getAuthorizationParams(array $config): array
    {
        $domain = $this->config->getAllowedDomain();

        return $domain === null ? [] : ['hd' => $domain];
    }
}

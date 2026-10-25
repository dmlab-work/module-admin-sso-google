# DmLab_AdminSsoGoogle

> Google Workspace login for the Magento 2 admin panel.

[![CI](https://github.com/dmlab-work/module-admin-sso-google/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-admin-sso-google/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-admin-sso-google/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-admin-sso-google) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-admin-sso-google)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-admin-sso-google)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-admin-sso-google/php)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![License](https://img.shields.io/packagist/l/dmlab/module-admin-sso-google)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

A thin Google provider plugin for the provider-agnostic `admin-sso` capability. It supplies the Google OIDC preset — a fixed discovery URL, default scopes, the required hosted-domain restriction, the `hd` account-chooser hint, login-button branding — while all OIDC protocol lives in `sso-core` and all admin logic in `admin-sso`.

## Installation

```bash
composer require dmlab/module-admin-sso-google
bin/magento module:enable DmLab_SsoCore DmLab_AdminSso DmLab_AdminSsoGoogle
bin/magento setup:upgrade
```

Requires `dmlab/module-admin-sso` and `dmlab/module-sso-core` (a direct dependency); Composer pulls both.
See [CHANGELOG.md](CHANGELOG.md) for release notes — 0.2.0 has breaking changes.

## Create the Google OAuth 2.0 client

In the [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services → Credentials**:

1. **Create credentials → OAuth client ID**, application type **Web application**.
2. **Authorized redirect URI** — the `admin-sso` callback:
   `https://<admin-host>/<admin-path>/adminsso/sso/callback`
   (`<admin-path>` is the backend frontName, default `admin`). It must match the
   admin URL used at runtime exactly.
3. Create, then copy the **Client ID** and **Client secret**.

No discovery/tenant configuration is needed — Google exposes a single, tenant-independent
OIDC discovery document (`https://accounts.google.com/.well-known/openid-configuration`).

## Configuration

Admin → Stores → Configuration → **DMLab → Admin SSO**.

**General** (`dmlab_admin_sso/general/*`):

| Field | Value |
|---|---|
| Enable Admin SSO | Yes |
| Identity Provider | Google Workspace |
| Client ID | from the Google OAuth client |
| Client Secret | from the Google OAuth client |

**Google Workspace** (`dmlab_admin_sso/google/*`, shown when Google is selected):

| Field | Value |
|---|---|
| Allowed Workspace Domain | **required**; the one Workspace domain allowed to sign in — a bare domain such as `example.com` (no scheme, `@` or path), validated and lowercased on save |

Sign-in is enforced against Google's `hd` (hosted-domain) claim: accounts outside the
domain — including personal Google accounts, which carry no `hd` — are rejected on callback.
While Google is the active provider the domain cannot be saved empty, and if it is empty
anyway every Google login is refused. Logins also require `email_verified=true`.

The domain is sent as the `hd` authorization param, so Google's account chooser offers only
that domain. It is a hint the user can edit — the `hd` claim check stays the authority.

Enforce-SSO/break-glass are configured in `admin-sso`; see that module's README.

## No groups in the Google token — role mapping limitation

Google Workspace **does not include group membership in the OIDC ID token**. There is no
`groups` claim over standard OIDC, so `admin-sso`'s group → ACL-role mapping cannot resolve
from a Google login: a provisioned admin falls back to the **default role** configured in
`admin-sso`. The practical Google control is *who may sign in* (the Allowed Workspace Domain
above), not *what role they get*.

Fetching group memberships would require the Google Admin SDK **Directory API** (a service
account with domain-wide delegation) — out of scope for v1.

## Requirements

- Magento **2.4.x** (tested on 2.4.9)
- PHP **8.3 – 8.5**

## Part of the DMLab identity suite

| Repo | Role |
|------|------|
| `sso-core` | Shared OIDC engine (direct dependency, installed automatically) |
| `admin-sso` · `admin-sso-<idp>` | Admin-panel SSO login |
| `customer-sso` · `customer-sso-<idp>` | Storefront SSO login |
| `admin-scim` · `admin-scim-<idp>` | Admin-user provisioning (SCIM 2.0) |

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.

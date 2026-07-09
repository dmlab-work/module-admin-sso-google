# MageDevGroup_AdminSsoGoogle

> Google Workspace login for the Magento 2 admin panel.

![License](https://img.shields.io/badge/license-OSL--3.0-green) ![Magento](https://img.shields.io/badge/Magento-2.4-orange) ![PHP](https://img.shields.io/badge/PHP-8.3--8.5-blue) ![Version](https://img.shields.io/badge/version-0.0.1-lightgrey)

A thin Google provider plugin for the provider-agnostic `admin-sso` capability. It supplies the Google OIDC preset — a fixed discovery URL, default scopes, optional hosted-domain restriction, login-button branding — while all OIDC protocol lives in `sso-core` and all admin logic in `admin-sso`. Installing it pulls `admin-sso` and `sso-core` automatically.

## Installation

```bash
composer require magedevgroup/module-admin-sso-google
bin/magento module:enable MageDevGroup_SsoCore MageDevGroup_AdminSso MageDevGroup_AdminSsoGoogle
bin/magento setup:upgrade
```

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

Admin → Stores → Configuration → **MageDevGroup → Admin SSO**.

**General** (`magedevgroup_admin_sso/general/*`):

| Field | Value |
|---|---|
| Enable Admin SSO | Yes |
| Identity Provider | Google Workspace |
| Client ID | from the Google OAuth client |
| Client Secret | from the Google OAuth client |

**Google Workspace** (`magedevgroup_admin_sso/google/*`, shown when Google is selected):

| Field | Value |
|---|---|
| Allowed Workspace Domain | optional; restrict sign-in to one Workspace domain, e.g. `example.com` |

When **Allowed Workspace Domain** is set, sign-in is enforced against Google's `hd`
(hosted-domain) claim: accounts outside the domain — including personal Google accounts,
which carry no `hd` — are rejected on callback. Leave it empty to allow any Google account.

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

- Magento **2.4.x**
- PHP **8.3 – 8.5**

## Part of the MageDevGroup identity suite

| Repo | Role |
|------|------|
| `sso-core` | Shared OIDC engine (installed automatically) |
| `admin-sso` · `admin-sso-<idp>` | Admin-panel SSO login |
| `customer-sso` · `customer-sso-<idp>` | Storefront SSO login |
| `admin-scim` · `admin-scim-<idp>` | Admin-user provisioning (SCIM 2.0) |

## License

[OSL-3.0](LICENSE) © MageDevGroup. Commercial licensing and support: <https://magedevgroup.com>.

# DmLab_AdminSsoGoogle

> Google Workspace login for the Magento 2 admin panel.

[![CI](https://github.com/dmlab-work/module-admin-sso-google/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-admin-sso-google/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-admin-sso-google/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-admin-sso-google) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-admin-sso-google)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-admin-sso-google)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-admin-sso-google/php)](https://packagist.org/packages/dmlab/module-admin-sso-google) [![License](https://img.shields.io/packagist/l/dmlab/module-admin-sso-google)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

Your team signs in to the Magento admin with their company Google account — a "Sign in with Google" button on the
admin login page. Only your company's Google Workspace domain gets in; personal Gmail accounts are refused.
Free, no user cap. Installs Admin SSO with it.

![Google Workspace settings for Magento admin SSO: allowed workspace domain](.github/images/config.png)

## Install

```bash
composer require dmlab/module-admin-sso-google  # also installs Admin SSO and the SSO engine
bin/magento module:enable DmLab_SsoCore DmLab_AdminSso DmLab_AdminSsoGoogle
bin/magento setup:upgrade
```

## Requirements

- Magento 2.4.7+ · PHP 8.2–8.5
- A Google Workspace account with admin access to create an OAuth client in Google Cloud Console
- An HTTPS admin URL for the redirect URI
- Outbound HTTPS to `oauth2.googleapis.com` and `admin.googleapis.com` when group mapping is on

## Create the Google OAuth client

In the [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services → Credentials**:

1. **Create credentials → OAuth client ID**, application type **Web application**.
2. **Authorized redirect URI**: `https://<admin-host>/<admin-path>/adminsso/sso/callback` (`<admin-path>` is the
   backend frontName, default `admin`). It must exactly match the admin URL used at runtime.
3. Create, then copy the **Client ID** and **Client secret**.

## Configuration

Admin → Stores → Configuration → **DMLab → Admin SSO**.

| Field | Path | Value |
|---|---|---|
| Enable Admin SSO | `general/enabled` | Yes |
| Identity Provider | `general/active_provider` | Google Workspace |
| Client ID / Client Secret | `general/client_id`, `general/client_secret` | from the Google OAuth client |
| Allowed Workspace Domain | `google/allowed_domain` | **required**; bare domain such as `example.com` (no scheme, `@` or path), validated and lowercased on save |

Paths are under `dmlab_admin_sso/`. The Google group is shown only when Google is selected. Default role and
enforce-SSO/break-glass are set in [admin-sso](https://github.com/dmlab-work/module-admin-sso).

## Map Google groups to admin roles (optional)

Google's sign-in token carries no groups. With this option on, the module reads the user's Workspace groups from
the Directory API at every login, through a service account with domain-wide delegation.

1. Google Cloud Console, same project: **APIs & Services → Library → Admin SDK API → Enable**.
2. **IAM & Admin → Service accounts → Create** (no project roles), then **Keys → Add key → JSON**. The org policy
   `iam.disableServiceAccountKeyCreation` must allow key creation.
3. Copy the service account's numeric **Client ID** (Details tab).
4. [Workspace Admin console](https://admin.google.com/) → **Security → Access and data control → API controls →
   Manage Domain Wide Delegation → Add new**: that Client ID, scope
   `https://www.googleapis.com/auth/admin.directory.group.readonly`.
5. Pick the user to impersonate: a Workspace user with the **Groups Reader** admin role (a dedicated user is
   recommended).

| Field | Path | Value |
|---|---|---|
| Map Google Groups to Roles | `google/directory_groups_enabled` | Yes (default No) |
| Service Account Key (JSON) | `google/service_account_key` | the whole JSON key file; validated, stored encrypted |
| Admin to Impersonate | `google/directory_admin_subject` | email of the Groups Reader user from step 5 |

Map group emails in admin-sso's **Group to Role Map**, lowercase, one per line:

```
engineering@example.com=3
```

- Direct memberships only; nested groups are not expanded.
- A failed lookup (missing or bad key or admin, revoked delegation, 403, timeout, over 5,000 direct groups) refuses
  the login. Use break-glass during a Google outage.
- No caching: one token request plus one Directory call per 200 groups at every login.

## What it does

- Restricts sign-in to the allowed Workspace domain, checked against Google's hosted-domain claim on every login.
- Refuses personal Google accounts (they carry no Workspace domain).
- Requires a verified email (`email_verified=true`).
- Limits Google's account chooser to the allowed domain.
- Fails closed: while Google is active the domain cannot be saved empty, and if it is empty anyway every Google
  login is refused.
- Optional: maps Google Workspace groups to admin roles (Directory API, off by default). While off, Google's
  sign-in token carries no groups and every Google admin gets the default role.
- Fails closed on group lookup: if the Directory API call fails, the login is refused instead of falling back to
  the default role.

Pairs with [Admin SSO](https://github.com/dmlab-work/module-admin-sso) · [Admin SSO Okta](https://github.com/dmlab-work/module-admin-sso-okta) · [Admin SCIM](https://github.com/dmlab-work/module-admin-scim)

## Architecture

This module is the Google provider plugin for `admin-sso`. `Model/GooglePreset.php` supplies the fixed discovery
URL (`https://accounts.google.com/.well-known/openid-configuration`), scopes `openid profile email`, a null groups
claim and the login-button label and logo; it also sends the allowed domain as the `hd` authorization param. That
param is only a hint the user can edit: `Plugin/EnforceHostedDomain.php`, a before-plugin on sso-core's
`IdentityFactory::create`, is the authority for the `hd` claim and `email_verified`. `etc/di.xml` registers the
preset into admin-sso's `PresetRegistry`. Group mapping is an after-plugin on the same method,
`Plugin/AddDirectoryGroups.php`: it signs an RS256 JWT with the service-account key, exchanges it at Google's token
endpoint (JWT bearer flow, `web-token/jwt-framework`, no `google/apiclient`) and wraps the identity so
`getGroups()` returns the Directory groups (`Model/Directory/*`). The OIDC protocol lives in `sso-core`; admin users, roles and the login
flow live in `admin-sso`.

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.

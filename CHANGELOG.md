# Changelog

## 0.2.1

- Requires Magento 2.4.7+ and PHP 8.2–8.5: `magento/framework` `^103.0.7`, `magento/module-config` `^101.2.7`.
- CI runs PHP 8.2–8.5 and no longer notifies Packagist on install.
- composer.json: keywords, homepage, support links.
- README: plain intro, screenshot, install and Google setup first; plugin detail under Architecture.

## 0.2.0

- **Breaking:** Google sign-in fails closed — an empty Allowed Workspace Domain refuses every Google login
  instead of allowing any Google account. Set a domain before upgrading.
- **Breaking:** Allowed Workspace Domain is required and validated (bare hostname, normalized to lowercase)
  on save while Google is the active provider.
- **Breaking:** Google logins require `email_verified=true`.
- The authorization request carries the `hd` param, pre-filtering Google's account chooser.
- **Breaking:** requires `dmlab/module-admin-sso` ^0.2.0 (passes the `hd` param).
- `dmlab/module-sso-core` and `magento/module-config` declared as direct dependencies.

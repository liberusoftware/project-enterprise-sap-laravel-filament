# Security Policy — Liberu Enterprise SAP

## Reporting a Vulnerability

Private vulnerability reporting is enabled for [liberusoftware/project-enterprise-sap-laravel-filament](https://github.com/liberusoftware/project-enterprise-sap-laravel-filament).

[**Report a vulnerability privately**](https://github.com/liberusoftware/project-enterprise-sap-laravel-filament/security/advisories/new).

Sign in to GitHub and use the link above, or open **Security → Advisories → Report a vulnerability** in this repository. Reports are handled privately through GitHub Security Advisories; maintainers can invite collaborators when needed to investigate and prepare a fix.

**Do not disclose suspected vulnerabilities in public issues, pull requests, or discussions.**

Include the affected release or commit, package versions, relevant deployment configuration, reproduction steps or a minimal proof of concept, and the security impact. Redact credentials, tokens, personal data, and other sensitive information. Test only systems you own or have permission to assess.

## What to Expect

Liberu Enterprise SAP is maintained by volunteers. While we will do our best to respond promptly, there is no guaranteed response time or SLA for security reports.

## Scope

The following are in scope for security reports:

- `app/` — application code
- `routes/` — route definitions
- `database/` — migrations and seeders
- `config/` — configuration files
- Other first-party code in this repository

First-party modules shipped with this application, including code under `modules/`, are also in scope.

## Out of Scope

The following are out of scope:

- Vulnerabilities confined to third-party dependencies should be reported upstream. First-party integration flaws and application-specific impact remain in scope here.
- Vulnerabilities requiring `APP_DEBUG=true` in production
- Social engineering or physical attacks
- Denial-of-service attacks against infrastructure

## Supported Versions

Security fixes target the default branch (`main`) and the latest release, when a release is available. Older versions may require an upgrade; backports are not guaranteed. Include the affected version or commit in your report even if you are not running the latest release.

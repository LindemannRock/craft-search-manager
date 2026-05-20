# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Search Manager, please report it privately. **Do not open a public GitHub issue** — public disclosure before a fix is available puts other users at risk.

### Preferred: GitHub private advisory

[Open a private security advisory](https://github.com/LindemannRock/craft-search-manager/security/advisories/new). This routes the report directly to maintainers with no public visibility.

### Email fallback

If you can't use GitHub's private reporting, email **security@lindemannrock.com** with:

- A description of the vulnerability
- Steps to reproduce
- Affected version(s)
- Potential impact

We aim to acknowledge reports within **48 hours** and provide a status update within **5 business days**.

## Supported Versions

Security fixes are issued for the current major release. Please keep the plugin up to date.

| Version | Supported |
| ------- | --------- |
| 5.x     | ✅        |
| < 5.0   | ❌        |

## Scope

**In scope:**

- Authentication and authorization bypasses
- SQL injection, XSS, CSRF, path traversal, RCE
- Sensitive data exposure or privilege escalation
- Cryptographic weaknesses in plugin code
- Search query injection (Craft FTS, Meilisearch, Algolia)
- XSS in search result rendering or widget output
- Search index poisoning

**Out of scope:**

- Vulnerabilities in Craft CMS core — report to [Craft CMS](https://craftcms.com/security)
- Vulnerabilities in third-party dependencies — report upstream
- Issues requiring physical access, stolen credentials, or social engineering
- Theoretical vulnerabilities without a demonstrable impact
- Findings from automated scanners without manual verification

## Disclosure

After a fix is released, we publish a security advisory crediting the reporter (unless they prefer to remain anonymous). We follow a coordinated disclosure model — please give us reasonable time to patch before publishing details publicly.

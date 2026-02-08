# Security Policy

## Supported Versions

The following versions of CP Static Deploy are currently being maintained with security updates:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

Security is a top priority for CP Static Deploy. The plugin handles sensitive operations including:

- GitHub API tokens (encrypted using AES-256-CBC)
- Git repository access
- Server-side file operations
- Automated deployment workflows

### How to Report

If the vulnerability is discovered in a ClassicPress or WordPress core function, plugin authors are encouraged to report the issue to the [ClassicPress Security Team](https://www.classicpress.net/security/) or [WordPress Security Team](https://wordpress.org/support/articles/reporting-security-vulnerabilities/).

If the vulnerability is specific to CP Static Deploy:

1. **DO NOT** open a public GitHub issue for security vulnerabilities
2. Report via [GitHub Security Advisories](https://github.com/seriocomic/cp-static-deploy/security/advisories/new)
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if available)

### What to Expect

- **Initial Response**: Within 48 hours
- **Status Updates**: At least once per week
- **Resolution Timeline**: Varies based on severity
  - Critical: Patch within 7 days
  - High: Patch within 14 days
  - Medium: Patch within 30 days
  - Low: Addressed in next scheduled release

### Disclosure Policy

- Vulnerabilities will be patched before public disclosure
- Security advisories will be published after fixes are released
- Credit will be given to reporters (unless anonymity is requested)

## Security Features

CP Static Deploy implements several security measures:

- AES-256-CBC encryption for GitHub tokens
- Sudoers configuration for privilege separation
- File permission checks during activation
- Input validation and sanitization
- WordPress nonce verification for AJAX requests

# Security Policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use GitHub's private vulnerability reporting: on the repository's **Security** tab →
**Report a vulnerability**. That opens a private advisory visible only to you and the
maintainers.

Please include:

- the affected version / commit,
- a description of the problem and its impact,
- steps to reproduce, or a proof of concept.

Describe the *class* of problem rather than publishing a working exploit or a
step-by-step data-extraction path.

## Scope

Portico stores sensitive member data (dates of birth, ban/watchlist reasons, notes). It
is designed for **LAN / private-VLAN deployment only** and must never be exposed to the
public internet — see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md). Reports that depend on
the app being internet-facing will be treated as deployment misconfiguration, not
product vulnerabilities, though we're still glad to hear about them.

Of particular interest:

- server-side authorization gaps (a role reaching a capability above it via a forged
  request, not just a hidden UI element),
- anything that lets a lower role read Manager+ sensitive fields,
- SQL injection, stored XSS in the admin panel, auth bypass.

## Supported versions

This is a single-branch project. Fixes land on `main`; run a recent commit.

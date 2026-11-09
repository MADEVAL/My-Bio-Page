# Security Policy

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email
`leonidovev@gmail.com` with:

- the affected endpoint or file;
- reproduction steps or a proof of concept;
- the expected and actual behaviour;
- the impact you believe it has.

You will get an acknowledgement as soon as possible. Please allow time for a
fix before publishing details.

## Scope

The deployed surface is small: `public/` is the document root, and the private
application lives in `app/`. In scope:

- the MCP endpoint at `/mcp.php` and its `/mcp` alias;
- the public page, sitemap, robots.txt and static assets;
- token provisioning and the content/backup/audit write path;
- the Apache and nginx deployment examples in `README.md`.

Out of scope:

- issues that require write access to the server or the repository;
- denial of service caused by a deliberately compromised token;
- third-party services linked from the page;
- the frozen snapshot in `.legacy/`, which is not deployed or maintained.

## Baseline

- `MCP_TOKEN` in the PHP-FPM pool, `BIO_TOKEN_FILE`, or `app/bio.token`.
- `public/` must be the only document root; `app/` must not be web-readable.
- `expose_php=Off`, `display_errors=Off`, `log_errors=On`.
- HTTPS with the HSTS header; the app does not redirect HTTP by itself.
- PHP-FPM needs write access only to `app/content/` and `app/cache/`.

See the security model in [README.md](README.md) for the full list of controls.

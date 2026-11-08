# bio.leonidov.dev

> *"Success in Persistence"* - Yevhen Leonidov

A personal link-in-bio page. No framework, no `node_modules` eating your SSD, no build step. PHP 8.5 renders the page, nginx serves it, and one JSON file is the only source of truth for both languages, the meta tags, the JSON-LD and the sitemap.

Since 2026 the page is content-driven: **MCP** (Model Context Protocol) tools can add, reorder and hide links without touching a template.

---

## Who's this guy?

**Yevhen Leonidov** - Software Architect & Engineer. I build scalable systems, lead dev teams, run an outsource studio, and apparently also make my own link-in-bio instead of just using Linktree like a normal person.

- 💼 Software development (outsource studio) → [GLOBUS.studio](https://globus.studio)
- 🛡️ Main project → [BotBlocker](https://botblocker.top) - WordPress Security Plugin

---

## How it works

```
request ──► nginx ──► index.php ──► App ──► Cache ──► Renderer ──► HTML
                        │                            ▲
                        └──► Content (content/site.json)
```

- **One content file.** `content/site.json` holds both languages, all links, all metadata. Nothing is duplicated between EN and RU.
- **Rendered once, then cached.** The cache key is `revision + template mtime + css/js mtime`, so a deploy or an edit invalidates itself. No stale pages, no manual cache busting.
- **Writes are safe.** Every mutation validates the whole tree first, copies a backup, writes atomically under `flock`, and appends to an audit log.
- **MCP writes through the same path.** No second code path, no "admin mode".

---

## Structure

```
app/
├── index.php              # front controller (/, /ru.html, /sitemap.xml)
├── mcp.php                # MCP endpoint (POST /mcp.php)
├── src/
│   ├── App.php            # routing, headers, ETag, 304, env
│   ├── Content.php        # schema validation, atomic writes, backups, audit
│   ├── Renderer.php       # PHP → HTML, escaped
│   ├── Seo.php            # meta, JSON-LD, sitemap from content
│   ├── Cache.php          # rendered HTML + revision key
│   ├── Mcp.php            # JSON-RPC, 12 tools
│   ├── Icons.php          # inline SVG, no icon library
│   └── ...
├── templates/page.php     # the only template
├── content/site.seed.json # initial content (deployed, never overwritten)
├── content/site.json      # live content (created on first write, gitignored)
├── assets/                # photo, logos, OG image, favicon
├── styles.css             # design tokens, dark/light
└── script.js              # theme, clipboard, shortcuts
tests/                     # 148 unit, 14 HTTP, 74 Playwright checks
bio.token                  # MCP token, lives next to app/, never inside it
```

---

## Local run

```bash
php -S 127.0.0.1:8123 -t app tests/router.php
```

`tests/router.php` mirrors a typical nginx setup (pretty URLs plus the deny list) so the dev server behaves like production.

---

## Deploy

PHP-FPM only. Apache is not supported. **There is no nginx config in this repository on purpose** - the existing vhost is used as is.

Document root: `app/`.

Two things the current vhost has to provide:

1. `index index.php;` - otherwise `/` does not reach the front controller.
2. Executing `mcp.php` - the standard `location ~ \.php$` block is enough.

`/ru.html` and `/mcp` are pretty URLs: they exist only if the vhost rewrites them. Every entry point below works without touching a single nginx line:

| URL | Result |
|---|---|
| `/` | EN |
| `/?lang=ru` | RU |
| `/index.php?lang=ru` | RU |
| `/ru.html` | RU, only with `try_files $uri $uri/ /index.php` |
| `/mcp.php` | MCP endpoint |
| `/mcp` | alias, only with an explicit `location` |

Verify after the first deploy:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://bio.leonidov.dev/          # 200
curl -s https://bio.leonidov.dev/index.php?lang=ru | grep -c 'lang="ru"'    # 1
curl -s -o /dev/null -w '%{http_code}\n' https://bio.leonidov.dev/mcp.php   # 405
```

### Permissions

PHP-FPM needs write access to the directory that holds the token (the parent of `app/`) plus `app/content/` and `app/cache/`:

```bash
chown www-data:www-data /var/www/bio
chown -R www-data:www-data app/content app/cache
chmod -R 775 app/content app/cache
```

Pushing to `main` rsyncs the app. Live content, the cache and the token never exist in the repository, so a deploy cannot overwrite them.

The token itself is provisioned by the application - see [Token](#token).

---

## MCP

Endpoint: `POST /mcp.php` (or `/mcp` if the vhost aliases it), `Authorization: Bearer <MCP_TOKEN>`, JSON-RPC 2.0 over Streamable HTTP (JSON responses, POST only, no SSE stream).

```bash
curl -s https://bio.leonidov.dev/mcp.php \
  -H "Authorization: Bearer $MCP_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'
```

| Tool | What it does |
|---|---|
| `get_content` | whole content tree + revision |
| `get_schema` | JSON Schema of the tree |
| `list_sections` | sections, labels, layouts, counts |
| `list_links` | links filtered by section / visibility / language |
| `create_link` | add a link at an optional position |
| `update_link` | patch title, subtitle, href, type, icon, visibility |
| `move_link` | move between sections or positions |
| `set_visibility` | hide without deleting |
| `delete_link` | soft delete (hide) or `hard: true` |
| `update_settings` | email, themeDefault, footerStart, ga4 |
| `preview` | render either language without touching the cache |
| `publish` | drop the HTML cache |

Every mutation accepts `rev`. Pass a stale one and you get a conflict instead of a lost update.

### Token

The application creates the token itself on the first request. No shell, no setup command: open the site once, and the file appears one level above the document root, where a plain web server cannot serve it.

```
/var/www/bio/bio.token      # 64 hex characters, mode 0600
```

Read it once through your hosting panel or SFTP and put it into the client environment. Delete the file to rotate - the next request provisions a new one.

Resolution order, first match wins:

| Source | Notes |
|---|---|
| `MCP_TOKEN` in the PHP-FPM pool environment | nothing on disk |
| `BIO_TOKEN_FILE` | explicit path outside the document root |
| `app/.env` with `MCP_TOKEN=...` | legacy, only safe if the vhost denies dotfiles |
| `bio.token` next to `app/` | generated on first use |

If PHP-FPM cannot create the file, MCP answers `401` and the site keeps working.

### Connect a client

Every client needs the same two values:

```
URL:           https://bio.leonidov.dev/mcp.php
Authorization: Bearer <64 hex characters from bio.token>
```

Keep the token in an environment variable and reference it from the config, so it never lands in a file you might commit. On Windows:

```powershell
setx BIO_TOKEN "<paste the 64 hex characters from bio.token>"
```

New shells pick it up. `setx` stores it for the user, not for the current window.

#### OpenCode V2

Project or global `opencode.jsonc`. `oauth: false` is required: OpenCode tries OAuth first for remote servers, and this endpoint is header-only.

```jsonc
{
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    "servers": {
      "bio": {
        "type": "remote",
        "url": "https://bio.leonidov.dev/mcp.php",
        "oauth": false,
        "headers": {
          "Authorization": "Bearer {env:BIO_TOKEN}"
        }
      }
    }
  }
}
```

```sh
opencode mcp list
```

Tools appear as `bio_<tool>`, or grouped under `tools.bio.*` in Code Mode.

> `{env:BIO_TOKEN}` is the **name** of the variable, not the token. Writing the value inside the braces makes OpenCode look for an environment variable with that name and send `Bearer ` without a credential.

#### OpenClaw

`~/.openclaw/openclaw.json`. `transport` must be explicit - without it OpenClaw falls back to SSE.

```json
{
  "mcp": {
    "servers": {
      "bio": {
        "url": "https://bio.leonidov.dev/mcp.php",
        "transport": "streamable-http",
        "headers": {
          "Authorization": "Bearer ${BIO_TOKEN}"
        }
      }
    }
  }
}
```

Or through the registry:

```sh
openclaw mcp set bio '{"url":"https://bio.leonidov.dev/mcp.php","transport":"streamable-http","headers":{"Authorization":"Bearer ${BIO_TOKEN}"}}'
openclaw mcp doctor bio --probe
```

OpenClaw warns when a sensitive header holds a literal value, hence `${BIO_TOKEN}`. Its Claude Code backend receives the same servers through the Gateway MCP bridge, so there is nothing else to configure for that path.

#### Claude Code

```sh
claude mcp add --transport http bio https://bio.leonidov.dev/mcp.php \
  --header "Authorization: Bearer $BIO_TOKEN"
```

Or in `.mcp.json` at the project root. The file can be committed, the secret stays in the environment:

```json
{
  "mcpServers": {
    "bio": {
      "type": "http",
      "url": "https://bio.leonidov.dev/mcp.php",
      "headers": {
        "Authorization": "Bearer ${BIO_TOKEN}"
      }
    }
  }
}
```

`type` is not optional: an entry with a `url` and no `type` is read as a stdio server.

```sh
claude mcp get bio
```

#### If it does not connect

| Symptom | Cause |
|---|---|
| `401` | token is wrong, or the file was rotated and the old value is still in the client |
| `401` and the config shows `Bearer {env:...}` | the token was pasted inside the braces - `{env:NAME}` expects a variable name |
| `405` | the client probed with `GET`; this endpoint is POST-only |
| client starts an OAuth flow | the `Authorization` header is missing, so it fell back to OAuth - check the header and `oauth: false` in OpenCode |
| `tools/list` returns 12 tools but the agent sees none | tool filters or permissions on the client side |

### Security model

- Bearer token read from `MCP_TOKEN`, `bio.token` outside the document root, or a legacy `.env`; compared with `hash_equals`. Empty token = endpoint locked.
- Whole-tree schema validation before every write; invalid content never reaches disk.
- Atomic write (`tmp` + `rename`) under an exclusive lock.
- Backup of the previous revision before every write, last 20 kept.
- Audit log: `content/.audit.jsonl`.
- `robots.txt` disallows `/mcp` and `/mcp.php`; if the vhost can be touched, deny `/content`, `/src`, `/templates`, `/cache` and dotfiles as well.

### If something goes wrong

```bash
cp content/backups/site-20261108-221500-rev003.json content/site.json
rm -f cache/*.html cache/*.key
```

---

## Tests

```bash
php -d xdebug.mode=coverage tests/run.php   # 148 unit tests, 100% line coverage gate
php tests/http.php                          # 14 end-to-end checks over real sockets
node tests/landing.audit.js                 # 74 browser checks (Playwright + axe-core)
```

`tests/run.php` fails if **any** executable line in `app/src`, `app/index.php`, `app/mcp.php` or `templates/page.php` is not covered - measured by Xdebug, no PHPUnit, no composer.

The Playwright suite also covers WCAG 2.0-2.2 AA via axe-core, contrast in both themes, 44px touch targets, no horizontal overflow from 320 to 2560px, CLS, JSON-LD, hreflang and the clipboard fallback.

```
Unit:        148 passed, 100.00% (838/838 lines)
HTTP:        14 passed
Playwright:  74 passed
```

---

## Content model

```json
{
  "id": "telegram",
  "type": "link",
  "href": "https://t.me/leonidov_dev",
  "icon": "telegram",
  "order": 1,
  "visible": true,
  "title":    { "en": "Telegram Channel", "ru": "Телеграм-канал" },
  "subtitle": { "en": "@leonidov_dev",    "ru": "@leonidov_dev" }
}
```

Item types: `link`, `featured`, `project`, `social`, `email`. Section layouts: `cards`, `social`, `mixed`. Icons come from a fixed registry (inline SVG); a link can use `logo` instead of `icon` for a raster brand mark.

---

## Analytics

GA4 is off until you give it a measurement ID. An empty string means no tag at all - not a single request to `googletagmanager.com`.

```json
"settings": { "ga4": "G-XXXXXXXXXX" }
```

Only then does the page emit the gtag snippet in `<head>`, with a preconnect. Switch it at runtime through MCP:

```json
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"update_settings","arguments":{"ga4":"G-XXXXXXXXXX"}}}
```

and back off with `"ga4": ""`. A `UA-` property is rejected by validation instead of silently doing nothing.

One caveat: the tag is either on for every visitor or off for everyone. GA4 sets cookies, so where consent is required this alone is not enough - the page ships no banner.

---

## .legacy/

`.legacy/` is a frozen copy of the static HTML version (commit `2b1a871`), kept for reference. It is not deployed and not maintained.

---

*© 2016 – 2026 Yevhen Leonidov*

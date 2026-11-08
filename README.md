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
├── mcp.php                # POST /mcp - MCP over Streamable HTTP
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
├── script.js              # theme, clipboard, shortcuts
└── .env                   # MCP_TOKEN (gitignored)
deploy/nginx-bio.conf      # nginx + PHP-FPM server block
tests/                     # 130 unit, 12 HTTP, 74 Playwright checks
```

---

## Local run

```bash
php -S 127.0.0.1:8123 -t app tests/router.php
```

`tests/router.php` mirrors the nginx rules (including the deny list) so the dev server behaves like production.

---

## Deploy

nginx + PHP-FPM only. Apache is not supported and never will be.

```bash
cp deploy/nginx-bio.conf /etc/nginx/sites-available/bio.leonidov.dev
ln -s ../sites-available/bio.leonidov.dev /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Adjust the PHP-FPM socket at the top of the config, then create the token once on the server:

```bash
cd /var/www/bio/app
cp .env.example .env
php -r 'echo "MCP_TOKEN=", bin2hex(random_bytes(32)), PHP_EOL;' >> .env
chown -R www-data:www-data content cache
chmod -R 775 content cache
```

PHP-FPM must be able to write `content/` and `cache/`; the rest of the tree can stay read-only for the web user.

Pushing to `main` lints, runs both test suites, rsyncs and smoke-tests. `content/site.json`, `cache/` and `.env` are excluded from rsync, so a deploy never overwrites live content or secrets.

---

## MCP

Endpoint: `POST /mcp`, `Authorization: Bearer <MCP_TOKEN>`, JSON-RPC 2.0 over Streamable HTTP (JSON responses, POST only, no SSE stream).

```bash
curl -s https://bio.leonidov.dev/mcp \
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
| `update_settings` | email, themeDefault, footerStart |
| `preview` | render either language without touching the cache |
| `publish` | drop the HTML cache |

Every mutation accepts `rev`. Pass a stale one and you get a conflict instead of a lost update.

### Security model

- Bearer token in `.env`, compared with `hash_equals`. Empty token = endpoint locked.
- Whole-tree schema validation before every write; invalid content never reaches disk.
- Atomic write (`tmp` + `rename`) under an exclusive lock.
- Backup of the previous revision before every write, last 20 kept.
- Audit log: `content/.audit.jsonl`.
- `robots.txt` disallows `/mcp`; nginx denies `/content`, `/src`, `/templates`, `/cache`, `.env`.

### If something goes wrong

```bash
cp content/backups/site-20261108-221500-rev003.json content/site.json
rm -f cache/*.html cache/*.key
```

---

## Tests

```bash
php -d xdebug.mode=coverage tests/run.php   # 130 unit tests, 100% line coverage gate
php tests/http.php                          # 12 end-to-end checks over real sockets
node tests/landing.audit.js                 # 74 browser checks (Playwright + axe-core)
```

`tests/run.php` fails if **any** executable line in `app/src`, `app/index.php`, `app/mcp.php` or `templates/page.php` is not covered - measured by Xdebug, no PHPUnit, no composer.

The Playwright suite also covers WCAG 2.0-2.2 AA via axe-core, contrast in both themes, 44px touch targets, no horizontal overflow from 320 to 2560px, CLS, JSON-LD, hreflang and the clipboard fallback.

```
Unit:        130 passed, 100.00% (795/795 lines)
HTTP:        12 passed
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

## legacy/

`legacy/` is a frozen copy of the static HTML version (commit `2b1a871`), kept for reference. It is not deployed and not maintained.

---

*© 2016 – 2026 Yevhen Leonidov*

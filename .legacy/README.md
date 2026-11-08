# bio.leonidov.dev

> *"Success in Persistence"* - Yevhen Leonidov

A personal link-in-bio page. No framework, no npm install, no `node_modules` folder eating your SSD. Just plain HTML, CSS, and a sprinkle of vanilla JS - because sometimes the best stack is the one that loads in under a second.

---

## Who's this guy?

**Yevhen Leonidov** - Software Architect & Engineer. I build scalable systems, lead dev teams, run an outsource studio, and apparently also make my own link-in-bio instead of just using Linktree like a normal person.

- 💼 Software development (outsource studio) → [GLOBUS.studio](https://globus.studio)
- 🛡️ Main project → [BotBlocker](https://botblocker.top) - WordPress Security Plugin

---

## Contacts

- 🌐 Personal site & blog → [leonidov.dev](https://leonidov.dev)
- 📢 Telegram channel → [@leonidov_dev](https://t.me/leonidov_dev)
- 💼 LinkedIn → [evgeniy-leonidov](https://www.linkedin.com/in/evgeniy-leonidov/)
- 🤖 Reddit → [u/ZestycloseElection84](https://www.reddit.com/user/ZestycloseElection84/) · [r/GLOBUS_studio](https://www.reddit.com/r/GLOBUS_studio/)
- ✉️ Email → [leonidovev@gmail.com](mailto:leonidovev@gmail.com)



---

## Open Source

### [Atomic Framework](https://github.com/MADEVAL/Atomic-Framework)
Lightweight PHP framework built on top of Fat-Free Framework. Because sometimes you want F3's speed without starting from scratch every time.

### [Atomic Framework Application](https://github.com/MADEVAL/Atomic-Framework-Application)
Boilerplate / starter template for Atomic Framework. Clone, configure, ship. No magic build pipeline required.

### [Cortex-Atomic](https://github.com/GLOBUS-studio/Cortex-Atomic)
Fork of the classic `ikkez/f3-cortex` Multi-engine ORM: SQL (SQLite, MySQL, PostgreSQL, MSSQL), MongoDB, Jig - heavily refactored for PHP 8.0–8.5 and the Atomic ecosystem.

### WordPress Plugins

#### [BotBlocker Security](https://wordpress.org/plugins/botblocker-security/)
WordPress security plugin & WAF. Blocks bots, brute-force attempts, fake crawlers, and spam before WordPress even loads (MU-plugin, early-init). Multi-layer CAPTCHA, 2FA, live traffic monitoring, GeoIP, cloud threat intelligence. 2,000+ active installs, 5/5 stars.

#### [GLOBUS Debug Control](https://wordpress.org/plugins/globus-debug-control/)
Surgical `WP_DEBUG` filter. Suppress notices, deprecations, strict warnings independently - without touching `wp-config.php`. Keeps AJAX and REST responses clean, auto-rotates log files, optional MU-plugin dropin for early interception.

---

## What's in the box?

```
app/
├── index.html   # EN version
├── ru.html      # RU version (yes, I speak both)
├── styles.css   # Design tokens, dark/light themes, responsive
├── script.js    # Theme toggle, copy email, toasts, shortcuts
├── robots.txt   # Crawler rules
├── sitemap.xml  # Both pages + hreflang alternates
└── assets/      # OG image, photo, logos, inline SVG favicon
```

## Features

- **Dark / Light theme** - follows the system on first visit, remembers your choice, no flash on load
- **EN / RU** - two static pages, SEO-friendly hreflang + `x-default`
- **No build step, no framework, no icon library** - icons are inline SVG, the only external request is the Inter webfont
- **Responsive** - 44px+ touch targets, works from a flip phone to a 4K monitor
- **Accessible** - AA contrast in both themes, visible focus states, `aria-live` toasts, reduced-motion support
- **Open Graph, Twitter Card & JSON-LD** - `ProfilePage` + `Person` with `sameAs`, link previews done right
- **Copy email to clipboard** - with a fallback and a toast notification, because UX matters

---

## Testing

The audit lives outside `app/`, so it never ships and adds no runtime weight.

```bash
npx playwright install chromium
python -m http.server 8123 --directory app
npm i playwright axe-core
node tests/landing.audit.js
```

74 checks: no console/network errors, theme without flash (system preference + persistence), WCAG AA contrast in both themes, axe-core (WCAG 2.0-2.2 AA + best practices), 44px touch targets, no horizontal overflow at 320-2560px, SEO/JSON-LD/hreflang, clipboard with fallback, keyboard shortcuts, reduced motion, ripple and CLS.

---

*© 2016 – 2026 Yevhen Leonidov*

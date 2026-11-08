/**
 * Landing page audit - Playwright + axe-core
 *
 * Usage:
 *   1. npx playwright install chromium
 *   2. php -S 127.0.0.1:8123 -t public tests/router.php
 *   3. npm i playwright axe-core && node tests/landing.audit.js
 *
 * Optional env: BASE_URL (default http://127.0.0.1:8123)
 *
 * The site itself has zero dependencies - everything here lives outside app/
 * and is never deployed.
 */
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8123';
const AXE = require.resolve('axe-core/axe.min.js');

let pass = 0;
let fail = 0;
const failures = [];

function ok(name, cond, detail = '') {
    if (cond) {
        pass++;
        console.log('  PASS  ' + name);
    } else {
        fail++;
        failures.push({ name, detail });
        console.log('  FAIL  ' + name + (detail ? '   ->  ' + detail : ''));
    }
}

function section(title) {
    console.log('\n=== ' + title + ' ===');
}

async function newPage(browser, opts = {}) {
    const ctx = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        colorScheme: 'dark',
        ...opts
    });
    const page = await ctx.newPage();
    page.problems = [];
    page.consoleAll = [];
    page.on('console', (m) => {
        page.consoleAll.push(m.type() + ': ' + m.text());
        if (m.type() === 'error') page.problems.push('console: ' + m.text());
    });
    page.on('pageerror', (e) => page.problems.push('pageerror: ' + e.message));
    page.on('requestfailed', (r) => page.problems.push('requestfailed: ' + r.url() + ' :: ' + (r.failure() || {}).errorText));
    page.on('response', (r) => {
        if (r.status() >= 400) page.problems.push('http ' + r.status() + ': ' + r.url());
    });
    return { ctx, page };
}

const contrastScript = () => {
    function parse(c) {
        const m = c.match(/rgba?\(([^)]+)\)/);
        if (!m) return null;
        const p = m[1].split(',').map((n) => parseFloat(n));
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function lin(v) {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }
    function lum(c) {
        return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
    }
    function bgOf(el) {
        // Gradient surfaces: use the darkest end of the brand gradient (worst case)
        if (el.closest('.link-card.featured')) return { r: 37, g: 99, b: 235, a: 1 };
        let node = el;
        while (node && node !== document.documentElement) {
            const cs = getComputedStyle(node);
            const c = parse(cs.backgroundColor);
            if (c && c.a > 0.95) return c;
            node = node.parentElement;
        }
        return parse(getComputedStyle(document.body).backgroundColor) || { r: 10, g: 10, b: 11, a: 1 };
    }
    const selectors = [
        '.section-title', '.link-subtitle', '.link-title', '.name', '.title', '.bio',
        '.link-card.featured .link-subtitle', '.link-card.featured .link-title', '.footer p'
    ];
    const out = [];
    selectors.forEach((sel) => {
        document.querySelectorAll(sel).forEach((el) => {
            const cs = getComputedStyle(el);
            if (cs.display === 'none' || cs.visibility === 'hidden') return;
            const fg = parse(cs.color);
            const bg = bgOf(el);
            if (!fg) return;
            const L1 = lum(fg);
            const L2 = lum(bg);
            const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
            const size = parseFloat(cs.fontSize);
            const bold = parseInt(cs.fontWeight, 10) >= 700;
            const large = size >= 24 || (size >= 18.66 && bold);
            out.push({ sel, ratio: Math.round(ratio * 100) / 100, need: large ? 3 : 4.5, size, text: el.textContent.trim().slice(0, 40) });
        });
    });
    return out;
};

(async () => {
    const browser = await chromium.launch();

    // ---------------------------------------------------------------
    section('1. Загрузка, консоль, сеть');
    {
        for (const [url, label] of [[BASE + '/', 'EN'], [BASE + '/ru.html', 'RU']]) {
            const { ctx, page } = await newPage(browser);
            await page.goto(url, { waitUntil: 'load' });
            await page.waitForTimeout(400);
            ok(label + ': нет ошибок консоли/сети', page.problems.length === 0, page.problems.join(' | '));
            const noise = page.consoleAll.filter((m) => m.includes('[LinkBio]'));
            ok(label + ': нет console.log в проде', noise.length === 0, noise.join(' | '));
            await ctx.close();
        }
    }

    // ---------------------------------------------------------------
    section('2. Тема: системная настройка, мигание, переключение');
    {
        const { ctx, page } = await newPage(browser, { colorScheme: 'light' });
        await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
        const first = await page.evaluate(() => ({
            theme: document.documentElement.getAttribute('data-theme'),
            bg: getComputedStyle(document.body).backgroundColor,
            meta: document.querySelector('meta[name="theme-color"]').content
        }));
        ok('светлая ОС -> light до первой отрисовки', first.theme === 'light', JSON.stringify(first));
        ok('фон сразу светлый (нет вспышки)', first.bg === 'rgb(250, 250, 250)', first.bg);
        ok('meta theme-color соответствует теме', first.meta === '#fafafa', first.meta);

        await page.waitForTimeout(300);
        await page.click('#themeToggle');
        const afterToggle = await page.evaluate(() => ({
            theme: document.documentElement.getAttribute('data-theme'),
            stored: localStorage.getItem('linkbio-theme'),
            meta: document.querySelector('meta[name="theme-color"]').content,
            label: document.getElementById('themeToggle').getAttribute('aria-label'),
            moonVisible: getComputedStyle(document.querySelector('.icon-moon')).display
        }));
        ok('клик переключает на dark', afterToggle.theme === 'dark', JSON.stringify(afterToggle));
        ok('тема сохраняется в localStorage', afterToggle.stored === 'dark', String(afterToggle.stored));
        ok('meta theme-color обновляется', afterToggle.meta === '#0a0a0b', afterToggle.meta);
        ok('aria-label описывает действие', afterToggle.label === 'Switch to light theme', afterToggle.label);
        ok('иконка меняется на солнце', afterToggle.moonVisible === 'none', afterToggle.moonVisible);

        await page.reload({ waitUntil: 'domcontentloaded' });
        const persisted = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        ok('выбор переживает перезагрузку', persisted === 'dark', persisted);
        await ctx.close();

        const dark = await newPage(browser, { colorScheme: 'dark' });
        await dark.page.addInitScript(() => localStorage.setItem('linkbio-theme', 'light'));
        await dark.page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
        const forced = await dark.page.evaluate(() => ({
            theme: document.documentElement.getAttribute('data-theme'),
            bg: getComputedStyle(document.body).backgroundColor
        }));
        ok('сохранённая light перебивает тёмную ОС', forced.theme === 'light' && forced.bg === 'rgb(250, 250, 250)', JSON.stringify(forced));
        await dark.ctx.close();
    }

    // ---------------------------------------------------------------
    section('3. Контраст (WCAG AA)');
    for (const theme of ['dark', 'light']) {
        const { ctx, page } = await newPage(browser, { reducedMotion: 'reduce' });
        await page.addInitScript((t) => localStorage.setItem('linkbio-theme', t), theme);
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.waitForTimeout(700);
        const rows = await page.evaluate(contrastScript);
        const bad = rows.filter((r) => r.ratio < r.need);
        ok(theme + ': весь текст проходит AA', bad.length === 0, bad.map((b) => `${b.sel} ${b.ratio}:1 (нужно ${b.need})`).join(' | '));
        const min = rows.reduce((m, r) => Math.min(m, r.ratio), 99);
        console.log('        минимум в теме ' + theme + ': ' + min + ':1');
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('4. Копирование email');
    {
        const { ctx, page } = await newPage(browser, { permissions: ['clipboard-read', 'clipboard-write'] });
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.click('#copyEmail');
        const toast = page.locator('.toast');
        await toast.first().waitFor({ timeout: 2000 });
        ok('тост появляется', await toast.count() > 0);
        const text = await toast.first().textContent();
        ok('тост сообщает об успехе', /copied/i.test(text), text);
        const clip = await page.evaluate(() => navigator.clipboard.readText());
        ok('email реально в буфере', clip === 'leonidovev@gmail.com', clip);
        const live = await page.evaluate(() => {
            const c = document.getElementById('toastContainer');
            return c.getAttribute('role') + '/' + c.getAttribute('aria-live');
        });
        ok('контейнер тостов объявлен для скринридеров', live === 'status/polite', live);
        ok('ошибок нет', page.problems.length === 0, page.problems.join(' | '));
        await ctx.close();

        const fb = await newPage(browser);
        await fb.page.addInitScript(() => {
            Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        });
        await fb.page.goto(BASE + '/', { waitUntil: 'load' });
        await fb.page.click('#copyEmail');
        await fb.page.locator('.toast').first().waitFor({ timeout: 2000 });
        const fbText = await fb.page.locator('.toast').first().textContent();
        ok('без Clipboard API показывается внятное сообщение', /copied|Could not/i.test(fbText || ''), fbText);
        await fb.ctx.close();
    }

    // ---------------------------------------------------------------
    section('5. Адаптивность, тап-таргеты, сетка');
    for (const width of [320, 360, 390, 768, 1440, 2560]) {
        const { ctx, page } = await newPage(browser, { viewport: { width, height: 900 } });
        await page.goto(BASE + '/', { waitUntil: 'load' });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        ok('нет горизонтального скролла @' + width, overflow <= 1, 'overflow=' + overflow + 'px');

        if (width === 360 || width === 390) {
            const small = await page.evaluate(() => {
                const out = [];
                document.querySelectorAll('.link-card, .social-btn, .control-btn, .copy-btn').forEach((el) => {
                    const r = el.getBoundingClientRect();
                    if (r.width && r.height && (r.width < 44 || r.height < 44)) {
                        out.push((el.className || el.tagName) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
                    }
                });
                return out;
            });
            ok('все тап-таргеты >= 44px @' + width, small.length === 0, small.join(' | '));
        }

        const cols = await page.evaluate(() => getComputedStyle(document.querySelector('.social-grid')).gridTemplateColumns.split(' ').length);
        if (width === 390) ok('соцсети: 3 колонки на мобиле', cols === 3, String(cols));
        if (width === 1440) ok('соцсети: 6 колонок на десктопе', cols === 6, String(cols));
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('6. Тач-устройства: стрелки и hover');
    {
        const { ctx, page } = await newPage(browser, { hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.waitForTimeout(800);
        const r = await page.evaluate(() => ({
            hoverNone: matchMedia('(hover: none)').matches,
            arrow: getComputedStyle(document.querySelector('.link-arrow')).opacity
        }));
        ok('эмуляция (hover: none)', r.hoverNone === true, String(r.hoverNone));
        ok('стрелка видна без hover', parseFloat(r.arrow) > 0.3, r.arrow);
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('7. Ссылки и изображения');
    let enAnchorCount = 0;
    let enExternal = 0;
    for (const [url, label] of [[BASE + '/', 'EN'], [BASE + '/ru.html', 'RU']]) {
        const { ctx, page } = await newPage(browser);
        await page.goto(url, { waitUntil: 'load' });
        const audit = await page.evaluate(() => {
            const anchors = [...document.querySelectorAll('a[href]')];
            const bad = [];
            anchors.forEach((a) => {
                const href = a.getAttribute('href');
                const name = (a.getAttribute('aria-label') || a.textContent || '').trim();
                if (!href || href === '#') bad.push('пустой href: ' + name);
                if (!name) bad.push('нет доступного имени: ' + href);
                if (/^https?:/.test(href)) {
                    if (a.target !== '_blank') bad.push('нет target=_blank: ' + href);
                    if (!/noopener/.test(a.rel || '')) bad.push('нет noopener: ' + href);
                }
            });
            const imgs = [...document.images].map((i) => ({ src: i.currentSrc || i.src, w: i.naturalWidth }));
            return {
                count: anchors.length,
                external: anchors.filter((a) => /^https?:/.test(a.getAttribute('href'))).length,
                bad,
                brokenImgs: imgs.filter((i) => i.w === 0)
            };
        });
        ok(label + ': ссылки корректны (' + audit.count + ')', audit.bad.length === 0, audit.bad.slice(0, 5).join(' | '));
        ok(label + ': все картинки загрузились', audit.brokenImgs.length === 0, JSON.stringify(audit.brokenImgs));
        if (label === 'EN') {
            enAnchorCount = audit.count;
            enExternal = audit.external;
            ok('EN: есть ссылки', audit.count > 0, String(audit.count));
        }
        if (label === 'RU') ok('RU: столько же ссылок, что и EN', audit.count === enAnchorCount, String(audit.count));
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('8. SEO и метаданные');
    for (const [url, label, lang, canonical] of [
        [BASE + '/', 'EN', 'en', 'https://bio.leonidov.dev/'],
        [BASE + '/ru.html', 'RU', 'ru', 'https://bio.leonidov.dev/ru.html']
    ]) {
        const { ctx, page } = await newPage(browser);
        await page.goto(url, { waitUntil: 'load' });
        const seo = await page.evaluate(() => {
            const meta = (sel, attr = 'content') => {
                const el = document.querySelector(sel);
                return el ? el.getAttribute(attr) : null;
            };
            const alternates = [...document.querySelectorAll('link[rel="alternate"][hreflang]')].map((l) => l.hreflang);
            const ld = document.querySelector('script[type="application/ld+json"]');
            let parsed = null;
            try { parsed = JSON.parse(ld.textContent); } catch (e) { parsed = 'PARSE_ERROR'; }
            return {
                lang: document.documentElement.lang,
                title: document.title,
                canonical: meta('link[rel="canonical"]', 'href'),
                alternates,
                desc: meta('meta[name="description"]'),
                og: {
                    title: meta('meta[property="og:title"]'),
                    image: meta('meta[property="og:image"]'),
                    w: meta('meta[property="og:image:width"]'),
                    h: meta('meta[property="og:image:height"]'),
                    url: meta('meta[property="og:url"]'),
                    site: meta('meta[property="og:site_name"]')
                },
                twitter: meta('meta[name="twitter:card"]'),
                robots: meta('meta[name="robots"]'),
                ld: parsed
            };
        });
        ok(label + ': lang=' + seo.lang, seo.lang === lang);
        ok(label + ': canonical корректен', seo.canonical === canonical, seo.canonical);
        ok(label + ': hreflang en/ru/x-default', ['en', 'ru', 'x-default'].every((x) => seo.alternates.includes(x)), seo.alternates.join(','));
        ok(label + ': description 50-160 символов', seo.desc && seo.desc.length >= 50 && seo.desc.length <= 160, String(seo.desc && seo.desc.length));
        ok(label + ': OG image 1200x630', seo.og.image && seo.og.w === '1200' && seo.og.h === '630');
        ok(label + ': og:url без расхождений', seo.og.url === canonical, seo.og.url);
        ok(label + ': twitter card', seo.twitter === 'summary_large_image');
        ok(label + ': JSON-LD валиден', seo.ld !== 'PARSE_ERROR' && seo.ld['@type'] === 'ProfilePage', typeof seo.ld === 'string' ? seo.ld : seo.ld['@type']);
        const person = seo.ld && seo.ld.mainEntity;
        ok(label + ': JSON-LD Person.sameAs заполнен', person && person['@type'] === 'Person' && Array.isArray(person.sameAs) && person.sameAs.length === enExternal, person && String(person.sameAs && person.sameAs.length));
        await ctx.close();
    }
    {
        const { ctx, page } = await newPage(browser);
        for (const f of ['/robots.txt', '/sitemap.xml']) {
            const res = await page.goto(BASE + f);
            ok('доступен ' + f, res.status() === 200, String(res.status()));
        }
        const favicon = await page.goto(BASE + '/assets/favicon.svg');
        ok('favicon.svg отдается', favicon.status() === 200 && (favicon.headers()['content-type'] || '').includes('svg'), favicon.headers()['content-type']);
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('9. Переключение языка');
    {
        const { ctx, page } = await newPage(browser);
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await Promise.all([page.waitForURL(/ru\.html$/), page.click('#langToggle')]);
        ok('EN -> RU', /ru\.html$/.test(page.url()) && (await page.evaluate(() => document.documentElement.lang)) === 'ru', page.url());
        await Promise.all([page.waitForURL(BASE + '/'), page.click('#langToggle')]);
        ok('RU -> EN (канонический /)', page.url() === BASE + '/', page.url());
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('10. Клавиатура и фокус');
    {
        const { ctx, page } = await newPage(browser);
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.keyboard.press('Tab');
        const focus = await page.evaluate(() => {
            const el = document.activeElement;
            const cs = getComputedStyle(el);
            return { id: el.id, outline: cs.outlineWidth, style: cs.outlineStyle };
        });
        ok('Tab даёт видимый фокус', parseFloat(focus.outline) >= 2 && focus.style !== 'none', JSON.stringify(focus));

        const before = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        await page.evaluate(() => document.body.focus());
        await page.keyboard.press('t');
        const after = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
        ok('хоткей T переключает тему', before !== after, before + ' -> ' + after);

        const ru = await newPage(browser);
        await ru.page.goto(BASE + '/ru.html', { waitUntil: 'load' });
        await Promise.all([ru.page.waitForURL(BASE + '/'), ru.page.keyboard.press('l')]);
        ok('хоткей L переключает язык', ru.page.url() === BASE + '/', ru.page.url());
        await ru.ctx.close();
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('11. prefers-reduced-motion');
    {
        const { ctx, page } = await newPage(browser, { reducedMotion: 'reduce' });
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.waitForTimeout(300);
        const opacities = await page.evaluate(() => [...document.querySelectorAll('.links-section, .profile')].map((el) => getComputedStyle(el).opacity));
        ok('контент виден без анимаций', opacities.every((o) => parseFloat(o) > 0.99), opacities.join(','));
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('12. Ripple и целостность клика');
    {
        const { ctx, page } = await newPage(browser);
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.waitForTimeout(1200);
        const card = page.locator('.link-card.project-card');
        await page.evaluate(() => {
            window.__rippleSeen = false;
            const target = document.querySelector('.link-card.project-card');
            new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (node.nodeType === 1 && node.classList.contains('ripple')) window.__rippleSeen = true;
                    }
                }
            }).observe(target, { childList: true });
        });
        const box = await card.boundingBox();
        const [popup] = await Promise.all([
            page.waitForEvent('popup', { timeout: 3000 }).catch(() => null),
            page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
        ]);
        const ripple = await page.evaluate(() => window.__rippleSeen);
        ok('клик по карточке создаёт ripple', ripple === true, String(ripple));
        ok('клик открывает ссылку в новой вкладке', popup !== null, 'popup=' + (popup ? popup.url() : 'null'));
        if (popup) await popup.close();
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('13. Layout Shift (CLS)');
    {
        const { ctx, page } = await newPage(browser);
        await page.addInitScript(() => {
            window.__cls = 0;
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) window.__cls += entry.value;
                }
            }).observe({ type: 'layout-shift', buffered: true });
        });
        await page.goto(BASE + '/', { waitUntil: 'load' });
        await page.waitForTimeout(1500);
        const cls = await page.evaluate(() => window.__cls);
        ok('CLS < 0.1', cls < 0.1, String(Math.round(cls * 1000) / 1000));
        await ctx.close();
    }

    // ---------------------------------------------------------------
    section('14. axe-core (a11y)');
    const axeTags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
    for (const theme of ['dark', 'light']) {
        for (const [url, label] of [[BASE + '/', 'EN'], [BASE + '/ru.html', 'RU']]) {
            const { ctx, page } = await newPage(browser, { reducedMotion: 'reduce' });
            await page.addInitScript((t) => localStorage.setItem('linkbio-theme', t), theme);
            await page.goto(url, { waitUntil: 'load' });
            await page.waitForTimeout(700);
            await page.addScriptTag({ path: AXE });
            const res = await page.evaluate(async (tags) => {
                return await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
            }, axeTags);
            const summary = res.violations.map((v) => v.id + ' [' + v.impact + '] x' + v.nodes.length + ': ' + v.nodes[0].target.join(' '));
            ok(label + ' / ' + theme + ': 0 нарушений', res.violations.length === 0, summary.join(' | '));
            if (res.violations.length) {
                res.violations.forEach((v) => console.log('        ' + v.id + ' -> ' + v.help + '\n          ' + v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('\n          ')));
            }
            await ctx.close();
        }
    }

    // ---------------------------------------------------------------
    await browser.close();

    console.log('\n' + '='.repeat(60));
    console.log('ИТОГО: ' + pass + ' passed, ' + fail + ' failed');
    if (fail) {
        console.log('\nПровалы:');
        failures.forEach((f) => console.log(' - ' + f.name + (f.detail ? ': ' + f.detail : '')));
    }
    process.exit(fail ? 1 : 0);
})().catch((e) => {
    console.error('FATAL', e);
    process.exit(2);
});

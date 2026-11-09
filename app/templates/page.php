<?php
/**
 * @var array $v
 */

use Bio\Icons;
use Bio\Renderer;
use Bio\Theme;

$lang = $v['lang'];
$seo = $v['seo'];
$ui = $v['ui'];
$profile = $v['profile'];
$settings = $v['settings'];
$assetVersion = substr((string) @sha1_file(dirname(__DIR__, 2) . '/public/styles.css'), 0, 12)
    . '-' . substr((string) @sha1_file(dirname(__DIR__, 2) . '/public/script.js'), 0, 12);
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>" data-theme="dark" data-theme-default="<?= e($settings['themeDefault']) ?>" data-ga4="<?= e($settings['ga4']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($seo['title']) ?></title>
    <meta name="description" content="<?= e($seo['description']) ?>">
    <meta name="robots" content="index, follow">

    <!-- Alternate language -->
<?php foreach ($seo['alternates'] as $hreflang => $href): ?>
    <link rel="alternate" hreflang="<?= e((string) $hreflang) ?>" href="<?= e($href) ?>">
<?php endforeach; ?>
    <link rel="canonical" href="<?= e($seo['canonical']) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= e($seo['og']['title']) ?>">
    <meta property="og:description" content="<?= e($seo['og']['description']) ?>">
    <meta property="og:image" content="<?= e($seo['og']['image']) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= e($seo['og']['imageAlt']) ?>">
    <meta property="og:url" content="<?= e($seo['og']['url']) ?>">
    <meta property="og:site_name" content="<?= e($seo['og']['siteName']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="<?= e($seo['og']['locale']) ?>">
    <meta property="og:locale:alternate" content="<?= e($seo['og']['localeAlternate']) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seo['twitter']['title']) ?>">
    <meta name="twitter:description" content="<?= e($seo['twitter']['description']) ?>">
    <meta name="twitter:image" content="<?= e($seo['twitter']['image']) ?>">
    <meta name="twitter:image:alt" content="<?= e($seo['twitter']['imageAlt']) ?>">

    <!-- Theme Color -->
    <meta name="theme-color" content="#0a0a0b">
    <meta name="color-scheme" content="dark light">

    <!-- Theme bootstrap: runs before CSS paints to avoid a flash of the wrong theme -->
    <script><?= Theme::script() ?></script>

    <!-- Icons & Fonts -->
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
    <link rel="preload" as="image" href="<?= e(asset($settings['photo'])) ?>" fetchpriority="high">
    <link rel="stylesheet" href="/styles.css?v=<?= e($assetVersion) ?>">

    <!-- Structured data -->
    <script type="application/ld+json">
<?= $seo['jsonld'] ?>
    </script>
<?php if ($settings['ga4'] !== ''): ?>

    <!-- Google Analytics 4 -->
    <link rel="preconnect" href="https://www.googletagmanager.com">
<?php endif; ?>
</head>
<body>
    <!-- Controls -->
    <nav class="controls" aria-label="<?= e($ui['controls']) ?>">
        <a href="<?= e($v['otherHref']) ?>" class="control-btn" id="langToggle" hreflang="<?= e($v['other']) ?>" aria-keyshortcuts="Alt+Shift+L">
            <span class="lang-text"><?= $v['other'] === 'ru' ? 'RU' : 'EN' ?></span>
            <span class="sr-only"><?= e($ui['switchLang']) ?></span>
        </a>
        <button type="button" class="control-btn" id="themeToggle" aria-label="<?= e($ui['themeToLight']) ?>" aria-keyshortcuts="Alt+Shift+T">
            <?= Icons::render('sun', 'icon-sun') ?>

            <?= Icons::render('moon', 'icon-moon') ?>

        </button>
    </nav>

    <main class="container">
        <!-- Profile Section -->
        <header class="profile">
            <div class="avatar">
                <img src="<?= e(asset($settings['photo'])) ?>" alt="<?= e($profile['alt']) ?>" class="avatar-img" width="120" height="120" fetchpriority="high" decoding="async">
            </div>
            <h1 class="name"><?= e($profile['name']) ?></h1>
            <p class="title"><?= e($profile['role']) ?></p>
            <p class="bio"><?= e($profile['bio']) ?></p>
        </header>
<?php foreach ($v['sections'] as $section): ?>
        <section class="links-section">
<?php if ($section['label'] !== null): ?>
            <h2 class="section-title"><?= e($section['label']) ?></h2>
<?php else: ?>
            <h2 class="sr-only"><?= e($section['srLabel']) ?></h2>
<?php endif; ?>

<?php foreach ($section['cards'] as $item): ?>
<?php if ($item['type'] === 'email'): ?>
<?= Renderer::emailBlock($item, $lang, $ui) ?>

<?php else: ?>
        <?= Renderer::card($item, $lang) ?>

<?php endif; ?>
<?php endforeach; ?>
<?php if ($section['socials'] !== []): ?>
            <div class="social-grid">
<?php foreach ($section['socials'] as $item): ?>
                <?= Renderer::socialButton($item, $lang) ?>

<?php endforeach; ?>
            </div>
<?php endif; ?>
        </section>
<?php endforeach; ?>
        <!-- Footer -->
        <footer class="footer">
            <p>&copy; <?= e((string) $settings['footerStart']) ?> - <?= e((string) $v['year']) ?> <?= e($profile['name']) ?></p>
        </footer>
    </main>

    <!-- Toast notifications -->
    <div class="toast-container" id="toastContainer" role="status" aria-live="polite" aria-atomic="true"></div>

    <script type="application/json" id="i18n"><?= $v['i18n'] ?></script>
    <script src="/script.js?v=<?= e($assetVersion) ?>"></script>
</body>
</html>

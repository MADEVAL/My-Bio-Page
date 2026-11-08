<?php
declare(strict_types=1);

namespace Bio;

/**
 * The tiny theme bootstrap that must run before CSS paints. It lives in one
 * place so the template and the CSP hash cannot drift apart.
 */
final class Theme
{
    private const JS = <<<'JS'
(function () {
    var theme;
    try { theme = localStorage.getItem('linkbio-theme'); } catch (e) { /* storage blocked */ }
    if (theme !== 'light' && theme !== 'dark') {
        var fallback = document.documentElement.getAttribute('data-theme-default') || 'system';
        theme = fallback === 'system'
            ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
            : fallback;
    }
    document.documentElement.setAttribute('data-theme', theme);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0a0a0b' : '#fafafa');
})();
JS;

    public static function script(): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim(self::JS));
    }

    /** CSP source expression for the inline bootstrap. */
    public static function cspHash(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::script(), true)) . "'";
    }
}

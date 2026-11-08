/**
 * Link Bio Page - Theme Controller & UX Enhancements
 * Yevhen Leonidov | Software Architect
 * Updated 2026 - SEO-friendly two-page approach (EN/RU)
 */

(function () {
    'use strict';

    // ==========================================
    // Configuration
    // ==========================================
    const CONFIG = {
        storageKey: 'linkbio-theme',
        themeColors: {
            dark: '#0a0a0b',
            light: '#fafafa'
        }
    };

    // UI strings are rendered by PHP into #i18n, so nothing is duplicated here.
    const I18N_FALLBACK = {
        themeToLight: 'Switch to light theme',
        themeToDark: 'Switch to dark theme',
        copied: 'Email copied!',
        copyFailed: 'Copy failed'
    };

    const t = (() => {
        const el = document.getElementById('i18n');

        if (!el) return I18N_FALLBACK;

        try {
            return { ...I18N_FALLBACK, ...JSON.parse(el.textContent) };
        } catch {
            return I18N_FALLBACK;
        }
    })();

    // ==========================================
    // Safe localStorage wrapper
    // ==========================================
    const Storage = {
        get(key) {
            try {
                return localStorage.getItem(key);
            } catch {
                return null;
            }
        },
        set(key, value) {
            try {
                localStorage.setItem(key, value);
            } catch {
                // Private mode or storage full - silently fail
            }
        }
    };

    // ==========================================
    // Toast Notifications
    // ==========================================
    const Toast = {
        show(message) {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            container.appendChild(toast);

            toast.addEventListener('animationend', (e) => {
                if (e.animationName === 'toastOut') toast.remove();
            });
        }
    };

    // ==========================================
    // Theme Controller
    // ==========================================
    const ThemeController = {
        init() {
            this.btn = document.getElementById('themeToggle');
            if (!this.btn) return;

            this.meta = document.querySelector('meta[name="theme-color"]');

            // The theme attribute is already set by the inline <head> script
            const initial = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            this.apply(initial, false);

            this.btn.addEventListener('click', () => this.toggle());
        },

        apply(theme, persist = true) {
            document.documentElement.setAttribute('data-theme', theme);
            if (persist) Storage.set(CONFIG.storageKey, theme);

            if (this.meta) this.meta.setAttribute('content', CONFIG.themeColors[theme]);
            this.btn.setAttribute('aria-label', theme === 'dark' ? t.themeToLight : t.themeToDark);
        },

        toggle() {
            const current = document.documentElement.getAttribute('data-theme');
            this.apply(current === 'dark' ? 'light' : 'dark');
        }
    };

    // ==========================================
    // Email Copy Handler
    // ==========================================
    const EmailCopy = {
        init() {
            const copyBtn = document.getElementById('copyEmail');
            if (!copyBtn) return;

            copyBtn.addEventListener('click', async () => {
                const email = copyBtn.dataset.email;
                const copied = await this.copy(email);
                Toast.show(copied ? t.copied : t.copyFailed);
            });
        },

        async copy(text) {
            // Modern path
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                    return true;
                }
            } catch {
                // Permission denied or insecure context - fall through
            }

            // Legacy fallback
            try {
                const field = document.createElement('textarea');
                field.value = text;
                field.setAttribute('readonly', '');
                field.style.cssText = 'position:fixed;top:-1000px;left:-1000px;opacity:0';
                document.body.appendChild(field);
                field.select();
                const ok = document.execCommand('copy');
                field.remove();
                return ok;
            } catch {
                return false;
            }
        }
    };

    // ==========================================
    // Keyboard Shortcuts
    // ==========================================
    const KeyboardNav = {
        init() {
            document.addEventListener('keydown', (e) => {
                if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
                if (this.isEditable()) return;

                const key = e.key.toLowerCase();

                if (key === 't') {
                    ThemeController.toggle();
                }

                if (key === 'l') {
                    const langLink = document.getElementById('langToggle');
                    if (langLink) langLink.click();
                }
            });
        },

        isEditable() {
            const el = document.activeElement;
            if (!el) return false;
            return el.isContentEditable ||
                el.tagName === 'INPUT' ||
                el.tagName === 'TEXTAREA' ||
                el.tagName === 'SELECT';
        }
    };

    // ==========================================
    // Ripple Effect
    // ==========================================
    const RippleEffect = {
        init() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            document.querySelectorAll('.link-card').forEach((card) => {
                card.addEventListener('click', (e) => {
                    // detail === 0 means keyboard activation
                    if (e.detail === 0) return;
                    // Don't animate modified clicks (new tab / download)
                    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                    this.create(e, card);
                });
            });
        },

        create(e, element) {
            const rect = element.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);

            const ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.width = size + 'px';
            ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';

            element.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove(), { once: true });
        }
    };

    // ==========================================
    // Initialize
    // ==========================================
    function init() {
        ThemeController.init();
        EmailCopy.init();
        KeyboardNav.init();
        RippleEffect.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

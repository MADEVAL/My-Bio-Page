/**
 * Link Bio Page - Theme Controller & UX Enhancements
 * Yevhen Leonidov | Software Architect
 * Updated 2026 — SEO-friendly two-page approach (EN/RU)
 */

(function() {
    'use strict';

    // ==========================================
    // Configuration
    // ==========================================
    const CONFIG = {
        storageKeys: {
            theme: 'linkbio-theme'
        },
        defaultTheme: 'dark'
    };

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
                // Private mode or storage full — silently fail
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
            this.themeBtn = document.getElementById('themeToggle');
            this.icon = this.themeBtn.querySelector('i');
            
            const savedTheme = Storage.get(CONFIG.storageKeys.theme) || CONFIG.defaultTheme;
            this.setTheme(savedTheme, false);
            
            this.themeBtn.addEventListener('click', () => this.toggle());
        },

        setTheme(theme, animate = true) {
            document.documentElement.setAttribute('data-theme', theme);
            Storage.set(CONFIG.storageKeys.theme, theme);
            
            this.icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

            const metaDark = document.querySelector('meta[name="theme-color"][media*="dark"]');
            const metaLight = document.querySelector('meta[name="theme-color"][media*="light"]');
            if (metaDark) metaDark.content = theme === 'dark' ? '#0a0a0b' : '#fafafa';
            if (metaLight) metaLight.content = theme === 'light' ? '#fafafa' : '#0a0a0b';

            if (animate) {
                this.themeBtn.style.transform = 'scale(0.9)';
                setTimeout(() => { this.themeBtn.style.transform = ''; }, 150);
            }
        },

        toggle() {
            const current = document.documentElement.getAttribute('data-theme');
            this.setTheme(current === 'dark' ? 'light' : 'dark');
        }
    };

    // ==========================================
    // Email Copy Handler
    // ==========================================
    const EmailCopy = {
        init() {
            const copyBtn = document.getElementById('copyEmail');
            if (!copyBtn) return;

            copyBtn.addEventListener('click', () => {
                const email = copyBtn.dataset.email;
                const isRu = document.documentElement.lang === 'ru';
                
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(email).then(() => {
                        Toast.show(isRu ? 'Email скопирован!' : 'Email copied!');
                    });
                }
            });
        }
    };

    // ==========================================
    // Link Analytics
    // ==========================================
    const Analytics = {
        init() {
            const links = document.querySelectorAll('.link-card, .social-btn');
            links.forEach(link => {
                link.addEventListener('click', () => {
                    const href = link.getAttribute('href');
                    const title = link.querySelector('.link-title')?.textContent || 
                                  link.getAttribute('aria-label') || 
                                  'Unknown';
                    console.log(`[LinkBio] Clicked: ${title} -> ${href}`);
                });
            });
        }
    };

    // ==========================================
    // Keyboard Navigation
    // ==========================================
    const KeyboardNav = {
        init() {
            document.addEventListener('keydown', (e) => {
                if (this.isInteractiveFocused()) return;

                if (e.key === 't' || e.key === 'T') {
                    ThemeController.toggle();
                }
                if (e.key === 'l' || e.key === 'L') {
                    // Navigate to the other language page
                    const langLink = document.getElementById('langToggle');
                    if (langLink) langLink.click();
                }
            });
        },

        isInteractiveFocused() {
            const el = document.activeElement;
            return el.tagName === 'INPUT' || 
                   el.tagName === 'TEXTAREA' || 
                   el.tagName === 'A' ||
                   el.tagName === 'BUTTON' ||
                   el.isContentEditable;
        }
    };

    // ==========================================
    // Ripple Effect
    // ==========================================
    const RippleEffect = {
        init() {
            if (!document.getElementById('ripple-style')) {
                const style = document.createElement('style');
                style.id = 'ripple-style';
                style.textContent = `
                    @keyframes ripple {
                        to { transform: scale(4); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }

            const cards = document.querySelectorAll('.link-card');
            cards.forEach(card => {
                card.addEventListener('click', (e) => this.create(e, card));
            });
        },

        create(e, element) {
            const ripple = document.createElement('span');
            const rect = element.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            const x = e.clientX - rect.left - size / 2;
            const y = e.clientY - rect.top - size / 2;

            ripple.style.cssText = `
                position: absolute;
                width: ${size}px;
                height: ${size}px;
                left: ${x}px;
                top: ${y}px;
                background: currentColor;
                opacity: 0.1;
                border-radius: 50%;
                transform: scale(0);
                animation: ripple 0.6s ease-out;
                pointer-events: none;
            `;

            element.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());
        }
    };

    // ==========================================
    // Initialize
    // ==========================================
    function init() {
        ThemeController.init();
        EmailCopy.init();
        Analytics.init();
        KeyboardNav.init();
        RippleEffect.init();

        console.log('[LinkBio] Initialized successfully');
        console.log('[LinkBio] Keyboard shortcuts: T = Toggle theme, L = Switch language');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

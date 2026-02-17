/**
 * Link Bio Page - Theme & Language Controller
 * Yevhen Leonidov | Software Architect
 * Updated 2026 — with safety, a11y and UX improvements
 */

(function() {
    'use strict';

    // ==========================================
    // Configuration
    // ==========================================
    const CONFIG = {
        storageKeys: {
            theme: 'linkbio-theme',
            lang: 'linkbio-lang'
        },
        defaultTheme: 'dark',
        defaultLang: 'en'
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
            
            // Update icon
            this.icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

            // Update theme-color meta
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
        },

        get current() {
            return document.documentElement.getAttribute('data-theme');
        }
    };

    // ==========================================
    // Language Controller
    // ==========================================
    const LangController = {
        init() {
            this.langBtn = document.getElementById('langToggle');
            this.langText = this.langBtn.querySelector('.lang-text');
            
            const savedLang = Storage.get(CONFIG.storageKeys.lang) || this.detectLanguage();
            this.setLanguage(savedLang, false);
            
            this.langBtn.addEventListener('click', () => this.toggle());
        },

        detectLanguage() {
            const browserLang = navigator.language || navigator.userLanguage;
            return browserLang.startsWith('ru') ? 'ru' : CONFIG.defaultLang;
        },

        setLanguage(lang, animate = true) {
            this.currentLang = lang;
            Storage.set(CONFIG.storageKeys.lang, lang);
            
            // Show opposite language as option
            this.langText.textContent = lang === 'en' ? 'RU' : 'EN';
            
            document.documentElement.lang = lang;
            
            // Re-query translatable elements each time (supports dynamic DOM)
            const elements = document.querySelectorAll('[data-en], [data-ru]');
            elements.forEach(el => {
                const text = el.getAttribute(`data-${lang}`);
                if (text) el.textContent = text;
            });

            // Update aria-labels
            const ariaEls = document.querySelectorAll('[data-aria-en], [data-aria-ru]');
            ariaEls.forEach(el => {
                const ariaText = el.getAttribute(`data-aria-${lang}`);
                if (ariaText) el.setAttribute('aria-label', ariaText);
            });

            if (animate) {
                this.langBtn.style.transform = 'scale(0.9)';
                setTimeout(() => { this.langBtn.style.transform = ''; }, 150);
            }
        },

        toggle() {
            this.setLanguage(this.currentLang === 'en' ? 'ru' : 'en');
        }
    };

    // ==========================================
    // Email Copy Handler
    // ==========================================
    const EmailCopy = {
        init() {
            const emailCard = document.getElementById('emailCard');
            if (!emailCard) return;

            emailCard.addEventListener('click', (e) => {
                e.preventDefault();
                const email = emailCard.dataset.email;
                
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(email).then(() => {
                        const msg = LangController.currentLang === 'ru' 
                            ? 'Email скопирован!' 
                            : 'Email copied!';
                        Toast.show(msg);
                    }).catch(() => {
                        // Fallback: open mailto
                        window.location.href = `mailto:${email}`;
                    });
                } else {
                    window.location.href = `mailto:${email}`;
                }
            });
        }
    };

    // ==========================================
    // Link Analytics (Optional Enhancement)
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
    // Keyboard Navigation Enhancement
    // ==========================================
    const KeyboardNav = {
        init() {
            document.addEventListener('keydown', (e) => {
                if (this.isInteractiveFocused()) return;

                if (e.key === 't' || e.key === 'T') {
                    ThemeController.toggle();
                }
                if (e.key === 'l' || e.key === 'L') {
                    LangController.toggle();
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
    // Ripple Effect (Subtle interaction feedback)
    // ==========================================
    const RippleEffect = {
        init() {
            // Inject ripple keyframes once
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
    // Initialize Everything
    // ==========================================
    function init() {
        ThemeController.init();
        LangController.init();
        EmailCopy.init();
        Analytics.init();
        KeyboardNav.init();
        RippleEffect.init();

        console.log('[LinkBio] Initialized successfully');
        console.log('[LinkBio] Keyboard shortcuts: T = Toggle theme, L = Toggle language');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

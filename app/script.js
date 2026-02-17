/**
 * Link Bio Page - Theme & Language Controller
 * Yevhen Leonidov | Software Architect
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
    // Theme Controller
    // ==========================================
    const ThemeController = {
        init() {
            this.themeBtn = document.getElementById('themeToggle');
            this.icon = this.themeBtn.querySelector('i');
            
            // Load saved theme or use default
            const savedTheme = localStorage.getItem(CONFIG.storageKeys.theme) || CONFIG.defaultTheme;
            this.setTheme(savedTheme, false);
            
            // Bind events
            this.themeBtn.addEventListener('click', () => this.toggle());
        },

        setTheme(theme, animate = true) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem(CONFIG.storageKeys.theme, theme);
            
            // Update icon
            if (theme === 'dark') {
                this.icon.className = 'fas fa-sun';
            } else {
                this.icon.className = 'fas fa-moon';
            }

            // Optional animation feedback
            if (animate) {
                this.themeBtn.style.transform = 'scale(0.9)';
                setTimeout(() => {
                    this.themeBtn.style.transform = '';
                }, 150);
            }
        },

        toggle() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            this.setTheme(newTheme);
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
            this.elements = document.querySelectorAll('[data-en], [data-ru]');
            
            // Load saved language or detect from browser
            const savedLang = localStorage.getItem(CONFIG.storageKeys.lang) || this.detectLanguage();
            this.setLanguage(savedLang, false);
            
            // Bind events
            this.langBtn.addEventListener('click', () => this.toggle());
        },

        detectLanguage() {
            const browserLang = navigator.language || navigator.userLanguage;
            return browserLang.startsWith('ru') ? 'ru' : CONFIG.defaultLang;
        },

        setLanguage(lang, animate = true) {
            this.currentLang = lang;
            localStorage.setItem(CONFIG.storageKeys.lang, lang);
            
            // Update button text (show opposite language as option)
            this.langText.textContent = lang === 'en' ? 'RU' : 'EN';
            
            // Update HTML lang attribute
            document.documentElement.lang = lang;
            
            // Update all translatable elements
            this.elements.forEach(el => {
                const text = el.getAttribute(`data-${lang}`);
                if (text) {
                    el.textContent = text;
                }
            });

            // Optional animation feedback
            if (animate) {
                this.langBtn.style.transform = 'scale(0.9)';
                setTimeout(() => {
                    this.langBtn.style.transform = '';
                }, 150);
            }
        },

        toggle() {
            const newLang = this.currentLang === 'en' ? 'ru' : 'en';
            this.setLanguage(newLang);
        }
    };

    // ==========================================
    // Link Analytics (Optional Enhancement)
    // ==========================================
    const Analytics = {
        init() {
            const links = document.querySelectorAll('.link-card, .social-btn');
            links.forEach(link => {
                link.addEventListener('click', (e) => {
                    const href = link.getAttribute('href');
                    const title = link.querySelector('.link-title')?.textContent || 
                                  link.getAttribute('aria-label') || 
                                  'Unknown';
                    
                    // Log click (replace with actual analytics if needed)
                    console.log(`[LinkBio] Clicked: ${title} -> ${href}`);
                    
                    // You can integrate with analytics services here
                    // Example: gtag('event', 'click', { 'link_title': title, 'link_url': href });
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
                // Toggle theme with 'T' key
                if (e.key === 't' || e.key === 'T') {
                    if (!this.isInputFocused()) {
                        ThemeController.toggle();
                    }
                }
                
                // Toggle language with 'L' key
                if (e.key === 'l' || e.key === 'L') {
                    if (!this.isInputFocused()) {
                        LangController.toggle();
                    }
                }
            });
        },

        isInputFocused() {
            const activeEl = document.activeElement;
            return activeEl.tagName === 'INPUT' || 
                   activeEl.tagName === 'TEXTAREA' || 
                   activeEl.isContentEditable;
        }
    };

    // ==========================================
    // Ripple Effect (Subtle interaction feedback)
    // ==========================================
    const RippleEffect = {
        init() {
            const cards = document.querySelectorAll('.link-card');
            cards.forEach(card => {
                card.addEventListener('click', (e) => {
                    this.create(e, card);
                });
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

            // Add ripple animation if not exists
            if (!document.getElementById('ripple-style')) {
                const style = document.createElement('style');
                style.id = 'ripple-style';
                style.textContent = `
                    @keyframes ripple {
                        to {
                            transform: scale(4);
                            opacity: 0;
                        }
                    }
                `;
                document.head.appendChild(style);
            }

            element.style.position = 'relative';
            element.style.overflow = 'hidden';
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
        Analytics.init();
        KeyboardNav.init();
        RippleEffect.init();

        console.log('[LinkBio] Initialized successfully');
        console.log('[LinkBio] Keyboard shortcuts: T = Toggle theme, L = Toggle language');
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

'use strict';
/**
 * GigHub - Global Navigation & Account Menu Handler
 * Manages outside-click dismissal, Escape key closing, and accessible focus
 * for the User Profile Dropdown (<details class="gh-account-menu">).
 */
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        // Outside click listener
        document.addEventListener('click', (e) => {
            const openMenus = document.querySelectorAll('.gh-account-menu[open]');
            openMenus.forEach(menu => {
                if (!menu.contains(e.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        // Escape key listener
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const openMenus = document.querySelectorAll('.gh-account-menu[open]');
                openMenus.forEach(menu => {
                    menu.removeAttribute('open');
                    const summary = menu.querySelector('summary');
                    if (summary && typeof summary.focus === 'function') {
                        summary.focus();
                    }
                });
            }
        });
    });
})();

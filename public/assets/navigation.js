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

        // Notification Unread Count Poller
        const bellBtn = document.getElementById('gh-bell-btn');
        const bellBadge = document.getElementById('gh-bell-badge');

        if (bellBtn && bellBadge) {
            let isPolling = false;
            let lastCount = -1;

            const updateBadge = (count) => {
                if (count === lastCount) return;
                lastCount = count;
                if (count > 0) {
                    bellBadge.textContent = count > 99 ? '99+' : String(count);
                    bellBadge.style.display = 'inline-block';
                    bellBtn.setAttribute('aria-label', 'Notifications (' + count + ' unread)');
                } else {
                    bellBadge.style.display = 'none';
                    bellBadge.textContent = '';
                    bellBtn.setAttribute('aria-label', 'Notifications');
                }
            };

            const fetchCount = async () => {
                if (isPolling || document.visibilityState !== 'visible') return;
                isPolling = true;
                try {
                    const res = await fetch('?route=/notifications/count', {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if (typeof data.unread_count === 'number') {
                            updateBadge(data.unread_count);
                        }
                    }
                } catch {
                    // Fail silently on network errors
                } finally {
                    isPolling = false;
                }
            };

            // Initial async check on page load
            fetchCount();

            // Periodic 60-second polling
            setInterval(fetchCount, 60000);

            // Re-fetch immediately when user switches back to this tab
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    fetchCount();
                }
            });
        }
    });
})();

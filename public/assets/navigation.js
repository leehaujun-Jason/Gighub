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

        // Header Badges Poller (Notifications & Messages)
        const bellBtn = document.getElementById('gh-bell-btn');
        const bellBadge = document.getElementById('gh-bell-badge');
        const msgBtn = document.getElementById('gh-messages-btn');
        const msgBadge = document.getElementById('gh-messages-badge');

        if (bellBtn || msgBtn) {
            let isPollingNotifs = false;
            let isPollingMsgs = false;
            let lastNotifCount = -1;
            let lastMsgCount = -1;

            const updateNotifBadge = (count) => {
                if (!bellBadge || !bellBtn) return;
                if (count === lastNotifCount) return;
                lastNotifCount = count;
                if (count > 0) {
                    bellBadge.textContent = count > 99 ? '99+' : String(count);
                    bellBadge.style.display = 'inline-block';
                    bellBadge.classList.add('gh-badge-active');
                    bellBtn.setAttribute('aria-label', 'Notifications (' + count + ' unread)');
                } else {
                    bellBadge.style.display = 'none';
                    bellBadge.classList.remove('gh-badge-active');
                    bellBadge.textContent = '';
                    bellBtn.setAttribute('aria-label', 'Notifications');
                }
            };

            const updateMsgBadge = (count) => {
                if (!msgBadge || !msgBtn) return;
                if (count === lastMsgCount) return;
                lastMsgCount = count;
                if (count > 0) {
                    msgBadge.textContent = count > 99 ? '99+' : String(count);
                    msgBadge.style.display = 'inline-block';
                    msgBadge.classList.add('gh-badge-active');
                    msgBtn.setAttribute('aria-label', 'Messages (' + count + ' unread)');
                } else {
                    msgBadge.style.display = 'none';
                    msgBadge.classList.remove('gh-badge-active');
                    msgBadge.textContent = '';
                    msgBtn.setAttribute('aria-label', 'Messages');
                }
            };

            const fetchNotifCount = async () => {
                if (!bellBadge || isPollingNotifs || document.visibilityState !== 'visible') return;
                isPollingNotifs = true;
                try {
                    const res = await fetch('?route=/notifications/count', {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if (typeof data.unread_count === 'number') {
                            updateNotifBadge(data.unread_count);
                        }
                    }
                } catch {
                    // Fail silently on network errors
                } finally {
                    isPollingNotifs = false;
                }
            };

            const fetchMsgCount = async () => {
                if (!msgBadge || isPollingMsgs || document.visibilityState !== 'visible') return;
                isPollingMsgs = true;
                try {
                    const res = await fetch('?route=/messages/count', {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    if (res.ok) {
                        const data = await res.json();
                        if (typeof data.count === 'number') {
                            updateMsgBadge(data.count);
                        }
                    }
                } catch {
                    // Fail silently on network errors
                } finally {
                    isPollingMsgs = false;
                }
            };

            const pollAll = () => {
                fetchNotifCount();
                fetchMsgCount();
            };

            // Initial check on page load
            pollAll();

            // Unified 60-second polling cycle
            setInterval(pollAll, 60000);

            // Re-fetch immediately when user switches back to this tab
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    pollAll();
                }
            });

            // Listen for read events dispatched by message thread to update immediately
            window.addEventListener('gh:messages-read', () => {
                lastMsgCount = -1;
                fetchMsgCount();
            });
        }
    });
})();

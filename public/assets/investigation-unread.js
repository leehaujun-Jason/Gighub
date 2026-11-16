(() => {
    const threads = document.querySelectorAll('[data-investigation-thread]');
    if (!threads.length) return;
    let refreshing = false;
    async function refresh() {
        if (refreshing || document.visibilityState !== 'visible') return;
        refreshing = true;
        try {
            await Promise.allSettled([...threads].map(async (thread) => {
                const url = new URL(thread.href);
                url.searchParams.set('route', '/investigation-chat/count');
                const response = await fetch(url, {cache: 'no-store', headers: {Accept: 'application/json'}});
                if (!response.ok) return;
                const {unread_count: count} = await response.json();
                if (!Number.isInteger(count) || count < 0) return;
                const badge = thread.querySelector('[data-investigation-unread]');
                badge.textContent = count;
                badge.setAttribute('aria-label', count + ' unread messages');
                badge.hidden = count === 0;
            }));
        } finally {
            refreshing = false;
        }
    }
    window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    setInterval(refresh, 15000);
    refresh();
})();

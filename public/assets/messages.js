/**
 * GigHub Module 20 — Client-Freelancer Messaging Client Script
 * Lightweight 10-second visibility-aware polling, DOM rendering, read acknowledgement,
 * plain-text clipboard copying, and lightweight emoji insertion.
 */
(() => {
    'use strict';

    const container = document.getElementById('gh-messages-container');
    if (!container) {
        return;
    }

    const form = document.getElementById('gh-message-form');
    const textarea = document.getElementById('gh-message-input');
    const sendBtn = document.getElementById('gh-send-btn');
    const charCounter = document.getElementById('gh-char-counter');
    const emptyNotice = document.getElementById('gh-thread-empty');

    const bidId = parseInt(container.getAttribute('data-bid-id'), 10);
    const csrfToken = container.getAttribute('data-csrf') || '';
    const myUserId = parseInt(container.getAttribute('data-user-id'), 10);
    let lastMessageId = parseInt(container.getAttribute('data-last-id'), 10) || 0;

    const MAX_CHARS = 5000;
    const POLL_INTERVAL_MS = 10000;
    let pollTimer = null;
    let isPolling = false;
    let isSubmitting = false;

    // Scroll container to bottom
    const scrollToBottom = () => {
        container.scrollTop = container.scrollHeight;
    };
    scrollToBottom();

    // Character counter with Unicode code point support
    const updateCharCount = () => {
        if (!textarea || !charCounter) return;
        const count = [...textarea.value].length;
        const remaining = MAX_CHARS - count;
        charCounter.textContent = `${remaining.toLocaleString()} characters remaining`;
        if (remaining < 0) {
            charCounter.style.color = '#dc2626';
        } else {
            charCounter.style.color = '#64748b';
        }
    };

    if (textarea && charCounter) {
        textarea.addEventListener('input', updateCharCount);
        updateCharCount();

        // Submit on Enter (without Shift)
        textarea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (form && !isSubmitting && textarea.value.trim().length > 0) {
                    form.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }
        });
    }

    // Quick Emojis
    const emojiBtns = document.querySelectorAll('.gh-emoji-btn');
    emojiBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!textarea) return;
            const emoji = btn.getAttribute('data-emoji') || btn.textContent.trim();
            const start = textarea.selectionStart ?? textarea.value.length;
            const end = textarea.selectionEnd ?? textarea.value.length;
            const before = textarea.value.substring(0, start);
            const after = textarea.value.substring(end);

            textarea.value = before + emoji + after;
            const newCursor = start + emoji.length;
            textarea.selectionStart = newCursor;
            textarea.selectionEnd = newCursor;
            textarea.focus();
            updateCharCount();
        });
    });

    // Copy Message Functionality (Event Delegation)
    container.addEventListener('click', async (e) => {
        const btn = e.target.closest('.gh-message-copy-btn');
        if (!btn) return;

        const row = btn.closest('.gh-message-row');
        if (!row) return;

        const bubble = row.querySelector('.gh-message-bubble');
        if (!bubble) return;

        const textToCopy = bubble.textContent;
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                await navigator.clipboard.writeText(textToCopy);
            } else {
                const tempTextArea = document.createElement('textarea');
                tempTextArea.value = textToCopy;
                tempTextArea.style.position = 'fixed';
                tempTextArea.style.opacity = '0';
                document.body.appendChild(tempTextArea);
                tempTextArea.select();
                document.execCommand('copy');
                document.body.removeChild(tempTextArea);
            }

            const originalText = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('gh-copied');
            setTimeout(() => {
                btn.textContent = originalText;
                btn.classList.remove('gh-copied');
            }, 2000);
        } catch (_) {
            btn.textContent = 'Failed';
            setTimeout(() => {
                btn.textContent = 'Copy';
            }, 2000);
        }
    });

    // Append a message securely to DOM using safe DOM text APIs
    const appendMessage = (msg) => {
        if (container.querySelector(`[data-message-id="${msg.id}"]`)) {
            return; // Duplicate guard
        }

        if (emptyNotice && emptyNotice.parentNode) {
            emptyNotice.parentNode.removeChild(emptyNotice);
        }

        const isMe = msg.is_me || (msg.sender_id === myUserId);
        const row = document.createElement('div');
        row.className = `gh-message-row ${isMe ? 'gh-message-me' : 'gh-message-them'}`;
        row.setAttribute('data-message-id', msg.id);

        const meta = document.createElement('div');
        meta.className = 'gh-message-meta';

        const senderSpan = document.createElement('span');
        senderSpan.className = 'gh-message-sender';
        senderSpan.textContent = msg.sender_name;

        const timeElem = document.createElement('time');
        timeElem.className = 'gh-message-time';
        timeElem.setAttribute('datetime', msg.created_at);
        timeElem.textContent = msg.formatted_time;

        const copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'gh-message-copy-btn';
        copyBtn.setAttribute('aria-label', 'Copy message text');
        copyBtn.title = 'Copy message';
        copyBtn.textContent = 'Copy';

        meta.appendChild(senderSpan);
        meta.appendChild(timeElem);
        meta.appendChild(copyBtn);

        const bubble = document.createElement('div');
        bubble.className = 'gh-message-bubble';
        bubble.textContent = msg.body;

        row.appendChild(meta);
        row.appendChild(bubble);
        container.appendChild(row);

        scrollToBottom();
    };

    // Acknowledge read state for incoming messages
    const acknowledgeRead = async (messageId) => {
        if (!messageId || document.visibilityState !== 'visible') {
            return;
        }

        try {
            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('bid_id', bidId);
            formData.append('message_id', messageId);

            const res = await fetch('?route=/messages/read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData.toString()
            });

            if (res.ok) {
                // Dispatch event so global navigation immediately updates its badge
                window.dispatchEvent(new CustomEvent('gh:messages-read', { detail: { bidId, messageId } }));
            }
        } catch (_) {
            // Silently tolerate background read acknowledgement errors
        }
    };

    // Initial read acknowledgement on page load if thread contains messages
    if (lastMessageId > 0 && document.visibilityState === 'visible') {
        acknowledgeRead(lastMessageId);
    }

    // Polling function
    const pollMessages = async () => {
        if (isPolling || document.visibilityState !== 'visible' || !bidId) {
            return;
        }

        isPolling = true;
        try {
            const res = await fetch(`?route=/messages/poll&bid_id=${bidId}&since_id=${lastMessageId}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (res.status === 401 || res.status === 403) {
                // Session expired or access revoked, stop polling
                stopPolling();
                return;
            }

            if (res.ok) {
                const data = await res.json();
                if (data.messages && Array.isArray(data.messages) && data.messages.length > 0) {
                    let maxThemId = 0;
                    data.messages.forEach((msg) => {
                        appendMessage(msg);
                        if (!msg.is_me && msg.sender_id !== myUserId) {
                            maxThemId = Math.max(maxThemId, msg.id);
                        }
                    });

                    if (data.last_id) {
                        lastMessageId = Math.max(lastMessageId, data.last_id);
                    }

                    if (maxThemId > 0) {
                        acknowledgeRead(maxThemId);
                    }
                }
            }
        } catch (_) {
            // Network failure or offline, will retry on next poll interval
        } finally {
            isPolling = false;
        }
    };

    // Start / Stop Poller
    const startPolling = () => {
        if (!pollTimer) {
            pollTimer = setInterval(pollMessages, POLL_INTERVAL_MS);
        }
    };

    const stopPolling = () => {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    };

    // Page visibility event listener
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (lastMessageId > 0) {
                acknowledgeRead(lastMessageId);
            }
            pollMessages();
            startPolling();
        } else {
            stopPolling();
        }
    });

    startPolling();

    // AJAX Message Submission
    if (form && textarea && sendBtn) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const text = textarea.value.trim();
            if (!text || isSubmitting) {
                return;
            }

            isSubmitting = true;
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';

            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('bid_id', bidId);
            formData.append('body', text);

            try {
                const res = await fetch('?route=/messages/send', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData.toString()
                });

                const data = await res.json();

                if (res.status === 201 && data.success && data.message) {
                    textarea.value = '';
                    updateCharCount();
                    appendMessage(data.message);
                    if (data.message.id) {
                        lastMessageId = Math.max(lastMessageId, data.message.id);
                    }
                    textarea.focus();
                } else {
                    const errorMsg = (data && data.error) ? data.error : 'Could not send message. Please try again.';
                    alert(errorMsg);
                }
            } catch (_) {
                alert('A network error occurred while sending your message. Please try again.');
            } finally {
                isSubmitting = false;
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send Message';
            }
        });
    }
})();

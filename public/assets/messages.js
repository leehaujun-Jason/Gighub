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
    const emptyNotice = document.getElementById('gh-thread-empty');
    const dialog = document.getElementById('gh-chat-dialog');
    const closeLink = dialog?.querySelector('.gh-chat-close');
    let openPanel = null;
    let pickerActive = false;
    let suppressCancelUntil = 0;
    const closePanel = (restore = false) => {
        if (!openPanel) return;
        const {button, panel} = openPanel;
        openPanel = null;
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        if (restore) button.focus();
    };
    if (dialog && closeLink && typeof dialog.showModal === 'function') {
        dialog.removeAttribute('open');
        dialog.showModal();
        const maximize = document.getElementById('gh-chat-maximize');
        maximize.hidden = false;
        maximize.onclick = () => {
            const expanded = dialog.classList.toggle('gh-chat-maximized');
            maximize.setAttribute('aria-pressed', String(expanded));
            maximize.setAttribute('aria-label', expanded ? 'Restore dialog size' : 'Maximize conversation');
        };
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            if (pickerActive || performance.now() < suppressCancelUntil) return;
            if (openPanel) {
                closePanel(true);
                return;
            }
            window.location.assign(closeLink.href);
        });
    }
    const imageDialog = document.getElementById('gh-image-dialog');
    if (imageDialog && typeof imageDialog.showModal === 'function') {
        const image = imageDialog.querySelector('img');
        let trigger;
        container.addEventListener('click', (event) => {
            const link = event.target.closest('[data-image-preview]');
            if (!link) return;
            event.preventDefault();
            trigger = link;
            image.src = link.href;
            image.alt = link.querySelector('img').alt;
            imageDialog.showModal();
        });
        imageDialog.querySelector('button').onclick = () => imageDialog.close();
        imageDialog.addEventListener('click', (event) => {
            if (event.target === imageDialog) imageDialog.close();
        });
        imageDialog.addEventListener('close', () => {
            image.removeAttribute('src');
            trigger?.focus({preventScroll: true});
        });
    }

    const renderLinks = (bubble, text) => {
        bubble.replaceChildren();
        let offset = 0;
        for (const match of text.matchAll(/(?<![\w:/])https?:\/\/[^\s<>"']+/giu)) {
            const candidate = match[0].replace(/[.,!?;:)\]]+$/u, '');
            try {
                const url = new URL(candidate);
                if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) continue;
                bubble.append(document.createTextNode(text.slice(offset, match.index)));
                const link = document.createElement('a');
                link.href = url.href;
                link.textContent = candidate;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                bubble.append(link);
                offset = match.index + candidate.length;
            } catch (_) {
                // Leave malformed URLs as ordinary text.
            }
        }
        bubble.append(document.createTextNode(text.slice(offset)));
    };
    container.querySelectorAll('.gh-message-text').forEach((body) => renderLinks(body, body.textContent));

    const bidId = parseInt(container.getAttribute('data-bid-id'), 10);
    const csrfToken = container.getAttribute('data-csrf') || '';
    const myUserId = parseInt(container.getAttribute('data-user-id'), 10);
    let lastMessageId = parseInt(container.getAttribute('data-last-id'), 10) || 0;

    const MAX_CHARS = 5000;
    const POLL_INTERVAL_MS = 10000;
    let pollTimer = null;
    let isPolling = false;
    let isSubmitting = false;
    const picker = document.getElementById('gh-attachment-input');
    const tools = document.querySelector('.gh-composer-popovers');
    const preview = document.getElementById('gh-attachment-preview');
    const errorNotice = document.getElementById('gh-message-error');
    const pending = [];
    let mode = 'image';
    const showError = (message = '') => {
        errorNotice.textContent = message;
        errorNotice.hidden = !message;
    };
    const scrollToBottom = () => { container.scrollTop = container.scrollHeight; };
    scrollToBottom();

    const updateComposer = () => {
        if (!textarea) return;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
        sendBtn.disabled = isSubmitting || (!textarea.value.trim() && !pending.length)
            || [...textarea.value].length > MAX_CHARS;
    };
    const clearAttachment = () => {
        for (const item of pending.splice(0)) if (item.url) URL.revokeObjectURL(item.url);
        preview.replaceChildren();
        picker.value = '';
    };

    if (tools && picker) {
        tools.hidden = false;
        for (const button of tools.querySelectorAll('[aria-controls]')) {
            button.onclick = () => {
                const wasOpen = openPanel?.button === button;
                closePanel();
                if (wasOpen) return;
                const panel = document.getElementById(button.getAttribute('aria-controls'));
                panel.hidden = false;
                openPanel = {button, panel};
                button.setAttribute('aria-expanded', 'true');
                panel.querySelector('button').focus();
            };
        }
        document.addEventListener('pointerdown', (event) => {
            if (openPanel && !tools.contains(event.target)) closePanel();
        });
        const finishPicker = () => {
            if (!pickerActive) return;
            pickerActive = false;
            suppressCancelUntil = performance.now() + 250;
            textarea.focus();
        };
        picker.addEventListener('cancel', finishPicker);
        window.addEventListener('focus', () => {
            if (pickerActive) setTimeout(finishPicker, 250);
        });
        const allowed = {image: '.jpg,.jpeg,.png,.webp', file: '.pdf,.docx,.xlsx,.txt,.csv'};
        tools.querySelectorAll('[data-attachment]').forEach((button) => {
            button.addEventListener('click', () => {
                if (isSubmitting) return;
                mode = button.dataset.attachment;
                picker.accept = allowed[mode];
                picker.value = '';
                closePanel();
                pickerActive = true;
                picker.click();
            });
        });
        picker.addEventListener('change', () => {
            finishPicker();
            if (isSubmitting) return;
            showError();
            for (const file of picker.files) {
                const extension = '.' + file.name.split('.').pop().toLowerCase();
                if (!allowed[mode].split(',').includes(extension) || !file.size || file.size > (mode === 'image' ? 5 : 10) * 1024 * 1024) {
                    showError('Choose a supported image up to 5 MB or document up to 10 MB.');
                    continue;
                }
                if (pending.some(({file: other}) => other.name === file.name && other.size === file.size && other.lastModified === file.lastModified)) continue;
                if (pending.length >= 5) {
                    showError('Choose at most five attachments.');
                    break;
                }
                const item = {file, url: mode === 'image' ? URL.createObjectURL(file) : null};
                pending.push(item);
                const card = document.createElement('div');
                card.className = 'gh-chat-preview';
                if (item.url) {
                    const img = document.createElement('img');
                    img.src = item.url;
                    img.alt = '';
                    card.append(img);
                }
                if (!item.url) card.append(document.createTextNode('📄 ' + file.name));
                else card.classList.add('gh-chat-preview-image');
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove ' + file.name);
                const trigger = tools.querySelector('[aria-controls="gh-files-menu"]');
                remove.onclick = () => {
                    if (isSubmitting) return;
                    if (item.url) URL.revokeObjectURL(item.url);
                    pending.splice(pending.indexOf(item), 1);
                    card.remove();
                    trigger.focus();
                    updateComposer();
                };
                card.append(remove);
                preview.append(card);
            }
            picker.value = '';
            updateComposer();
        });
        window.addEventListener('pagehide', (event) => { if (!event.persisted) clearAttachment(); });
    }

    if (textarea) {
        let accepted = textarea.value;
        textarea.addEventListener('input', (event) => {
            if (event.isComposing) return;
            if ([...textarea.value].length > MAX_CHARS) {
                textarea.value = accepted;
                showError('Messages can contain up to 5,000 characters.');
            } else {
                accepted = textarea.value;
            }
            updateComposer();
        });
        textarea.addEventListener('compositionend', () => textarea.dispatchEvent(new Event('input')));
        textarea.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                if (!sendBtn.disabled) form.requestSubmit();
            }
        });
        updateComposer();
    }

    // Quick Emojis
    const emojiBtns = document.querySelectorAll('.gh-emoji-btn');
    emojiBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!textarea || isSubmitting) return;
            const emoji = btn.getAttribute('data-emoji') || btn.textContent.trim();
            const start = textarea.selectionStart ?? textarea.value.length;
            const end = textarea.selectionEnd ?? textarea.value.length;
            const before = textarea.value.substring(0, start);
            const after = textarea.value.substring(end);

            if ([...(before + emoji + after)].length > MAX_CHARS) {
                showError('Messages can contain up to 5,000 characters.');
                return;
            }
            closePanel();
            textarea.value = before + emoji + after;
            const newCursor = start + emoji.length;
            textarea.selectionStart = newCursor;
            textarea.selectionEnd = newCursor;
            textarea.focus();
            textarea.dispatchEvent(new Event('input'));
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

        const textToCopy = bubble.querySelector('.gh-message-text')?.textContent ?? '';
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
        const body = document.createElement('span');
        body.className = 'gh-message-text';
        renderLinks(body, msg.body);
        bubble.append(body);
        const imageGrid = document.createElement('div');
        imageGrid.className = 'gh-chat-image-grid';
        const documents = document.createElement('div');
        documents.className = 'gh-chat-document-list';
        for (const item of msg.attachments ?? []) {
            const url = '?route=/messages/attachment&bid_id=' + bidId + '&id=' + Number(msg.id) + (item.id === null ? '' : '&attachment_id=' + Number(item.id));
            const link = document.createElement('a');
            link.className = 'gh-chat-attachment';
            link.href = url;
            if (item.image) {
                link.dataset.imagePreview = '';
                const image = document.createElement('img');
                image.src = url;
                image.alt = 'Open image: ' + item.name;
                link.append(image);
            } else {
                link.download = '';
                link.textContent = '📄 ' + item.name;
            }
            (item.image ? imageGrid : documents).append(link);
        }
        bubble.append(imageGrid, documents);

        row.appendChild(meta);
        row.appendChild(bubble);
        container.appendChild(row);

        if (!document.getElementById('gh-image-dialog')?.open) {
            scrollToBottom();
        }
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
            if ((!text && !pending.length) || isSubmitting || [...textarea.value].length > MAX_CHARS) {
                return;
            }

            isSubmitting = true;
            sendBtn.disabled = true;
            textarea.readOnly = true;
            showError();

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('bid_id', bidId);
            formData.append('body', text);
            for (const {file} of pending) formData.append('attachments[]', file);

            try {
                const res = await fetch('?route=/messages/send', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                if (res.status === 413) {
                    showError('This upload exceeds the server request size limit.');
                    return;
                }
                const data = await res.json();

                if (res.status === 201 && data.success && data.message) {
                    textarea.value = '';
                    clearAttachment();
                    textarea.dispatchEvent(new Event('input'));
                    appendMessage(data.message);
                    textarea.focus();
                } else {
                    const errorMsg = (data && data.error) ? data.error : 'Could not send message. Please try again.';
                    showError(errorMsg);
                }
            } catch (_) {
                showError('Could not confirm delivery. Check the conversation before retrying.');
            } finally {
                isSubmitting = false;
                textarea.readOnly = false;
                textarea.dispatchEvent(new Event('input'));
            }
        });
    }
})();

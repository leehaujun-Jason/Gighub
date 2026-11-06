'use strict';
/**
 * GigHub - Freelancer Workspace Script
 * Handles the "Update Your Skills" modal dialog, live search filter,
 * checkbox selection counter, and accessible modal state management.
 * Compliant with Content Security Policy (default-src 'self').
 */
(() => {
    function initSkillsModal() {
        const modal = document.getElementById('gh-skills-modal');
        if (!modal) {
            return;
        }

        const openBtns = document.querySelectorAll(
            '#gh-open-skills-modal, #gh-open-skills-modal-empty, #gh-open-skills-modal-rec'
        );
        const closeBtn = document.getElementById('gh-modal-close-btn');
        const cancelBtn = document.getElementById('gh-skills-cancel-btn');
        const searchInput = document.getElementById('gh-skill-search-input');
        const counter = document.getElementById('gh-selected-counter');
        const skillsList = document.getElementById('gh-modal-skills-list');
        const noMatchMsg = document.getElementById('gh-modal-no-filter-match');

        let initialCheckedMap = {};
        let lastFocused = null;

        function saveInitialState() {
            initialCheckedMap = {};
            const checkboxes = modal.querySelectorAll('input[type="checkbox"][name="skills[]"]');
            checkboxes.forEach((cb) => {
                initialCheckedMap[cb.value] = cb.checked;
            });
        }

        function restoreInitialState() {
            const checkboxes = modal.querySelectorAll('input[type="checkbox"][name="skills[]"]');
            checkboxes.forEach((cb) => {
                cb.checked = !!initialCheckedMap[cb.value];
            });
            updateCounter();
            resetFilter();
        }

        function updateCounter() {
            const checked = modal.querySelectorAll('input[type="checkbox"][name="skills[]"]:checked').length;
            if (counter) {
                counter.innerHTML = '<strong>' + checked + '</strong> selected';
            }
        }

        function resetFilter() {
            if (searchInput) {
                searchInput.value = '';
            }
            const items = modal.querySelectorAll('.gh-modal-skill-item');
            items.forEach((item) => {
                item.style.display = '';
            });
            if (noMatchMsg) {
                noMatchMsg.style.display = 'none';
            }
        }

        function openModal(trigger) {
            lastFocused = trigger || null;
            saveInitialState();
            updateCounter();
            resetFilter();

            if (typeof modal.showModal === 'function') {
                try {
                    modal.showModal();
                } catch (_) {
                    modal.setAttribute('open', '');
                }
            } else {
                modal.setAttribute('open', '');
            }

            if (searchInput) {
                setTimeout(() => {
                    searchInput.focus();
                }, 60);
            }
        }

        function closeModal() {
            if (typeof modal.close === 'function') {
                try {
                    modal.close();
                } catch (_) {
                    modal.removeAttribute('open');
                }
            } else {
                modal.removeAttribute('open');
            }
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        // Attach global helpers for compatibility or testing if needed
        window.ghOpenSkillsModal = openModal;
        window.ghCloseSkillsModal = closeModal;

        // Wire open buttons
        openBtns.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                openModal(btn);
            });
        });

        // Wire close button (X)
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                restoreInitialState();
                closeModal();
            });
        }

        // Wire cancel button
        if (cancelBtn) {
            cancelBtn.addEventListener('click', (e) => {
                e.preventDefault();
                restoreInitialState();
                closeModal();
            });
        }

        // Backdrop click dismissal: outside modal card
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                restoreInitialState();
                closeModal();
                return;
            }
            const card = modal.querySelector('.gh-modal-card');
            if (card && !card.contains(e.target)) {
                restoreInitialState();
                closeModal();
            }
        });

        // Native dialog cancel event (Escape key)
        modal.addEventListener('cancel', () => {
            restoreInitialState();
            if (lastFocused && typeof lastFocused.focus === 'function') {
                setTimeout(() => {
                    lastFocused.focus();
                }, 10);
            }
        });

        // Fallback keydown listener for Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.hasAttribute('open')) {
                restoreInitialState();
                closeModal();
            }
        });

        // Delegation for skill checkbox changes
        if (skillsList) {
            skillsList.addEventListener('change', (e) => {
                if (e.target && e.target.type === 'checkbox') {
                    updateCounter();
                }
            });
        }

        // Real-time live search filter
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim().toLowerCase();
                const items = modal.querySelectorAll('.gh-modal-skill-item');
                let visibleCount = 0;
                items.forEach((item) => {
                    const name = (item.getAttribute('data-skill-name') || '').toLowerCase();
                    if (q === '' || name.includes(q)) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                if (noMatchMsg) {
                    noMatchMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSkillsModal);
    } else {
        initSkillsModal();
    }
})();

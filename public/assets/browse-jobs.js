'use strict';
/**
 * GigHub - Browse Jobs Client Engine
 * Handles debounced real-time search, Filter modal state management,
 * Sort menu selection, Guest login prompt modal, and DOMParser AJAX navigation.
 * Compliant with Content Security Policy (default-src 'self').
 */
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const browseForm = document.getElementById('gh-browse-form');
        const searchInput = document.getElementById('q');
        const resultsContainer = document.getElementById('browse-results');
        const countDisplay = document.getElementById('browse-count');
        const sortLabelDisplay = document.getElementById('browse-sort-label');

        // Hidden form inputs
        const inputPage = document.getElementById('gh-input-page');
        const inputMinBudget = document.getElementById('gh-input-min-budget');
        const inputMaxBudget = document.getElementById('gh-input-max-budget');
        const inputSort = document.getElementById('gh-input-sort');
        const hiddenSkillsContainer = document.getElementById('gh-hidden-skills-container');

        // Filter button & badge
        const openFilterBtn = document.getElementById('gh-open-filter-modal');
        const filterBadge = document.getElementById('gh-filter-badge');

        // Filter modal elements
        const filterModal = document.getElementById('gh-filter-modal');
        const filterCloseBtn = document.getElementById('gh-filter-close-btn');
        const filterCancelBtn = document.getElementById('gh-filter-cancel-btn');
        const filterApplyBtn = document.getElementById('gh-filter-apply-btn');
        const filterClearAllBtn = document.getElementById('gh-filter-clear-all-btn');
        const modalMinBudget = document.getElementById('modal-min-budget');
        const modalMaxBudget = document.getElementById('modal-max-budget');
        const modalBudgetError = document.getElementById('modal-budget-error');
        const modalSkillsCounter = document.getElementById('modal-skills-counter');
        const modalSkillsError = document.getElementById('modal-skills-error');

        // Sort menu elements
        const sortBtn = document.getElementById('gh-sort-btn');
        const sortMenu = document.getElementById('gh-sort-menu');

        // Guest modal elements
        const guestModal = document.getElementById('gh-guest-login-modal');
        const guestCloseBtn = document.getElementById('gh-guest-close-btn');
        const guestCancelBtn = document.getElementById('gh-guest-cancel-btn');

        // State trackers
        let debounceTimer = null;
        let activeAbortController = null;
        let latestRequestId = 0;
        let initialFilterModalState = null;
        let lastFocusedElement = null;

        const SORT_LABELS = {
            'newest': 'Newest first',
            'oldest': 'Oldest first',
            'budget_high': 'Highest first',
            'budget_low': 'Lowest first'
        };

        // -----------------------------------------------------------------
        // Core Fetch & DOM Update Engine
        // -----------------------------------------------------------------
        function buildQueryString(pageOverride) {
            const params = new URLSearchParams();
            params.set('route', '/jobs');

            const qVal = searchInput ? searchInput.value.trim() : '';
            if (qVal !== '') {
                params.set('q', qVal);
            }

            const minB = inputMinBudget ? inputMinBudget.value.trim() : '';
            if (minB !== '') {
                params.set('min_budget', minB);
            }

            const maxB = inputMaxBudget ? inputMaxBudget.value.trim() : '';
            if (maxB !== '') {
                params.set('max_budget', maxB);
            }

            const sortVal = inputSort ? inputSort.value.trim() : 'newest';
            if (sortVal !== '') {
                params.set('sort', sortVal);
            }

            const pageVal = pageOverride !== undefined ? pageOverride : (inputPage ? parseInt(inputPage.value, 10) || 1 : 1);
            if (pageVal > 1) {
                params.set('page', String(pageVal));
            }

            if (hiddenSkillsContainer) {
                const skillInputs = hiddenSkillsContainer.querySelectorAll('input[type="hidden"][name="skills[]"]');
                skillInputs.forEach(input => {
                    params.append('skills[]', input.value);
                });
            }

            return params.toString();
        }

        async function fetchJobs(queryString, shouldPushState) {
            if (activeAbortController) {
                activeAbortController.abort();
            }
            activeAbortController = new AbortController();
            const currentReqId = ++latestRequestId;

            if (resultsContainer) {
                resultsContainer.classList.add('is-loading');
                resultsContainer.setAttribute('aria-busy', 'true');
            }

            const targetUrl = '?' + queryString;

            try {
                const response = await fetch(targetUrl, {
                    signal: activeAbortController.signal,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (currentReqId !== latestRequestId) {
                    return; // Ignore stale response
                }

                if (!response.ok && response.status !== 422) {
                    throw new Error('Network response not ok: ' + response.status);
                }

                const htmlText = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(htmlText, 'text/html');

                const newResults = doc.getElementById('browse-results');
                if (newResults && resultsContainer) {
                    resultsContainer.innerHTML = newResults.innerHTML;
                }

                const newCount = doc.getElementById('browse-count');
                if (newCount && countDisplay) {
                    countDisplay.textContent = newCount.textContent;
                }

                const newSortLabel = doc.getElementById('browse-sort-label');
                if (newSortLabel && sortLabelDisplay) {
                    sortLabelDisplay.textContent = newSortLabel.textContent;
                }

                // Synchronize browser history
                if (shouldPushState) {
                    window.history.pushState(null, '', targetUrl);
                } else if (shouldPushState === false) {
                    window.history.replaceState(null, '', targetUrl);
                }

            } catch (err) {
                if (err.name === 'AbortError') {
                    return;
                }
                if (resultsContainer) {
                    resultsContainer.innerHTML = '<div class="browse-empty" role="alert"><h2>Could not load jobs</h2><p>A network error occurred while searching. Please check your connection and try again.</p></div>';
                }
            } finally {
                if (currentReqId === latestRequestId && resultsContainer) {
                    resultsContainer.classList.remove('is-loading');
                    resultsContainer.setAttribute('aria-busy', 'false');
                }
            }
        }

        // -----------------------------------------------------------------
        // Real-Time Search with Debounce
        // -----------------------------------------------------------------
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                if (debounceTimer) {
                    clearTimeout(debounceTimer);
                }
                debounceTimer = setTimeout(() => {
                    if (inputPage) {
                        inputPage.value = '1';
                    }
                    const qs = buildQueryString(1);
                    fetchJobs(qs, false); // replaceState on typing
                }, 350);
            });

            // Prevent full-page form submission when user hits Enter
            if (browseForm) {
                browseForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (debounceTimer) {
                        clearTimeout(debounceTimer);
                    }
                    if (inputPage) {
                        inputPage.value = '1';
                    }
                    const qs = buildQueryString(1);
                    fetchJobs(qs, true);
                });
            }
        }

        // -----------------------------------------------------------------
        // Sort Dropdown Menu
        // -----------------------------------------------------------------
        function toggleSortMenu(open) {
            if (!sortMenu || !sortBtn) return;
            const isOpen = open !== undefined ? open : sortMenu.hasAttribute('hidden');
            if (isOpen) {
                sortMenu.removeAttribute('hidden');
                sortBtn.setAttribute('aria-expanded', 'true');
            } else {
                sortMenu.setAttribute('hidden', '');
                sortBtn.setAttribute('aria-expanded', 'false');
            }
        }

        if (sortBtn && sortMenu) {
            sortBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleSortMenu();
            });

            document.addEventListener('click', (e) => {
                if (!sortMenu.contains(e.target) && !sortBtn.contains(e.target)) {
                    toggleSortMenu(false);
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !sortMenu.hasAttribute('hidden')) {
                    toggleSortMenu(false);
                    sortBtn.focus();
                }
            });

            const sortRadios = sortMenu.querySelectorAll('input[type="radio"][name="sort_option"]');
            sortRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    if (radio.checked) {
                        const newSort = radio.value;
                        if (inputSort) {
                            inputSort.value = newSort;
                        }
                        if (sortLabelDisplay) {
                            sortLabelDisplay.textContent = SORT_LABELS[newSort] || 'Newest first';
                        }
                        toggleSortMenu(false);
                        if (inputPage) {
                            inputPage.value = '1';
                        }
                        const qs = buildQueryString(1);
                        fetchJobs(qs, true);
                    }
                });
            });
        }

        // -----------------------------------------------------------------
        // Filter Modal Logic & Validation
        // -----------------------------------------------------------------
        function updateModalSkillsCounter() {
            if (!filterModal || !modalSkillsCounter) return;
            const checkedCount = filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]:checked').length;
            modalSkillsCounter.innerHTML = '<strong>' + checkedCount + '</strong>/10 selected';
        }

        function updateFilterBadge() {
            if (!filterBadge) return;
            const minB = inputMinBudget ? inputMinBudget.value.trim() : '';
            const maxB = inputMaxBudget ? inputMaxBudget.value.trim() : '';
            const skillsCount = hiddenSkillsContainer ? hiddenSkillsContainer.querySelectorAll('input').length : 0;
            const totalActive = (minB !== '' ? 1 : 0) + (maxB !== '' ? 1 : 0) + skillsCount;

            if (totalActive > 0) {
                filterBadge.textContent = '(' + totalActive + ')';
                filterBadge.classList.remove('gh-hidden');
                if (openFilterBtn) openFilterBtn.classList.add('is-active');
            } else {
                filterBadge.textContent = '';
                filterBadge.classList.add('gh-hidden');
                if (openFilterBtn) openFilterBtn.classList.remove('is-active');
            }
        }

        function saveInitialFilterModalState() {
            const checkedSkills = [];
            if (filterModal) {
                filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]:checked').forEach(cb => {
                    checkedSkills.push(cb.value);
                });
            }
            initialFilterModalState = {
                min: modalMinBudget ? modalMinBudget.value : '',
                max: modalMaxBudget ? modalMaxBudget.value : '',
                skills: checkedSkills
            };
        }

        function restoreFilterModalState() {
            if (!initialFilterModalState || !filterModal) return;
            if (modalMinBudget) modalMinBudget.value = initialFilterModalState.min;
            if (modalMaxBudget) modalMaxBudget.value = initialFilterModalState.max;
            filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]').forEach(cb => {
                cb.checked = initialFilterModalState.skills.includes(cb.value);
            });
            if (modalBudgetError) {
                modalBudgetError.textContent = '';
                modalBudgetError.style.display = 'none';
            }
            if (modalSkillsError) {
                modalSkillsError.textContent = '';
                modalSkillsError.style.display = 'none';
            }
            updateModalSkillsCounter();
        }

        function openFilterModalDialog() {
            if (!filterModal) return;
            lastFocusedElement = openFilterBtn || null;
            saveInitialFilterModalState();
            updateModalSkillsCounter();

            if (typeof filterModal.showModal === 'function') {
                try {
                    filterModal.showModal();
                } catch (_) {
                    filterModal.setAttribute('open', '');
                }
            } else {
                filterModal.setAttribute('open', '');
            }
            document.body.classList.add('gh-modal-open');

            if (modalMinBudget) {
                setTimeout(() => { modalMinBudget.focus(); }, 60);
            }
        }

        function closeFilterModalDialog(save) {
            if (!filterModal) return;
            if (!save) {
                restoreFilterModalState();
            }
            if (typeof filterModal.close === 'function') {
                try {
                    filterModal.close();
                } catch (_) {
                    filterModal.removeAttribute('open');
                }
            } else {
                filterModal.removeAttribute('open');
            }
            document.body.classList.remove('gh-modal-open');
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        if (openFilterBtn) {
            openFilterBtn.addEventListener('click', () => {
                openFilterModalDialog();
            });
        }

        if (filterCloseBtn) {
            filterCloseBtn.addEventListener('click', () => {
                closeFilterModalDialog(false);
            });
        }

        if (filterCancelBtn) {
            filterCancelBtn.addEventListener('click', () => {
                closeFilterModalDialog(false);
            });
        }

        if (filterModal) {
            filterModal.addEventListener('cancel', (e) => {
                e.preventDefault();
                closeFilterModalDialog(false);
            });

            // Prevent exceeding 10 selected skills
            filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]').forEach(cb => {
                cb.addEventListener('change', () => {
                    const checked = filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]:checked');
                    if (checked.length > 10) {
                        cb.checked = false;
                        if (modalSkillsError) {
                            modalSkillsError.textContent = 'Choose up to 10 distinct catalogue skills.';
                            modalSkillsError.style.display = 'block';
                        }
                    } else if (modalSkillsError) {
                        modalSkillsError.textContent = '';
                        modalSkillsError.style.display = 'none';
                    }
                    updateModalSkillsCounter();
                });
            });

            // Clear all modal inputs
            if (filterClearAllBtn) {
                filterClearAllBtn.addEventListener('click', () => {
                    if (modalMinBudget) modalMinBudget.value = '';
                    if (modalMaxBudget) modalMaxBudget.value = '';
                    filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]').forEach(cb => {
                        cb.checked = false;
                    });
                    if (modalBudgetError) {
                        modalBudgetError.textContent = '';
                        modalBudgetError.style.display = 'none';
                    }
                    if (modalSkillsError) {
                        modalSkillsError.textContent = '';
                        modalSkillsError.style.display = 'none';
                    }
                    updateModalSkillsCounter();
                });
            }

            // Apply Filters button
            if (filterApplyBtn) {
                filterApplyBtn.addEventListener('click', () => {
                    if (modalBudgetError) {
                        modalBudgetError.textContent = '';
                        modalBudgetError.style.display = 'none';
                    }

                    // Budget Validation matching JobBrowseValidator without floating-point inaccuracies
                    const minStr = modalMinBudget ? modalMinBudget.value.trim() : '';
                    const maxStr = modalMaxBudget ? modalMaxBudget.value.trim() : '';
                    const moneyRegex = /^(?:[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/;

                    function padDecimal(str) {
                        const parts = str.split('.');
                        const whole = parts[0].padStart(9, '0');
                        const frac = (parts[1] || '').padEnd(2, '0').slice(0, 2);
                        return whole + '.' + frac;
                    }

                    if (minStr !== '') {
                        if (!moneyRegex.test(minStr) || padDecimal(minStr) < '000000100.00') {
                            modalBudgetError.textContent = 'Minimum budget must be at least RM100.';
                            modalBudgetError.style.display = 'block';
                            if (modalMinBudget) modalMinBudget.focus();
                            return;
                        }
                        if (padDecimal(minStr) > '100000000.00') {
                            modalBudgetError.textContent = 'Minimum budget cannot exceed RM100,000,000.';
                            modalBudgetError.style.display = 'block';
                            if (modalMinBudget) modalMinBudget.focus();
                            return;
                        }
                    }

                    if (maxStr !== '') {
                        if (!moneyRegex.test(maxStr) || padDecimal(maxStr) > '100000000.00') {
                            modalBudgetError.textContent = 'Maximum budget cannot exceed RM100,000,000.';
                            modalBudgetError.style.display = 'block';
                            if (modalMaxBudget) modalMaxBudget.focus();
                            return;
                        }
                    }

                    if (minStr !== '' && maxStr !== '') {
                        if (padDecimal(minStr) > padDecimal(maxStr)) {
                            modalBudgetError.textContent = 'Minimum budget cannot exceed maximum budget.';
                            modalBudgetError.style.display = 'block';
                            if (modalMaxBudget) modalMaxBudget.focus();
                            return;
                        }
                    }

                    // Commit changes to hidden form
                    if (inputMinBudget) inputMinBudget.value = minStr;
                    if (inputMaxBudget) inputMaxBudget.value = maxStr;

                    if (hiddenSkillsContainer) {
                        hiddenSkillsContainer.innerHTML = '';
                        const checkedSkills = filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]:checked');
                        checkedSkills.forEach(cb => {
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = 'skills[]';
                            hiddenInput.value = cb.value;
                            hiddenSkillsContainer.appendChild(hiddenInput);
                        });
                    }

                    updateFilterBadge();
                    closeFilterModalDialog(true);

                    // Reset page to 1 and trigger search
                    if (inputPage) {
                        inputPage.value = '1';
                    }
                    const qs = buildQueryString(1);
                    fetchJobs(qs, true);
                });
            }
        }

        // -----------------------------------------------------------------
        // Guest Login Prompt Modal
        // -----------------------------------------------------------------
        function openGuestModal(triggerBtn) {
            if (!guestModal) return;
            lastFocusedElement = triggerBtn || null;
            if (typeof guestModal.showModal === 'function') {
                try {
                    guestModal.showModal();
                } catch (_) {
                    guestModal.setAttribute('open', '');
                }
            } else {
                guestModal.setAttribute('open', '');
            }
            document.body.classList.add('gh-modal-open');
        }

        function closeGuestModal() {
            if (!guestModal) return;
            if (typeof guestModal.close === 'function') {
                try {
                    guestModal.close();
                } catch (_) {
                    guestModal.removeAttribute('open');
                }
            } else {
                guestModal.removeAttribute('open');
            }
            document.body.classList.remove('gh-modal-open');
            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        if (guestCloseBtn) {
            guestCloseBtn.addEventListener('click', closeGuestModal);
        }
        if (guestCancelBtn) {
            guestCancelBtn.addEventListener('click', closeGuestModal);
        }
        if (guestModal) {
            guestModal.addEventListener('cancel', (e) => {
                e.preventDefault();
                closeGuestModal();
            });
        }

        // -----------------------------------------------------------------
        // Event Delegation on #browse-results
        // Handles: 1. Guest action clicks, 2. Pagination clicks
        // -----------------------------------------------------------------
        if (resultsContainer) {
            resultsContainer.addEventListener('click', (e) => {
                // 1. Guest action button
                const guestBtn = e.target.closest('.gh-guest-bid-btn');
                if (guestBtn) {
                    e.preventDefault();
                    openGuestModal(guestBtn);
                    return;
                }

                // 2. Pagination links
                const paginationLink = e.target.closest('.job-pagination a');
                if (paginationLink) {
                    e.preventDefault();
                    const targetPage = paginationLink.getAttribute('data-page');
                    if (targetPage) {
                        const pageNum = parseInt(targetPage, 10);
                        if (inputPage) {
                            inputPage.value = String(pageNum);
                        }
                        const qs = buildQueryString(pageNum);
                        fetchJobs(qs, true);

                        // Smooth scroll back to top of results
                        const mainContent = document.getElementById('main-content');
                        if (mainContent) {
                            mainContent.scrollIntoView({ behavior: 'smooth' });
                        }
                    }
                }
            });
        }

        // -----------------------------------------------------------------
        // Browser History PopState (Back / Forward Navigation)
        // -----------------------------------------------------------------
        window.addEventListener('popstate', () => {
            const currentParams = new URLSearchParams(window.location.search);
            const qVal = currentParams.get('q') || '';
            const minB = currentParams.get('min_budget') || '';
            const maxB = currentParams.get('max_budget') || '';
            const sortVal = currentParams.get('sort') || 'newest';
            const pageVal = currentParams.get('page') || '1';
            const skills = currentParams.getAll('skills[]');

            // Sync search input
            if (searchInput) searchInput.value = qVal;

            // Sync hidden inputs
            if (inputMinBudget) inputMinBudget.value = minB;
            if (inputMaxBudget) inputMaxBudget.value = maxB;
            if (inputSort) inputSort.value = sortVal;
            if (inputPage) inputPage.value = pageVal;

            // Sync hidden skills
            if (hiddenSkillsContainer) {
                hiddenSkillsContainer.innerHTML = '';
                skills.forEach(sId => {
                    const h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'skills[]';
                    h.value = sId;
                    hiddenSkillsContainer.appendChild(h);
                });
            }

            // Sync modal inputs
            if (modalMinBudget) modalMinBudget.value = minB;
            if (modalMaxBudget) modalMaxBudget.value = maxB;
            if (filterModal) {
                filterModal.querySelectorAll('input[type="checkbox"][name="modal_skills[]"]').forEach(cb => {
                    cb.checked = skills.includes(cb.value);
                });
            }
            updateModalSkillsCounter();
            updateFilterBadge();

            // Sync sort radio
            if (sortMenu) {
                const r = sortMenu.querySelector('input[type="radio"][name="sort_option"][value="' + sortVal + '"]');
                if (r) r.checked = true;
            }
            if (sortLabelDisplay) {
                sortLabelDisplay.textContent = SORT_LABELS[sortVal] || 'Newest first';
            }

            // Fetch without pushing new state
            const qs = buildQueryString(parseInt(pageVal, 10));
            fetchJobs(qs, null);
        });

        // Initial setup
        updateFilterBadge();
    });
})();

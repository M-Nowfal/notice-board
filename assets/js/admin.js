(function () {
    const config = window.dashboardConfig || {};
    const tableBody = document.getElementById('dashboard-table-body');
    const mobileList = document.getElementById('dashboard-mobile-list');
    const searchInput = document.getElementById('dashboard-search');
    const searchEmptyRow = document.getElementById('dashboard-search-empty-row');
    const mobileSearchEmpty = document.getElementById('dashboard-mobile-search-empty');

    const sidebar = document.getElementById('admin-sidebar-mobile');
    const sidebarOverlay = document.getElementById('admin-sidebar-overlay');
    const sidebarOpenBtn = document.getElementById('open-admin-sidebar');
    const sidebarCloseBtns = document.querySelectorAll('[data-close-admin-sidebar]');

    if (window.onbTheme && typeof window.onbTheme.initThemeToggle === 'function') {
        window.onbTheme.initThemeToggle('theme-toggle');
        window.onbTheme.initThemeToggle('theme-toggle-mobile');
    }

    function openSidebar() {
        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.remove('-translate-x-full');
        sidebarOverlay.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeSidebar() {
        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.add('-translate-x-full');
        sidebarOverlay.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    function bindSidebar() {
        if (!sidebar || !sidebarOverlay) {
            return;
        }

        if (sidebarOpenBtn) {
            sidebarOpenBtn.addEventListener('click', openSidebar);
        }

        sidebarCloseBtns.forEach(function (btn) {
            btn.addEventListener('click', closeSidebar);
        });

        sidebarOverlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    function getNoticeRows() {
        if (!tableBody) {
            return [];
        }

        return Array.from(tableBody.querySelectorAll('tr[data-row-id]'));
    }

    function getNoticeCards() {
        if (!mobileList) {
            return [];
        }

        return Array.from(mobileList.querySelectorAll('[data-card-id]'));
    }

    function getSearchText(node) {
        return (node.getAttribute('data-search') || '').toLowerCase();
    }

    function updateSearchEmptyState(term, visibleRows, visibleCards) {
        if (searchEmptyRow) {
            const showRow = term !== '' && getNoticeRows().length > 0 && visibleRows === 0;
            searchEmptyRow.classList.toggle('hidden', !showRow);
        }

        if (mobileSearchEmpty) {
            const showCard = term !== '' && getNoticeCards().length > 0 && visibleCards === 0;
            mobileSearchEmpty.classList.toggle('hidden', !showCard);
        }
    }

    function applySearchFilter(rawTerm) {
        const term = rawTerm.trim().toLowerCase();
        let visibleRows = 0;
        let visibleCards = 0;

        getNoticeRows().forEach(function (row) {
            const matches = term === '' || getSearchText(row).includes(term);
            row.classList.toggle('hidden', !matches);
            if (matches) {
                visibleRows += 1;
            }
        });

        getNoticeCards().forEach(function (card) {
            const matches = term === '' || getSearchText(card).includes(term);
            card.classList.toggle('hidden', !matches);
            if (matches) {
                visibleCards += 1;
            }
        });

        updateSearchEmptyState(term, visibleRows, visibleCards);
    }

    function ensureEmptyState() {
        const rows = getNoticeRows();
        const cards = getNoticeCards();

        if (rows.length > 0 || cards.length > 0) {
            return;
        }

        const emptyMessage = config.isSystemAdmin
            ? 'No active notices found.'
            : 'No notices found. Create your first notice.';

        if (tableBody) {
            const headerColumns = document.querySelectorAll('table thead th').length || (config.isSystemAdmin ? 9 : 8);
            tableBody.innerHTML = `<tr><td colspan="${headerColumns}" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">${emptyMessage}</td></tr>`;
        }

        if (mobileList) {
            mobileList.innerHTML = `<div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 px-4 py-8 text-center text-slate-500 dark:text-slate-400">${emptyMessage}</div>`;
        }
    }

    async function postAction(endpoint, id) {
        const payload = new URLSearchParams();
        payload.append('id', String(id));
        payload.append('csrf_token', config.csrfToken || '');

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: payload.toString(),
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Request failed.');
        }

        return data;
    }

    function setPinnedVisual(row, pinValue) {
        if (!row) {
            return;
        }

        const titleCell = row.querySelector('td');
        if (!titleCell) {
            return;
        }

        const oldBadge = titleCell.querySelector('.badge-pill');
        if (oldBadge) {
            oldBadge.remove();
        }

        if (pinValue !== 1) {
            return;
        }

        const badge = document.createElement('span');
        badge.className = 'inline-flex items-center gap-1 mt-2 badge-pill bg-blue-600 text-white text-[11px]';
        badge.innerHTML = '<i class="fa-solid fa-thumbtack"></i> Pinned';
        titleCell.appendChild(badge);
    }

    function setPinnedCardVisual(card, pinValue) {
        if (!card) {
            return;
        }

        const badge = card.querySelector('[data-mobile-pin-badge]');
        if (!badge) {
            return;
        }

        badge.classList.toggle('hidden', pinValue !== 1);
    }

    function setPinButtonsDisabled(id, isDisabled) {
        document.querySelectorAll(`[data-pin-id="${id}"]`).forEach(function (btn) {
            btn.disabled = isDisabled;
        });
    }

    function updatePinLabels(id, pinValue) {
        const text = pinValue === 1 ? 'Unpin' : 'Pin';
        document.querySelectorAll(`[data-pin-id="${id}"] [data-pin-label]`).forEach(function (label) {
            label.textContent = text;
        });
    }

    function bindDashboardActions() {
        if (!tableBody && !mobileList) {
            return;
        }

        document.addEventListener('click', async function (event) {
            const pinBtn = event.target.closest('[data-pin-id]');
            if (pinBtn) {
                const id = Number(pinBtn.getAttribute('data-pin-id'));
                if (!id) {
                    return;
                }

                setPinButtonsDisabled(id, true);
                try {
                    const data = await postAction(config.pinEndpoint, id);
                    const pinValue = Number(data.pin);
                    const row = tableBody ? tableBody.querySelector(`tr[data-row-id="${id}"]`) : null;
                    const card = mobileList ? mobileList.querySelector(`[data-card-id="${id}"]`) : null;

                    setPinnedVisual(row, pinValue);
                    setPinnedCardVisual(card, pinValue);
                    updatePinLabels(id, pinValue);
                } catch (error) {
                    alert(error.message || 'Unable to update pin status.');
                } finally {
                    setPinButtonsDisabled(id, false);
                }
                return;
            }

            const deleteBtn = event.target.closest('[data-delete-id]');
            if (deleteBtn) {
                const id = Number(deleteBtn.getAttribute('data-delete-id'));
                if (!id) {
                    return;
                }

                if (!window.confirm('Delete this notice? This action performs a soft delete.')) {
                    return;
                }

                deleteBtn.disabled = true;
                try {
                    await postAction(config.deleteEndpoint, id);
                    const row = tableBody ? tableBody.querySelector(`tr[data-row-id="${id}"]`) : null;
                    const card = mobileList ? mobileList.querySelector(`[data-card-id="${id}"]`) : null;

                    if (row) {
                        row.remove();
                    }

                    if (card) {
                        card.remove();
                    }

                    ensureEmptyState();
                    applySearchFilter(searchInput ? searchInput.value : '');
                } catch (error) {
                    alert(error.message || 'Unable to delete notice.');
                } finally {
                    deleteBtn.disabled = false;
                }
            }
        });
    }

    function bindSearch() {
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', function (event) {
            applySearchFilter(event.target.value);
        });
    }

    bindSidebar();
    bindDashboardActions();
    bindSearch();
    applySearchFilter(searchInput ? searchInput.value : '');
})();

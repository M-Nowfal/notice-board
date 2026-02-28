(function () {
    const state = {
        search: '',
        category: '',
        visibility: '',
        pinnedOnly: false,
    };

    const els = {
        container: document.getElementById('notice-container'),
        count: document.getElementById('notice-count'),
        countMobile: document.getElementById('notice-count-mobile'),

        search: document.getElementById('search'),
        category: document.getElementById('category'),
        visibility: document.getElementById('visibility'),
        pinnedOnly: document.getElementById('pinned-only'),

        mobileSearch: document.getElementById('mobile-search'),
        mobileCategory: document.getElementById('mobile-category'),
        mobileVisibility: document.getElementById('mobile-visibility'),
        mobilePinnedOnly: document.getElementById('mobile-pinned-only'),
        mobileClearFilters: document.getElementById('mobile-clear-filters'),

        sidebar: document.getElementById('mobile-sidebar'),
        sidebarOverlay: document.getElementById('mobile-sidebar-overlay'),
        sidebarOpeners: document.querySelectorAll('[data-open-sidebar]'),
        sidebarClosers: document.querySelectorAll('[data-close-sidebar]'),

        modal: document.getElementById('notice-modal'),
        modalTitle: document.getElementById('modal-title'),
        modalContent: document.getElementById('modal-content'),
    };

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString([], {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function debounce(callback, delay) {
        let timer;

        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(function () {
                callback.apply(this, args);
            }, delay);
        };
    }

    function syncFilterInputs() {
        if (els.search && els.search.value !== state.search) {
            els.search.value = state.search;
        }
        if (els.mobileSearch && els.mobileSearch.value !== state.search) {
            els.mobileSearch.value = state.search;
        }

        if (els.category && els.category.value !== state.category) {
            els.category.value = state.category;
        }
        if (els.mobileCategory && els.mobileCategory.value !== state.category) {
            els.mobileCategory.value = state.category;
        }

        if (els.visibility && els.visibility.value !== state.visibility) {
            els.visibility.value = state.visibility;
        }
        if (els.mobileVisibility && els.mobileVisibility.value !== state.visibility) {
            els.mobileVisibility.value = state.visibility;
        }

        if (els.pinnedOnly && els.pinnedOnly.checked !== state.pinnedOnly) {
            els.pinnedOnly.checked = state.pinnedOnly;
        }
        if (els.mobilePinnedOnly && els.mobilePinnedOnly.checked !== state.pinnedOnly) {
            els.mobilePinnedOnly.checked = state.pinnedOnly;
        }
    }

    function setNoticeCount(count) {
        const label = `${count} notice${count === 1 ? '' : 's'} found`;
        if (els.count) {
            els.count.textContent = label;
        }
        if (els.countMobile) {
            els.countMobile.textContent = label;
        }
    }

    function openSidebar() {
        if (!els.sidebar || !els.sidebarOverlay) {
            return;
        }

        els.sidebarOverlay.classList.remove('hidden');
        els.sidebar.classList.remove('translate-x-full');
        document.body.classList.add('modal-open');
    }

    function closeSidebar() {
        if (!els.sidebar || !els.sidebarOverlay) {
            return;
        }

        els.sidebar.classList.add('translate-x-full');
        els.sidebarOverlay.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    async function fetchNotices() {
        const params = new URLSearchParams({
            search: state.search,
            category: state.category,
            visibility: state.visibility,
            pinned_only: state.pinnedOnly ? '1' : '0',
        });

        els.container.innerHTML = '<div class="sm:col-span-2 xl:col-span-3 rounded-2xl border border-slate-300 dark:border-slate-700 p-8 text-center bg-white/70 dark:bg-slate-900/60">Loading notices...</div>';

        try {
            const response = await fetch('fetch_notices.php?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Unable to fetch notices.');
            }

            els.container.innerHTML = data.html;
            setNoticeCount(Number(data.count) || 0);
        } catch (error) {
            els.container.innerHTML = '<div class="sm:col-span-2 xl:col-span-3 rounded-2xl border border-red-300 text-red-700 dark:text-red-300 dark:border-red-700 p-8 text-center bg-white/70 dark:bg-slate-900/60">Unable to load notices. Please try again.</div>';
            setNoticeCount(0);
        }
    }

    function openModal() {
        if (!els.modal) {
            return;
        }

        els.modal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        if (!els.modal) {
            return;
        }

        els.modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    async function openNotice(noticeId) {
        try {
            const response = await fetch('notice_detail.php?id=' + encodeURIComponent(noticeId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Unable to load notice details.');
            }

            const notice = data.notice;
            els.modalTitle.textContent = notice.title;

            const filesMarkup = notice.files.length
                ? notice.files
                      .map(function (file, index) {
                          return `<a href="${escapeHtml(file.file_path)}" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"><i class="fa-solid fa-paperclip"></i> Attachment ${index + 1}</a>`;
                      })
                      .join(' ')
                : '<span class="text-sm text-slate-500 dark:text-slate-400">No attachments</span>';

            els.modalContent.innerHTML = `
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-slate-100 dark:bg-slate-800 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Category</p>
                        <p class="font-semibold mt-1">${escapeHtml(notice.category_name)}</p>
                    </div>
                    <div class="rounded-xl bg-slate-100 dark:bg-slate-800 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Published By</p>
                        <p class="font-semibold mt-1">${escapeHtml(notice.admin_name)}</p>
                    </div>
                    <div class="rounded-xl bg-slate-100 dark:bg-slate-800 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Created</p>
                        <p class="font-semibold mt-1">${escapeHtml(formatDate(notice.createdAt))}</p>
                    </div>
                    <div class="rounded-xl bg-slate-100 dark:bg-slate-800 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Expires</p>
                        <p class="font-semibold mt-1">${escapeHtml(formatDate(notice.expiresAt))}</p>
                    </div>
                </div>
                <div class="grid sm:grid-cols-3 gap-3 text-sm">
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Priority</p>
                        <p class="font-semibold mt-1">${escapeHtml(notice.priority)}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Visibility</p>
                        <p class="font-semibold mt-1">${escapeHtml(notice.visibility)}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-3">
                        <p class="text-slate-500 dark:text-slate-400">Views</p>
                        <p class="font-semibold mt-1">${escapeHtml(notice.views)}</p>
                    </div>
                </div>
                <div>
                    <p class="text-sm font-medium mb-2">Attachments</p>
                    <div class="flex flex-wrap gap-2">${filesMarkup}</div>
                </div>
            `;

            const viewNode = document.querySelector(`[data-view-count="${notice.id}"]`);
            if (viewNode) {
                viewNode.textContent = String(notice.views);
            }

            openModal();
        } catch (error) {
            alert(error.message || 'Unable to open notice.');
        }
    }

    function bindFilterEvents() {
        const handleSearch = debounce(function (value) {
            state.search = value.trim();
            syncFilterInputs();
            fetchNotices();
        }, 250);

        if (els.search) {
            els.search.addEventListener('input', function (event) {
                handleSearch(event.target.value);
            });
        }

        if (els.mobileSearch) {
            els.mobileSearch.addEventListener('input', function (event) {
                handleSearch(event.target.value);
            });
        }

        if (els.category) {
            els.category.addEventListener('change', function (event) {
                state.category = event.target.value;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.mobileCategory) {
            els.mobileCategory.addEventListener('change', function (event) {
                state.category = event.target.value;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.visibility) {
            els.visibility.addEventListener('change', function (event) {
                state.visibility = event.target.value;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.mobileVisibility) {
            els.mobileVisibility.addEventListener('change', function (event) {
                state.visibility = event.target.value;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.pinnedOnly) {
            els.pinnedOnly.addEventListener('change', function (event) {
                state.pinnedOnly = event.target.checked;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.mobilePinnedOnly) {
            els.mobilePinnedOnly.addEventListener('change', function (event) {
                state.pinnedOnly = event.target.checked;
                syncFilterInputs();
                fetchNotices();
            });
        }

        if (els.mobileClearFilters) {
            els.mobileClearFilters.addEventListener('click', function () {
                state.search = '';
                state.category = '';
                state.visibility = '';
                state.pinnedOnly = false;
                syncFilterInputs();
                fetchNotices();
            });
        }
    }

    function bindSidebarEvents() {
        if (!els.sidebar || !els.sidebarOverlay) {
            return;
        }

        els.sidebarOpeners.forEach(function (button) {
            button.addEventListener('click', openSidebar);
        });

        els.sidebarClosers.forEach(function (button) {
            button.addEventListener('click', closeSidebar);
        });

        els.sidebarOverlay.addEventListener('click', closeSidebar);
    }

    function bindNoticeEvents() {
        if (!els.container) {
            return;
        }

        els.container.addEventListener('click', function (event) {
            const openBtn = event.target.closest('[data-open-notice]');
            if (!openBtn) {
                return;
            }

            openNotice(openBtn.getAttribute('data-open-notice'));
        });
    }

    function bindModalEvents() {
        if (!els.modal) {
            return;
        }

        els.modal.addEventListener('click', function (event) {
            if (event.target.matches('[data-close-modal]') || event.target.closest('[data-close-modal]')) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (els.modal && !els.modal.classList.contains('hidden')) {
                closeModal();
            }

            if (els.sidebar && !els.sidebar.classList.contains('translate-x-full')) {
                closeSidebar();
            }
        });
    }

    function bindThemeToggles() {
        if (window.onbTheme && typeof window.onbTheme.initThemeToggle === 'function') {
            window.onbTheme.initThemeToggle('theme-toggle');
            window.onbTheme.initThemeToggle('theme-toggle-mobile');
        }
    }

    bindThemeToggles();
    bindFilterEvents();
    bindSidebarEvents();
    bindNoticeEvents();
    bindModalEvents();
    syncFilterInputs();
    fetchNotices();
})();

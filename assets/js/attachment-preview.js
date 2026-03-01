(function () {
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB'];
        const idx = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const value = bytes / Math.pow(1024, idx);
        return `${value.toFixed(idx === 0 ? 0 : 1)} ${units[idx]}`;
    }

    function isImageFile(file) {
        if (file && typeof file.type === 'string' && file.type.startsWith('image/')) {
            return true;
        }

        return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(file?.name || '');
    }

    function fileIconByName(name) {
        const extension = (name.split('.').pop() || '').toLowerCase();

        if (extension === 'pdf') {
            return 'fa-regular fa-file-pdf';
        }

        if (['doc', 'docx'].includes(extension)) {
            return 'fa-regular fa-file-word';
        }

        return 'fa-regular fa-file-lines';
    }

    function fileKey(file) {
        return `${String(file.name || '').toLowerCase()}::${file.size || 0}::${file.lastModified || 0}`;
    }

    function initializePreviewInput(input) {
        const previewId = input.dataset.attachmentPreview;
        const countId = input.dataset.attachmentCount;
        const previewContainer = previewId ? document.getElementById(previewId) : null;
        const countNode = countId ? document.getElementById(countId) : null;

        if (!previewContainer) {
            return;
        }

        const maxFiles = Math.max(1, parseInt(input.dataset.maxFiles || '5', 10) || 5);
        let selectedFiles = [];
        let objectUrls = [];

        function revokeUrls() {
            objectUrls.forEach(function (url) {
                URL.revokeObjectURL(url);
            });
            objectUrls = [];
        }

        function syncInputFiles() {
            if (typeof DataTransfer === 'undefined') {
                return;
            }

            const transfer = new DataTransfer();
            selectedFiles.forEach(function (file) {
                transfer.items.add(file);
            });
            input.files = transfer.files;
        }

        function renderSelectedFiles(messages) {
            const warnings = Array.isArray(messages) ? messages : [];
            const total = selectedFiles.length;

            if (countNode) {
                const baseText = total === 0
                    ? 'No files selected yet.'
                    : `${total} file${total === 1 ? '' : 's'} selected (max ${maxFiles}).`;

                countNode.textContent = warnings.length > 0
                    ? `${baseText} ${warnings.join(' ')}`
                    : baseText;
            }

            revokeUrls();
            previewContainer.innerHTML = '';

            if (total === 0) {
                previewContainer.classList.add('hidden');
                return;
            }

            const fragment = document.createDocumentFragment();

            selectedFiles.forEach(function (file, index) {
                const fileName = escapeHtml(file.name);
                const fileSize = formatBytes(file.size);
                const removeButton = `
                    <button type="button" data-remove-index="${index}" class="mt-2 inline-flex items-center gap-1 rounded-lg border border-red-300 text-red-600 dark:border-red-800 dark:text-red-300 px-2 py-1 text-xs hover:bg-red-50 dark:hover:bg-red-900/20">
                        <i class="fa-solid fa-xmark"></i> Remove
                    </button>
                `;

                const card = document.createElement('div');
                card.className = 'rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-3';

                if (isImageFile(file)) {
                    const url = URL.createObjectURL(file);
                    objectUrls.push(url);

                    card.innerHTML = `
                        <div class="h-28 rounded-lg overflow-hidden bg-slate-100 dark:bg-slate-800 mb-2">
                            <img src="${url}" alt="${fileName}" class="h-full w-full object-cover" />
                        </div>
                        <p class="text-xs font-medium truncate" title="${fileName}">${fileName}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">${fileSize}</p>
                        ${removeButton}
                    `;
                } else {
                    const iconClass = fileIconByName(file.name);
                    card.innerHTML = `
                        <div class="flex items-center gap-3">
                            <span class="h-10 w-10 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-300">
                                <i class="${iconClass}"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-medium truncate" title="${fileName}">${fileName}</p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">${fileSize}</p>
                            </div>
                        </div>
                        ${removeButton}
                    `;
                }

                fragment.appendChild(card);
            });

            previewContainer.appendChild(fragment);
            previewContainer.classList.remove('hidden');
        }

        function appendFiles(newFiles) {
            const incoming = Array.from(newFiles || []);
            if (incoming.length === 0) {
                return;
            }

            const messages = [];
            const existingKeys = new Set(selectedFiles.map(fileKey));

            incoming.forEach(function (file) {
                const key = fileKey(file);

                if (existingKeys.has(key)) {
                    messages.push(`Skipped duplicate: ${file.name}.`);
                    return;
                }

                if (selectedFiles.length >= maxFiles) {
                    messages.push(`Maximum ${maxFiles} attachments allowed.`);
                    return;
                }

                selectedFiles.push(file);
                existingKeys.add(key);
            });

            syncInputFiles();
            renderSelectedFiles(messages);
        }

        input.addEventListener('change', function () {
            appendFiles(input.files);
        });

        previewContainer.addEventListener('click', function (event) {
            const removeButton = event.target.closest('[data-remove-index]');
            if (!removeButton) {
                return;
            }

            const index = Number(removeButton.getAttribute('data-remove-index'));
            if (!Number.isInteger(index) || index < 0 || index >= selectedFiles.length) {
                return;
            }

            selectedFiles.splice(index, 1);
            syncInputFiles();
            renderSelectedFiles([]);
        });

        window.addEventListener('beforeunload', revokeUrls);
        renderSelectedFiles([]);
    }

    function boot() {
        const inputs = document.querySelectorAll('input[type="file"][data-attachment-preview]');
        inputs.forEach(initializePreviewInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

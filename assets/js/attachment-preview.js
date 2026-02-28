(function () {
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

    function initializePreviewInput(input) {
        const previewId = input.dataset.attachmentPreview;
        const countId = input.dataset.attachmentCount;
        const previewContainer = previewId ? document.getElementById(previewId) : null;
        const countNode = countId ? document.getElementById(countId) : null;

        if (!previewContainer) {
            return;
        }

        let objectUrls = [];

        function revokeUrls() {
            objectUrls.forEach(function (url) {
                URL.revokeObjectURL(url);
            });
            objectUrls = [];
        }

        function renderSelectedFiles() {
            const files = Array.from(input.files || []);
            const total = files.length;

            if (countNode) {
                countNode.textContent = total === 0
                    ? 'No files selected yet.'
                    : `${total} file${total === 1 ? '' : 's'} selected.`;
            }

            revokeUrls();
            previewContainer.innerHTML = '';

            if (total === 0) {
                previewContainer.classList.add('hidden');
                return;
            }

            const fragment = document.createDocumentFragment();

            files.forEach(function (file) {
                const card = document.createElement('div');
                card.className = 'rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-3';

                if (isImageFile(file)) {
                    const url = URL.createObjectURL(file);
                    objectUrls.push(url);

                    card.innerHTML = `
                        <div class="h-28 rounded-lg overflow-hidden bg-slate-100 dark:bg-slate-800 mb-2">
                            <img src="${url}" alt="${file.name}" class="h-full w-full object-cover" />
                        </div>
                        <p class="text-xs font-medium truncate" title="${file.name}">${file.name}</p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">${formatBytes(file.size)}</p>
                    `;
                } else {
                    const iconClass = fileIconByName(file.name);
                    card.innerHTML = `
                        <div class="flex items-center gap-3">
                            <span class="h-10 w-10 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-300">
                                <i class="${iconClass}"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-medium truncate" title="${file.name}">${file.name}</p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">${formatBytes(file.size)}</p>
                            </div>
                        </div>
                    `;
                }

                fragment.appendChild(card);
            });

            previewContainer.appendChild(fragment);
            previewContainer.classList.remove('hidden');
        }

        input.addEventListener('change', renderSelectedFiles);
        window.addEventListener('beforeunload', revokeUrls);
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

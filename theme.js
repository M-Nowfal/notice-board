(function () {
    const STORAGE_KEY = 'onb-theme';
    const FALLBACK_THEME = 'light';
    const toggleButtons = [];

    function readTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }

    function writeTheme(theme) {
        try {
            localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // Ignore storage failures and keep working for this session.
        }
    }

    function updateToggleVisual(button) {
        if (!button) {
            return;
        }

        const darkEnabled = document.documentElement.classList.contains('dark');
        const label = button.querySelector('[data-theme-label]');
        const icon = button.querySelector('i');

        if (label) {
            label.textContent = darkEnabled ? 'Dark' : 'Light';
        }

        if (icon) {
            icon.className = darkEnabled ? 'fa-solid fa-moon text-sm' : 'fa-solid fa-sun text-sm';
        }
    }

    function refreshThemeToggles() {
        toggleButtons.forEach(function (button) {
            updateToggleVisual(button);
        });
    }

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.documentElement.classList.toggle('dark', isDark);
        writeTheme(isDark ? 'dark' : FALLBACK_THEME);
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : FALLBACK_THEME);
        refreshThemeToggles();
    }

    function getPreferredTheme() {
        const stored = readTheme();
        if (stored === 'dark' || stored === 'light') {
            return stored;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : FALLBACK_THEME;
    }

    function initThemeToggle(buttonId) {
        const button = document.getElementById(buttonId);
        if (!button) {
            return;
        }

        if (!toggleButtons.includes(button)) {
            toggleButtons.push(button);
        }

        if (button.dataset.themeBound === '1') {
            updateToggleVisual(button);
            return;
        }

        button.dataset.themeBound = '1';
        updateToggleVisual(button);

        button.addEventListener('click', function () {
            const nextTheme = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
            applyTheme(nextTheme);
        });
    }

    window.onbTheme = {
        applyTheme,
        initThemeToggle,
        refreshThemeToggles,
    };

    applyTheme(getPreferredTheme());
})();

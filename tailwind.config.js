/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: [
        './index.php',
        './fetch_notices.php',
        './notice_detail.php',
        './admin/**/*.php',
        './assets/js/**/*.js',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', 'ui-sans-serif', 'system-ui'],
            },
        },
    },
    plugins: [],
};

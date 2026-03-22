<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
cleanupExpiredNotices($pdo);
$categories = fetchCategories($pdo);
$themeVersion = (string) (@filemtime(__DIR__ . '/theme.js') ?: time());
$mainJsVersion = (string) (@filemtime(__DIR__ . '/assets/js/main.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Notice Board</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-Evv84Mr4kqVGRNSgIGL/F/aIDqQb7xQ2vcrdIwxfjThSH8CSR7PBEakCr51Ck+w+/U6swU2Im1vVX0SVk9ABhg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="theme.js?v=<?php echo escape($themeVersion); ?>"></script>
</head>
<body class="h-full text-slate-800 dark:text-slate-100 bg-slate-100 dark:bg-slate-950 transition-colors duration-200">
    <div class="fixed inset-0 -z-10 pointer-events-none opacity-80 dark:opacity-60">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(14,165,233,0.3),transparent_35%),radial-gradient(circle_at_85%_10%,rgba(59,130,246,0.22),transparent_30%),radial-gradient(circle_at_20%_85%,rgba(14,116,144,0.18),transparent_40%)]"></div>
    </div>

    <header class="sticky top-0 z-30 backdrop-blur-soft bg-white/80 dark:bg-slate-900/80 border-b border-slate-200 dark:border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-blue-600 via-sky-500 to-cyan-500 text-white flex items-center justify-center shadow-lg shadow-blue-500/30">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">Campus Updates</p>
                    <h1 class="text-xl md:text-2xl font-bold">Online Notice Board</h1>
                </div>
            </div>

            <div class="hidden md:flex items-center gap-2 sm:gap-3">
                <button id="theme-toggle" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-sun text-sm"></i>
                    <span data-theme-label>Light</span>
                </button>
                <a href="admin/login.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white text-sm font-medium shadow-lg shadow-blue-600/30">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Admin Login</span>
                </a>
            </div>

            <button type="button" data-open-sidebar class="md:hidden inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                <i class="fa-solid fa-sliders"></i>
                Menu
            </button>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <section class="relative overflow-hidden rounded-3xl border border-slate-200/80 dark:border-slate-800/80 bg-white/80 dark:bg-slate-900/65 p-6 sm:p-8 shadow-xl mb-6">
            <div class="absolute -right-16 -top-16 w-56 h-56 bg-blue-500/20 blur-3xl rounded-full"></div>
            <div class="absolute -left-16 -bottom-16 w-56 h-56 bg-cyan-500/20 blur-3xl rounded-full"></div>
            <div class="relative flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400 mb-2">Real-Time Announcements</p>
                    <h2 class="text-2xl sm:text-3xl font-bold leading-tight">Stay updated with every important notice</h2>
                    <p class="mt-2 text-sm sm:text-base text-slate-600 dark:text-slate-300">Pinned updates, category filtering, file downloads, and instant search in one place.</p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs sm:text-sm">
                    <div class="rounded-xl bg-slate-100/90 dark:bg-slate-800/80 px-4 py-3 border border-slate-200 dark:border-slate-700">
                        <p class="text-slate-500 dark:text-slate-400">Sort Order</p>
                        <p class="font-semibold mt-1">Pinned + Latest</p>
                    </div>
                    <div class="rounded-xl bg-slate-100/90 dark:bg-slate-800/80 px-4 py-3 border border-slate-200 dark:border-slate-700">
                        <p class="text-slate-500 dark:text-slate-400">Visibility</p>
                        <p class="font-semibold mt-1">Public / Students / Staff</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="hidden md:block bg-white/85 dark:bg-slate-900/75 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 sm:p-6 shadow-xl">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                <div class="lg:col-span-2">
                    <label for="search" class="text-sm font-medium">Search Notices</label>
                    <div class="mt-2 relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input id="search" type="text" placeholder="Search by title or description..." class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    </div>
                </div>
                <div>
                    <label for="category" class="text-sm font-medium">Category</label>
                    <select id="category" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int) $category['id']; ?>"><?php echo escape((string) $category['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="visibility" class="text-sm font-medium">Visibility</label>
                    <select id="visibility" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">All</option>
                        <option value="public">Public</option>
                        <option value="students">Students</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-3 rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 w-full cursor-pointer select-none">
                        <input id="pinned-only" type="checkbox" class="h-4 w-4 rounded border-slate-400 text-blue-600 focus:ring-blue-500" />
                        <span class="text-sm font-medium">Pinned only</span>
                    </label>
                </div>
            </div>
        </section>

        <section class="md:hidden mb-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 px-4 py-3">
            <div class="flex flex-col gap-3">
                <div>
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Filters & Actions</p>
                    <p id="notice-count-mobile" class="text-sm font-medium mt-1"></p>
                </div>
                <button type="button" data-open-sidebar class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 text-sm font-medium">
                    <i class="fa-solid fa-bars"></i>
                    Open Sidebar
                </button>
            </div>
        </section>

        <section>
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 m-4">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <h2 id="notice-section-title" class="text-lg sm:text-xl font-semibold">Active Notices</h2>
                    <div class="flex w-full sm:w-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/70 p-1 shadow-sm">
                        <button type="button" data-notice-status="active" class="flex-1 sm:flex-none notice-status-button rounded-xl px-3 py-2 text-sm font-medium text-center bg-blue-600 text-white shadow-sm">
                            Active Notices
                        </button>
                        <button type="button" data-notice-status="expired" class="flex-1 sm:flex-none notice-status-button rounded-xl px-3 py-2 text-sm font-medium text-center text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                            Expired Notices
                        </button>
                    </div>
                </div>
                <p id="notice-count" class="hidden md:block text-sm text-slate-500 dark:text-slate-400"></p>
            </div>
            <div id="notice-container" class="grid md:grid-cols-2 gap-5"></div>
        </section>
    </main>

    <div id="mobile-sidebar-overlay" class="fixed inset-0 z-40 bg-slate-950/50 hidden md:hidden"></div>
    <aside id="mobile-sidebar" class="fixed inset-y-0 right-0 z-50 w-[88%] max-w-sm bg-white dark:bg-slate-900 border-l border-slate-200 dark:border-slate-800 shadow-2xl transform translate-x-full transition-transform duration-300 md:hidden">
        <div class="p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-semibold text-lg">Quick Panel</h3>
            <button type="button" data-close-sidebar class="h-9 w-9 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="p-4 space-y-5 overflow-y-auto h-[calc(100%-73px)]">
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/50 p-4 space-y-3">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Actions</p>
                <button id="theme-toggle-mobile" type="button" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-sun text-sm"></i>
                    <span data-theme-label>Light</span>
                </button>
                <a href="admin/login.php" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white text-sm font-medium">
                    <i class="fa-solid fa-user-shield"></i>
                    Admin Login
                </a>
            </div>

            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-950/50 p-4 space-y-4">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Filters</p>

                <div>
                    <label for="mobile-search" class="text-sm font-medium">Search Notices</label>
                    <div class="mt-2 relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input id="mobile-search" type="text" placeholder="Search by title or description..." class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    </div>
                </div>

                <div>
                    <label for="mobile-category" class="text-sm font-medium">Category</label>
                    <select id="mobile-category" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int) $category['id']; ?>"><?php echo escape((string) $category['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="mobile-visibility" class="text-sm font-medium">Visibility</label>
                    <select id="mobile-visibility" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">All</option>
                        <option value="public">Public</option>
                        <option value="students">Students</option>
                        <option value="staff">Staff</option>
                    </select>
                </div>

                <label class="inline-flex items-center gap-3 rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 w-full cursor-pointer select-none bg-white dark:bg-slate-900">
                    <input id="mobile-pinned-only" type="checkbox" class="h-4 w-4 rounded border-slate-400 text-blue-600 focus:ring-blue-500" />
                    <span class="text-sm font-medium">Pinned only</span>
                </label>

                <button id="mobile-clear-filters" type="button" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-eraser"></i>
                    Clear Filters
                </button>
            </div>
        </div>
    </aside>

    <div id="notice-modal" class="fixed inset-0 z-40 hidden">
        <div class="absolute inset-0 bg-black/60" data-close-modal></div>
        <div class="relative min-h-full flex items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl">
                <div class="p-4 sm:p-6 border-b border-slate-200 dark:border-slate-800 flex items-start justify-between gap-2">
                    <h3 id="modal-title" class="text-lg sm:text-xl font-semibold">Notice Details</h3>
                    <button type="button" data-close-modal class="h-9 w-9 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div id="modal-content" class="p-4 sm:p-6 space-y-4 max-h-[85vh] overflow-auto"></div>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js?v=<?php echo escape($mainJsVersion); ?>"></script>
</body>
</html>

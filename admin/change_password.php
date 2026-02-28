<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');

$pdo = db();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!verifyCsrf(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    if ($currentPassword === '') {
        $errors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Confirm password is required.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $errors[] = 'New password and confirm password do not match.';
    }

    $adminStmt = $pdo->prepare('SELECT id, username, password FROM admin WHERE id = :id LIMIT 1');
    $adminStmt->execute(['id' => getAdminId()]);
    $admin = $adminStmt->fetch();

    if (!$admin) {
        $errors[] = 'Unable to verify current account.';
    }

    if (!$errors && $admin) {
        $passwordCheck = verifyAdminPassword(
            $currentPassword,
            (string) $admin['password'],
            (string) $admin['username']
        );

        if (!(bool) $passwordCheck['valid']) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if (!$errors && $admin) {
        $sameAsCurrent = verifyAdminPassword(
            $newPassword,
            (string) $admin['password'],
            (string) $admin['username']
        );

        if ((bool) $sameAsCurrent['valid']) {
            $errors[] = 'New password must be different from current password.';
        }
    }

    if (!$errors && $admin) {
        $updateStmt = $pdo->prepare('UPDATE admin SET password = :password WHERE id = :id');
        $updateStmt->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $admin['id'],
        ]);

        $success = 'Password updated successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | Online Notice Board</title>
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../theme.js"></script>
</head>
<body class="h-full text-slate-800 dark:text-slate-100 bg-slate-100 dark:bg-slate-950 transition-colors duration-200">
    <div class="fixed inset-0 -z-10 pointer-events-none opacity-80 dark:opacity-60">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(14,165,233,0.28),transparent_35%),radial-gradient(circle_at_85%_10%,rgba(59,130,246,0.2),transparent_30%),radial-gradient(circle_at_20%_85%,rgba(14,116,144,0.16),transparent_40%)]"></div>
    </div>

    <div class="min-h-full lg:grid lg:grid-cols-[270px_1fr]">
        <aside class="hidden lg:block border-r border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/80 p-5">
            <div class="mb-8">
                <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">Admin Panel</p>
                <h1 class="text-xl font-bold mt-1">Online Notice Board</h1>
                <p class="text-sm mt-2 text-slate-500 dark:text-slate-400">Welcome, <?php echo escape(getAdminName()); ?></p>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php" class="sidebar-link"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                <a href="add_notice.php" class="sidebar-link"><i class="fa-solid fa-plus"></i> Add Notice</a>
                <a href="change_password.php" class="sidebar-link active"><i class="fa-solid fa-key"></i> Change Password</a>
                <?php if (isSystemAdmin()): ?>
                    <a href="manage_admins.php" class="sidebar-link"><i class="fa-solid fa-users-gear"></i> Manage Admins</a>
                <?php endif; ?>
                <a href="../index.php" class="sidebar-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
                <a href="logout.php" class="sidebar-link text-red-600 dark:text-red-400"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </nav>
        </aside>

        <div id="admin-sidebar-overlay" class="fixed inset-0 z-40 bg-slate-950/50 hidden lg:hidden"></div>
        <aside id="admin-sidebar-mobile" class="fixed inset-y-0 left-0 z-50 w-[84%] max-w-xs bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 shadow-2xl transform -translate-x-full transition-transform duration-300 lg:hidden">
            <div class="p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-semibold text-lg">Admin Menu</h3>
                <button type="button" data-close-admin-sidebar class="h-9 w-9 rounded-lg border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="p-4 space-y-4">
                <p class="text-sm text-slate-500 dark:text-slate-400">Signed in as <?php echo escape(getAdminName()); ?></p>
                <nav class="space-y-1">
                    <a href="dashboard.php" class="sidebar-link"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                    <a href="add_notice.php" class="sidebar-link"><i class="fa-solid fa-plus"></i> Add Notice</a>
                    <a href="change_password.php" class="sidebar-link active"><i class="fa-solid fa-key"></i> Change Password</a>
                    <?php if (isSystemAdmin()): ?>
                        <a href="manage_admins.php" class="sidebar-link"><i class="fa-solid fa-users-gear"></i> Manage Admins</a>
                    <?php endif; ?>
                    <a href="../index.php" class="sidebar-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
                    <a href="logout.php" class="sidebar-link text-red-600 dark:text-red-400"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                </nav>
                <button id="theme-toggle-mobile" type="button" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-sun"></i>
                    <span data-theme-label>Light</span>
                </button>
            </div>
        </aside>

        <main class="p-4 sm:p-6 lg:p-8">
            <section class="relative overflow-hidden rounded-3xl border border-slate-200/80 dark:border-slate-800/80 bg-white/80 dark:bg-slate-900/65 p-6 sm:p-8 shadow-xl mb-6">
                <div class="absolute -right-16 -top-16 w-56 h-56 bg-blue-500/20 blur-3xl rounded-full"></div>
                <div class="absolute -left-16 -bottom-16 w-56 h-56 bg-cyan-500/20 blur-3xl rounded-full"></div>

                <div class="relative flex flex-wrap items-end justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <button type="button" id="open-admin-sidebar" class="lg:hidden inline-flex items-center justify-center h-10 w-10 rounded-xl border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 shrink-0 mt-1">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400 mb-2">Security</p>
                            <h2 class="text-2xl sm:text-3xl font-bold leading-tight">Change Password</h2>
                            <p class="mt-2 text-sm sm:text-base text-slate-600 dark:text-slate-300">Use a strong password and avoid sharing it with others.</p>
                        </div>
                    </div>

                    <button id="theme-toggle" type="button" class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i class="fa-solid fa-sun"></i>
                        <span data-theme-label>Light</span>
                    </button>
                </div>
            </section>

            <?php if ($success !== ''): ?>
                <div class="mb-5 rounded-2xl border border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800 px-4 py-3 text-sm">
                    <?php echo escape($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="mb-5 rounded-2xl border border-red-300 bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300 dark:border-red-800 px-4 py-4 text-sm">
                    <div class="flex items-center gap-2 mb-2 font-semibold">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Please fix the following issues
                    </div>
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo escape($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="max-w-2xl">
                <form method="post" class="rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 shadow-2xl p-5 sm:p-6 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">

                    <div>
                        <label for="current_password" class="text-sm font-medium">Current Password</label>
                        <input id="current_password" name="current_password" type="password" required class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    </div>

                    <div>
                        <label for="new_password" class="text-sm font-medium">New Password</label>
                        <input id="new_password" name="new_password" type="password" required minlength="8" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    </div>

                    <div>
                        <label for="confirm_password" class="text-sm font-medium">Confirm New Password</label>
                        <input id="confirm_password" name="confirm_password" type="password" required minlength="8" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    </div>

                    <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white px-5 py-2.5 text-sm font-medium shadow-lg shadow-blue-600/30">
                        <i class="fa-solid fa-shield"></i> Update Password
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script>
        if (window.onbTheme && typeof window.onbTheme.initThemeToggle === 'function') {
            window.onbTheme.initThemeToggle('theme-toggle');
            window.onbTheme.initThemeToggle('theme-toggle-mobile');
        }

        (function () {
            const sidebar = document.getElementById('admin-sidebar-mobile');
            const overlay = document.getElementById('admin-sidebar-overlay');
            const openBtn = document.getElementById('open-admin-sidebar');
            const closeBtns = document.querySelectorAll('[data-close-admin-sidebar]');

            function openSidebar() {
                if (!sidebar || !overlay) {
                    return;
                }

                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
                document.body.classList.add('modal-open');
            }

            function closeSidebar() {
                if (!sidebar || !overlay) {
                    return;
                }

                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
                document.body.classList.remove('modal-open');
            }

            if (openBtn) {
                openBtn.addEventListener('click', openSidebar);
            }

            closeBtns.forEach(function (btn) {
                btn.addEventListener('click', closeSidebar);
            });

            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSidebar();
                }
            });
        })();
    </script>
</body>
</html>

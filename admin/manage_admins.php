<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');
requireSystemAdmin('dashboard.php');

$pdo = db();
cleanupExpiredNotices($pdo);

$errors = [];
$form = [
    'name' => '',
    'username' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitizeText($_POST['action'] ?? 'create', 20);
    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrf(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    if (!$errors && $action === 'delete') {
        $deleteAdminId = (int) ($_POST['delete_admin_id'] ?? 0);

        if ($deleteAdminId <= 0) {
            $errors[] = 'Invalid admin selected for removal.';
        } else {
            $targetStmt = $pdo->prepare('SELECT id, name, username FROM admin WHERE id = :id LIMIT 1');
            $targetStmt->execute(['id' => $deleteAdminId]);
            $targetAdmin = $targetStmt->fetch();

            if (!$targetAdmin) {
                $errors[] = 'Selected admin does not exist.';
            } elseif ((string) $targetAdmin['username'] === SYSTEM_ADMIN_USERNAME) {
                $errors[] = 'System admin account cannot be removed.';
            } elseif ((int) $targetAdmin['id'] === getAdminId()) {
                $errors[] = 'You cannot remove your own account.';
            } else {
                $noticeCountStmt = $pdo->prepare('SELECT COUNT(*) FROM notice WHERE admin_id = :admin_id AND is_deleted = 0');
                $noticeCountStmt->execute(['admin_id' => (int) $targetAdmin['id']]);
                $noticeCount = (int) $noticeCountStmt->fetchColumn();

                if ($noticeCount > 0) {
                    $errors[] = 'Cannot remove admin "' . (string) $targetAdmin['username'] . '" because notices are linked to this account.';
                }
            }
        }

        if (!$errors && isset($targetAdmin)) {
            $purgedPaths = [];
            try {
                $pdo->beginTransaction();

                // Purge soft-deleted notices so admin row can be deleted cleanly.
                $purgedIdsStmt = $pdo->prepare('SELECT id FROM notice WHERE admin_id = :admin_id AND is_deleted = 1');
                $purgedIdsStmt->execute(['admin_id' => (int) $targetAdmin['id']]);
                $purgedNoticeIds = normalizeNoticeIds($purgedIdsStmt->fetchAll(PDO::FETCH_COLUMN));

                if ($purgedNoticeIds) {
                    $purgedPaths = detachNoticeAttachments($pdo, $purgedNoticeIds);
                }

                $purgeStmt = $pdo->prepare('DELETE FROM notice WHERE admin_id = :admin_id AND is_deleted = 1');
                $purgeStmt->execute(['admin_id' => (int) $targetAdmin['id']]);

                $deleteStmt = $pdo->prepare('DELETE FROM admin WHERE id = :id');
                $deleteStmt->execute(['id' => (int) $targetAdmin['id']]);

                $pdo->commit();
                removeFilesFromDisk($purgedPaths);

                $_SESSION['manage_admins_flash'] = 'Admin removed: ' . (string) $targetAdmin['username'] . '.';
                header('Location: manage_admins.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to remove this admin. Delete active notices first.';
            }
        }
    }

    if (!$errors && $action === 'create') {
        $form['name'] = sanitizeText($_POST['name'] ?? '', 100);
        $form['username'] = strtolower(sanitizeText($_POST['username'] ?? '', 50));
        $defaultPassword = trim((string) ($_POST['default_password'] ?? ''));
        $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

        if ($form['name'] === '') {
            $errors[] = 'Admin name is required.';
        }

        if ($form['username'] === '') {
            $errors[] = 'Username is required.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $form['username'])) {
            $errors[] = 'Username must be 3-30 chars and can include lowercase letters, numbers, dot, underscore, and hyphen.';
        }

        if ($defaultPassword === '') {
            $errors[] = 'Default password is required.';
        } elseif (strlen($defaultPassword) < 8) {
            $errors[] = 'Default password must be at least 8 characters.';
        }

        if ($confirmPassword === '') {
            $errors[] = 'Confirm password is required.';
        } elseif (!hash_equals($defaultPassword, $confirmPassword)) {
            $errors[] = 'Default password and confirm password do not match.';
        }

        if (!$errors) {
            $existsStmt = $pdo->prepare('SELECT id FROM admin WHERE username = :username LIMIT 1');
            $existsStmt->execute(['username' => $form['username']]);

            if ($existsStmt->fetch()) {
                $errors[] = 'Username already exists. Please choose another username.';
            }
        }

        if (!$errors) {
            $insertStmt = $pdo->prepare(
                'INSERT INTO admin (name, username, password) VALUES (:name, :username, :password)'
            );
            $insertStmt->execute([
                'name' => $form['name'],
                'username' => $form['username'],
                'password' => password_hash($defaultPassword, PASSWORD_DEFAULT),
            ]);

            $_SESSION['manage_admins_flash'] = 'New admin created: ' . $form['username'] . '.';
            header('Location: manage_admins.php');
            exit;
        }
    }
}

$flash = $_SESSION['manage_admins_flash'] ?? '';
unset($_SESSION['manage_admins_flash']);

$adminsStmt = $pdo->query(
    'SELECT
        a.id,
        a.name,
        a.username,
        a.created_at,
        (SELECT COUNT(*) FROM notice n WHERE n.admin_id = a.id AND n.is_deleted = 0) AS notice_count
     FROM admin a
     ORDER BY a.created_at DESC, a.id DESC'
);
$admins = $adminsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins | Online Notice Board</title>
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
                <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
                <a href="manage_admins.php" class="sidebar-link active"><i class="fa-solid fa-users-gear"></i> Manage Admins</a>
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
                    <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
                    <a href="manage_admins.php" class="sidebar-link active"><i class="fa-solid fa-users-gear"></i> Manage Admins</a>
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
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400 mb-2">System Admin</p>
                            <h2 class="text-2xl sm:text-3xl font-bold leading-tight">Manage Admin Accounts</h2>
                            <p class="mt-2 text-sm sm:text-base text-slate-600 dark:text-slate-300">Create admin accounts with a default password. Ask them to change it after first login.</p>
                        </div>
                    </div>

                    <button id="theme-toggle" type="button" class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i class="fa-solid fa-sun"></i>
                        <span data-theme-label>Light</span>
                    </button>
                </div>
            </section>

            <?php if ($flash !== ''): ?>
                <div class="mb-5 rounded-2xl border border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800 px-4 py-3 text-sm">
                    <?php echo escape($flash); ?>
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

            <section class="grid xl:grid-cols-[minmax(0,1fr)_380px] gap-5">
                <div class="rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 shadow-2xl p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="text-lg font-semibold">Existing Admins</h3>
                        <span class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400"><?php echo count($admins); ?> total</span>
                    </div>

                    <div class="md:hidden space-y-3">
                        <?php foreach ($admins as $admin): ?>
                            <?php
                                $rowId = (int) $admin['id'];
                                $rowUsername = (string) $admin['username'];
                                $rowNoticeCount = (int) $admin['notice_count'];
                                $isSystemRow = $rowUsername === SYSTEM_ADMIN_USERNAME;
                                $isCurrentUser = $rowId === getAdminId();
                                $canDelete = !$isSystemRow && !$isCurrentUser && $rowNoticeCount === 0;
                            ?>
                            <article class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/50 p-4 space-y-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold"><?php echo escape((string) $admin['name']); ?></p>
                                        <p class="text-sm text-slate-500 dark:text-slate-400 break-all">@<?php echo escape($rowUsername); ?></p>
                                    </div>
                                    <?php if ($isSystemRow): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-blue-600 text-white">System Admin</span>
                                    <?php elseif ($isCurrentUser): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-slate-600 text-white">You</span>
                                    <?php endif; ?>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm">
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Active Notices</p>
                                        <p class="font-semibold mt-1"><?php echo $rowNoticeCount; ?></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white/80 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Created</p>
                                        <p class="font-semibold mt-1"><?php echo date('d M Y', strtotime((string) $admin['created_at'])); ?></p>
                                    </div>
                                </div>

                                <?php if ($canDelete): ?>
                                    <form method="post" onsubmit="return confirm('Remove admin <?php echo escape($rowUsername); ?>?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="delete_admin_id" value="<?php echo $rowId; ?>">
                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-1 rounded-lg border border-red-300 text-red-600 dark:border-red-800 dark:text-red-300 px-3 py-2.5 hover:bg-red-50 dark:hover:bg-red-900/20">
                                            <i class="fa-solid fa-user-xmark"></i> Remove
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        <?php
                                            if ($isSystemRow) {
                                                echo 'Protected';
                                            } elseif ($isCurrentUser) {
                                                echo 'Current account';
                                            } elseif ($rowNoticeCount > 0) {
                                                echo 'Has active notices';
                                            }
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="hidden md:block overflow-x-auto table-scroll rounded-2xl border border-slate-200 dark:border-slate-800">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                <tr>
                                    <th class="text-left px-4 py-3 font-semibold">Name</th>
                                    <th class="text-left px-4 py-3 font-semibold">Username</th>
                                    <th class="text-left px-4 py-3 font-semibold">Notices</th>
                                    <th class="text-left px-4 py-3 font-semibold">Created</th>
                                    <th class="text-left px-4 py-3 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($admins as $admin): ?>
                                    <?php
                                        $rowId = (int) $admin['id'];
                                        $rowUsername = (string) $admin['username'];
                                        $rowNoticeCount = (int) $admin['notice_count'];
                                        $isSystemRow = $rowUsername === SYSTEM_ADMIN_USERNAME;
                                        $isCurrentUser = $rowId === getAdminId();
                                        $canDelete = !$isSystemRow && !$isCurrentUser && $rowNoticeCount === 0;
                                    ?>
                                    <tr>
                                        <td class="px-4 py-3">
                                            <span class="font-medium"><?php echo escape((string) $admin['name']); ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-medium"><?php echo escape($rowUsername); ?></span>
                                            <?php if ($isSystemRow): ?>
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-blue-600 text-white">System Admin</span>
                                            <?php elseif ($isCurrentUser): ?>
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-slate-600 text-white">You</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                            <?php echo $rowNoticeCount; ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                            <?php echo date('d M Y, h:i A', strtotime((string) $admin['created_at'])); ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($canDelete): ?>
                                                <form method="post" onsubmit="return confirm('Remove admin <?php echo escape($rowUsername); ?>?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="delete_admin_id" value="<?php echo $rowId; ?>">
                                                    <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-red-300 text-red-600 dark:border-red-800 dark:text-red-300 px-2.5 py-1.5 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                        <i class="fa-solid fa-user-xmark"></i> Remove
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-500 dark:text-slate-400">
                                                    <?php
                                                        if ($isSystemRow) {
                                                            echo 'Protected';
                                                        } elseif ($isCurrentUser) {
                                                            echo 'Current account';
                                                        } elseif ($rowNoticeCount > 0) {
                                                            echo 'Has active notices';
                                                        }
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 shadow-2xl p-5 sm:p-6">
                    <h3 class="text-lg font-semibold mb-4">Create New Admin</h3>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">
                        <input type="hidden" name="action" value="create">

                        <div>
                            <label for="name" class="text-sm font-medium">Full Name</label>
                            <input id="name" name="name" type="text" required maxlength="100" value="<?php echo escape($form['name']); ?>" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                        </div>

                        <div>
                            <label for="username" class="text-sm font-medium">Username</label>
                            <input id="username" name="username" type="text" required maxlength="30" value="<?php echo escape($form['username']); ?>" placeholder="letters, numbers, ., _, -" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                        </div>

                        <div>
                            <label for="default_password" class="text-sm font-medium">Default Password</label>
                            <input id="default_password" name="default_password" type="password" required minlength="8" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                        </div>

                        <div>
                            <label for="confirm_password" class="text-sm font-medium">Confirm Password</label>
                            <input id="confirm_password" name="confirm_password" type="password" required minlength="8" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                        </div>

                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white px-4 py-2.5 text-sm font-medium shadow-lg shadow-blue-600/30">
                            <i class="fa-solid fa-user-plus"></i> Create Admin
                        </button>

                        <p class="text-xs text-slate-500 dark:text-slate-400">After creation, share username and default password securely. Ask them to change password after login.</p>
                    </form>
                </div>
            </section>
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

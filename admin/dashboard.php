<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');

$pdo = db();
cleanupExpiredNotices($pdo);
$isSystemAdmin = isSystemAdmin();

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$sql = "
    SELECT
        n.id,
        n.title,
        n.description,
        n.createdAt,
        n.expiresAt,
        n.pin,
        n.views,
        n.priority,
        n.visibility,
        c.category_name,
        a.name AS owner_name,
        a.username AS owner_username,
        COALESCE(nf.files_count, 0) AS files_count
     FROM notice n
     INNER JOIN category c ON c.id = n.category
     INNER JOIN admin a ON a.id = n.admin_id
     LEFT JOIN (
        SELECT notice_id, COUNT(*) AS files_count
        FROM notice_files
        GROUP BY notice_id
     ) nf ON nf.notice_id = n.id
     WHERE n.is_deleted = 0
       AND n.expiresAt >= NOW()
";

$params = [];
if (!$isSystemAdmin) {
    $sql .= " AND n.admin_id = :admin_id ";
    $params['admin_id'] = getAdminId();
}

$sql .= " ORDER BY n.pin DESC, n.createdAt DESC ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notices = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | DigiNotify</title>
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
                <h1 class="text-xl font-bold mt-1">DigiNotify</h1>
                <p class="text-sm mt-2 text-slate-500 dark:text-slate-400">Welcome, <?php echo escape(getAdminName()); ?></p>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php" class="sidebar-link active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                <a href="add_notice.php" class="sidebar-link"><i class="fa-solid fa-plus"></i> Add Notice</a>
                <a href="print_notices.php" class="sidebar-link"><i class="fa-solid fa-print"></i> Print Notices</a>
                <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
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
                    <a href="dashboard.php" class="sidebar-link active"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                    <a href="add_notice.php" class="sidebar-link"><i class="fa-solid fa-plus"></i> Add Notice</a>
                    <a href="print_notices.php" class="sidebar-link"><i class="fa-solid fa-print"></i> Print Notices</a>
                    <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
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
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400 mb-2">Control Center</p>
                            <h2 class="text-2xl sm:text-3xl font-bold leading-tight"><?php echo $isSystemAdmin ? 'All Notices Dashboard' : 'My Notices Dashboard'; ?></h2>
                            <p class="mt-2 text-sm sm:text-base text-slate-600 dark:text-slate-300">
                                <?php echo $isSystemAdmin
                                    ? 'As system admin, you can edit/delete notices from all admins.'
                                    : 'Pinned notices stay at the top. Expired notices are archived automatically.'; ?>
                            </p>
                        </div>
                    </div>

                    <div class="flex w-full sm:w-auto flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <button id="theme-toggle" type="button" class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-sun"></i>
                            <span data-theme-label>Light</span>
                        </button>
                        <a href="print_notices.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-print"></i> Print Notices
                        </a>
                        <a href="add_notice.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white px-4 py-2.5 text-sm font-medium shadow-lg shadow-blue-600/30">
                            <i class="fa-solid fa-plus"></i> New Notice
                        </a>
                    </div>
                </div>
            </section>

            <?php if ($flash !== ''): ?>
                <div class="mb-5 rounded-2xl border border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800 px-4 py-3 text-sm">
                    <?php echo escape($flash); ?>
                </div>
            <?php endif; ?>

            <section class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/70 p-4 sm:p-5 shadow-xl">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <h3 class="font-semibold text-lg"><?php echo $isSystemAdmin ? 'Manage All Notices' : 'Manage Notices'; ?></h3>
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400"><?php echo count($notices); ?> active</p>
                </div>

                <div class="mb-4">
                    <input id="dashboard-search" type="text" placeholder="<?php echo $isSystemAdmin ? 'Search by title, description, or owner...' : 'Search your notices by title or description...'; ?>" class="w-full sm:max-w-sm rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                </div>

                <div id="dashboard-mobile-list" class="md:hidden space-y-3">
                    <?php if (!$notices): ?>
                        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                            <?php echo $isSystemAdmin ? 'No active notices found.' : 'No notices found. Create your first notice.'; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notices as $notice): ?>
                            <?php
                                $searchText = strtolower((string) $notice['title']);
                                $searchText .= ' ' . strtolower((string) ($notice['description'] ?? ''));
                                if ($isSystemAdmin) {
                                    $searchText .= ' ' . strtolower((string) $notice['owner_name']) . ' ' . strtolower((string) $notice['owner_username']);
                                }
                                $description = trim((string) ($notice['description'] ?? ''));
                                $descriptionPreview = $description !== '' && mb_strlen($description) > 140
                                    ? mb_substr($description, 0, 137) . '...'
                                    : $description;
                            ?>
                            <article data-card-id="<?php echo (int) $notice['id']; ?>" data-search="<?php echo escape($searchText); ?>" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 p-4 space-y-3 shadow-lg">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold leading-snug"><?php echo escape((string) $notice['title']); ?></p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Created: <?php echo date('d M Y, h:i A', strtotime((string) $notice['createdAt'])); ?></p>
                                    </div>
                                    <span data-mobile-pin-badge class="badge-pill bg-blue-600 text-white inline-flex items-center gap-1 shrink-0 <?php echo (int) $notice['pin'] === 1 ? '' : 'hidden'; ?>">
                                        <i class="fa-solid fa-thumbtack"></i> Pinned
                                    </span>
                                </div>

                                <?php if ($descriptionPreview !== ''): ?>
                                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                                        <?php echo nl2br(escape($descriptionPreview)); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ($isSystemAdmin): ?>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2 text-sm">
                                        <p class="text-slate-500 dark:text-slate-400">Owner</p>
                                        <p class="font-semibold mt-1"><?php echo escape((string) $notice['owner_name']); ?> <span class="text-xs text-slate-500 dark:text-slate-400">(@<?php echo escape((string) $notice['owner_username']); ?>)</span></p>
                                    </div>
                                <?php endif; ?>

                                <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm">
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Category</p>
                                        <p class="font-semibold mt-1"><?php echo escape((string) $notice['category_name']); ?></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Priority</p>
                                        <p class="font-semibold mt-1"><?php echo escape((string) $notice['priority']); ?></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Visibility</p>
                                        <p class="font-semibold mt-1"><?php echo escape((string) ucfirst((string) $notice['visibility'])); ?></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Views / Files</p>
                                        <p class="font-semibold mt-1"><?php echo (int) $notice['views']; ?> / <?php echo (int) $notice['files_count']; ?></p>
                                    </div>
                                </div>

                                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2 text-xs sm:text-sm">
                                    <p class="text-slate-500 dark:text-slate-400">Expires</p>
                                    <p class="font-semibold mt-1"><?php echo date('d M Y, h:i A', strtotime((string) $notice['expiresAt'])); ?></p>
                                </div>

                                <div class="grid grid-cols-1 gap-2">
                                    <button type="button" data-pin-id="<?php echo (int) $notice['id']; ?>" class="w-full inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                                        <i class="fa-solid fa-thumbtack"></i>
                                        <span data-pin-label><?php echo (int) $notice['pin'] === 1 ? 'Unpin' : 'Pin'; ?></span>
                                    </button>
                                    <a href="edit_notice.php?id=<?php echo (int) $notice['id']; ?>" class="w-full inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 dark:border-slate-700 px-3 py-2.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <button type="button" data-delete-id="<?php echo (int) $notice['id']; ?>" class="w-full inline-flex items-center justify-center gap-1 rounded-lg border border-red-300 text-red-600 dark:border-red-800 dark:text-red-300 px-3 py-2.5 hover:bg-red-50 dark:hover:bg-red-900/20">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <div id="dashboard-mobile-search-empty" class="hidden rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">
                            No notices match your search.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="hidden md:block rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/80 dark:bg-slate-900/80 shadow-lg overflow-x-auto table-scroll">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                            <tr>
                                <th class="text-left px-4 py-3 font-semibold">Title</th>
                                <?php if ($isSystemAdmin): ?>
                                    <th class="text-left px-4 py-3 font-semibold">Owner</th>
                                <?php endif; ?>
                                <th class="text-left px-4 py-3 font-semibold">Category</th>
                                <th class="text-left px-4 py-3 font-semibold">Priority</th>
                                <th class="text-left px-4 py-3 font-semibold">Visibility</th>
                                <th class="text-left px-4 py-3 font-semibold">Views</th>
                                <th class="text-left px-4 py-3 font-semibold">Files</th>
                                <th class="text-left px-4 py-3 font-semibold">Expires</th>
                                <th class="text-left px-4 py-3 font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="dashboard-table-body" class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php if (!$notices): ?>
                                <tr>
                                    <td colspan="<?php echo $isSystemAdmin ? '9' : '8'; ?>" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                                        <?php echo $isSystemAdmin ? 'No active notices found.' : 'No notices found. Create your first notice.'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($notices as $notice): ?>
                                    <?php
                                        $searchText = strtolower((string) $notice['title']);
                                        $searchText .= ' ' . strtolower((string) ($notice['description'] ?? ''));
                                        if ($isSystemAdmin) {
                                            $searchText .= ' ' . strtolower((string) $notice['owner_name']) . ' ' . strtolower((string) $notice['owner_username']);
                                        }
                                        $description = trim((string) ($notice['description'] ?? ''));
                                        $descriptionPreview = $description !== '' && mb_strlen($description) > 120
                                            ? mb_substr($description, 0, 117) . '...'
                                            : $description;
                                    ?>
                                    <tr data-row-id="<?php echo (int) $notice['id']; ?>" data-search="<?php echo escape($searchText); ?>">
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium"><?php echo escape((string) $notice['title']); ?></div>
                                            <?php if ($descriptionPreview !== ''): ?>
                                                <div class="text-xs text-slate-500 dark:text-slate-300 mt-1 leading-relaxed">
                                                    <?php echo nl2br(escape($descriptionPreview)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                                Created: <?php echo date('d M Y, h:i A', strtotime((string) $notice['createdAt'])); ?>
                                            </div>
                                            <?php if ((int) $notice['pin'] === 1): ?>
                                                <span class="inline-flex items-center gap-1 mt-2 badge-pill bg-blue-600 text-white text-[11px]">
                                                    <i class="fa-solid fa-thumbtack"></i> Pinned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isSystemAdmin): ?>
                                            <td class="px-4 py-3 align-top">
                                                <div class="font-medium"><?php echo escape((string) $notice['owner_name']); ?></div>
                                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">@<?php echo escape((string) $notice['owner_username']); ?></div>
                                            </td>
                                        <?php endif; ?>
                                        <td class="px-4 py-3 align-top"><?php echo escape((string) $notice['category_name']); ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo escape((string) $notice['priority']); ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo escape((string) ucfirst((string) $notice['visibility'])); ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo (int) $notice['views']; ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo (int) $notice['files_count']; ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo date('d M Y, h:i A', strtotime((string) $notice['expiresAt'])); ?></td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" data-pin-id="<?php echo (int) $notice['id']; ?>" class="inline-flex items-center gap-1 rounded-lg border border-slate-300 dark:border-slate-700 px-2.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                                                    <i class="fa-solid fa-thumbtack"></i>
                                                    <span data-pin-label><?php echo (int) $notice['pin'] === 1 ? 'Unpin' : 'Pin'; ?></span>
                                                </button>
                                                <a href="edit_notice.php?id=<?php echo (int) $notice['id']; ?>" class="inline-flex items-center gap-1 rounded-lg border border-slate-300 dark:border-slate-700 px-2.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
                                                    <i class="fa-solid fa-pen"></i> Edit
                                                </a>
                                                <button type="button" data-delete-id="<?php echo (int) $notice['id']; ?>" class="inline-flex items-center gap-1 rounded-lg border border-red-300 text-red-600 dark:border-red-800 dark:text-red-300 px-2.5 py-1.5 hover:bg-red-50 dark:hover:bg-red-900/20">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr id="dashboard-search-empty-row" class="hidden">
                                    <td colspan="<?php echo $isSystemAdmin ? '9' : '8'; ?>" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                                        No notices match your search.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script>
        window.dashboardConfig = {
            csrfToken: '<?php echo escape(csrfToken()); ?>',
            pinEndpoint: 'pin_notice.php',
            deleteEndpoint: 'delete_notice.php',
            isSystemAdmin: <?php echo $isSystemAdmin ? 'true' : 'false'; ?>
        };
    </script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>

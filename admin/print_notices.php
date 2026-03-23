<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');

function normalizeFilterDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

function formatDateTimeValue(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d M Y, h:i A', $timestamp);
}

function printStatusClass(string $status): string
{
    return $status === 'expired'
        ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
}

function printPriorityClass(string $priority): string
{
    return match ($priority) {
        'High' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'Medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    };
}

function printVisibilityClass(string $visibility): string
{
    return match ($visibility) {
        'students' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'staff' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };
}

$pdo = db();
cleanupExpiredNotices($pdo);

$isSystemAdmin = isSystemAdmin();
$categories = fetchCategories($pdo);
$themeVersion = (string) (@filemtime(__DIR__ . '/../theme.js') ?: time());

$filters = [
    'search' => sanitizeText($_GET['search'] ?? '', 255),
    'category' => isset($_GET['category']) && $_GET['category'] !== '' ? (int) $_GET['category'] : 0,
    'priority' => sanitizeText($_GET['priority'] ?? '', 20),
    'visibility' => sanitizeText($_GET['visibility'] ?? '', 20),
    'status' => sanitizeText($_GET['status'] ?? 'all', 20),
    'from_date' => normalizeFilterDate($_GET['from_date'] ?? ''),
];

if (!isAllowedPriority($filters['priority'])) {
    $filters['priority'] = '';
}

if (!isAllowedVisibility($filters['visibility'])) {
    $filters['visibility'] = '';
}

if (!in_array($filters['status'], ['all', 'active', 'expired'], true)) {
    $filters['status'] = 'all';
}

$branches = [];
$params = [];

if ($filters['status'] !== 'expired') {
    $activeSql = "
        SELECT
            n.id AS record_id,
            n.id AS original_notice_id,
            'active' AS notice_status,
            n.title,
            n.description,
            n.createdAt,
            n.expiresAt,
            n.category,
            c.category_name,
            n.admin_id,
            a.name AS admin_name,
            a.username AS admin_username,
            n.pin,
            n.views,
            n.priority,
            n.visibility,
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
    ";

    $branches[] = $activeSql;
}

if ($filters['status'] !== 'active') {
    $expiredSql = "
        SELECT
            en.id AS record_id,
            en.original_notice_id,
            'expired' AS notice_status,
            en.title,
            en.description,
            en.createdAt,
            en.expiresAt,
            en.category,
            COALESCE(NULLIF(TRIM(en.category_name), ''), 'Archived Category') AS category_name,
            en.admin_id,
            COALESCE(NULLIF(TRIM(en.admin_name), ''), 'Archived Admin') AS admin_name,
            COALESCE(a.username, '') AS admin_username,
            en.pin,
            en.views,
            en.priority,
            en.visibility,
            COALESCE(enf.files_count, 0) AS files_count
        FROM expired_notice en
        LEFT JOIN admin a ON a.id = en.admin_id
        LEFT JOIN (
            SELECT expired_notice_id, COUNT(*) AS files_count
            FROM expired_notice_files
            GROUP BY expired_notice_id
        ) enf ON enf.expired_notice_id = en.id
        WHERE 1 = 1
    ";

    $branches[] = $expiredSql;
}

$notices = [];

if ($branches) {
    $sql = "SELECT * FROM (" . implode("\nUNION ALL\n", $branches) . ") notices WHERE 1 = 1";

    if ($filters['search'] !== '') {
        $sql .= "
            AND (
                notices.title LIKE :search_title
                OR COALESCE(notices.description, '') LIKE :search_description
                OR notices.category_name LIKE :search_category
                OR notices.admin_name LIKE :search_admin
            )
        ";
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search_title'] = $searchTerm;
        $params['search_description'] = $searchTerm;
        $params['search_category'] = $searchTerm;
        $params['search_admin'] = $searchTerm;
    }

    if ($filters['category'] > 0) {
        $sql .= ' AND notices.category = :category ';
        $params['category'] = $filters['category'];
    }

    if ($filters['priority'] !== '') {
        $sql .= ' AND notices.priority = :priority ';
        $params['priority'] = $filters['priority'];
    }

    if ($filters['visibility'] !== '') {
        $sql .= ' AND notices.visibility = :visibility ';
        $params['visibility'] = $filters['visibility'];
    }

    if ($filters['from_date'] !== '') {
        $sql .= ' AND notices.createdAt >= :from_date ';
        $params['from_date'] = $filters['from_date'] . ' 00:00:00';
    }

    $sql .= ' ORDER BY notices.createdAt DESC, notices.pin DESC, notices.expiresAt DESC ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notices = $stmt->fetchAll();
}

$activeCount = 0;
$expiredCount = 0;
foreach ($notices as $notice) {
    if (($notice['notice_status'] ?? '') === 'expired') {
        $expiredCount++;
    } else {
        $activeCount++;
    }
}

$selectedCategoryName = 'All Categories';
if ($filters['category'] > 0) {
    foreach ($categories as $category) {
        if ((int) $category['id'] === $filters['category']) {
            $selectedCategoryName = (string) $category['category_name'];
            break;
        }
    }
}

$summaryBits = [];
if ($filters['search'] !== '') {
    $summaryBits[] = 'Keyword: ' . $filters['search'];
}
if ($filters['category'] > 0) {
    $summaryBits[] = 'Category: ' . $selectedCategoryName;
}
if ($filters['priority'] !== '') {
    $summaryBits[] = 'Priority: ' . $filters['priority'];
}
if ($filters['visibility'] !== '') {
    $summaryBits[] = 'Visibility: ' . ucfirst($filters['visibility']);
}
if ($filters['status'] !== 'all') {
    $summaryBits[] = 'Status: ' . ucfirst($filters['status']);
}
if ($filters['from_date'] !== '') {
    $summaryBits[] = 'Uploaded from: ' . formatDateTimeValue($filters['from_date'] . ' 00:00:00');
}

$summaryText = $summaryBits
    ? implode(' | ', $summaryBits)
    : 'Showing every notice in the system, including archived expired notices.';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Notices | DigiNotify</title>
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
    <script src="../theme.js?v=<?php echo escape($themeVersion); ?>"></script>
    <style>
        .print-only {
            display: none;
        }

        @media print {
            @page {
                size: landscape;
                margin: 12mm;
            }

            html,
            body {
                background: #ffffff !important;
                color: #0f172a !important;
            }

            .no-print {
                display: none !important;
            }

            .print-only {
                display: block !important;
            }

            .print-card {
                border: 0 !important;
                box-shadow: none !important;
                background: #ffffff !important;
            }

            .print-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }

            .print-table th,
            .print-table td {
                border: 1px solid #cbd5e1;
                padding: 6px 8px;
                vertical-align: top;
                text-align: left;
            }

            .print-table th {
                background: #e2e8f0 !important;
                color: #0f172a !important;
                font-weight: 700;
            }

            .print-muted {
                color: #475569 !important;
            }
        }
    </style>
</head>
<body class="h-full text-slate-800 dark:text-slate-100 bg-slate-100 dark:bg-slate-950 transition-colors duration-200">
    <div class="fixed inset-0 -z-10 pointer-events-none opacity-80 dark:opacity-60 no-print">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_20%,rgba(14,165,233,0.28),transparent_35%),radial-gradient(circle_at_85%_10%,rgba(59,130,246,0.2),transparent_30%),radial-gradient(circle_at_20%_85%,rgba(14,116,144,0.16),transparent_40%)]"></div>
    </div>

    <div class="min-h-full lg:grid lg:grid-cols-[270px_1fr]">
        <aside class="hidden lg:block border-r border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/80 p-5 no-print">
            <div class="mb-8">
                <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">Admin Panel</p>
                <h1 class="text-xl font-bold mt-1">DigiNotify</h1>
                <p class="text-sm mt-2 text-slate-500 dark:text-slate-400">Welcome, <?php echo escape(getAdminName()); ?></p>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php" class="sidebar-link"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                <a href="add_notice.php" class="sidebar-link"><i class="fa-solid fa-plus"></i> Add Notice</a>
                <a href="print_notices.php" class="sidebar-link active"><i class="fa-solid fa-print"></i> Print Notices</a>
                <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
                <?php if ($isSystemAdmin): ?>
                    <a href="manage_admins.php" class="sidebar-link"><i class="fa-solid fa-users-gear"></i> Manage Admins</a>
                <?php endif; ?>
                <a href="../index.php" class="sidebar-link"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Site</a>
                <a href="logout.php" class="sidebar-link text-red-600 dark:text-red-400"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </nav>
        </aside>

        <div id="admin-sidebar-overlay" class="fixed inset-0 z-40 bg-slate-950/50 hidden lg:hidden no-print"></div>
        <aside id="admin-sidebar-mobile" class="fixed inset-y-0 left-0 z-50 w-[84%] max-w-xs bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 shadow-2xl transform -translate-x-full transition-transform duration-300 lg:hidden no-print">
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
                    <a href="print_notices.php" class="sidebar-link active"><i class="fa-solid fa-print"></i> Print Notices</a>
                    <a href="change_password.php" class="sidebar-link"><i class="fa-solid fa-key"></i> Change Password</a>
                    <?php if ($isSystemAdmin): ?>
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
            <section class="relative overflow-hidden rounded-3xl border border-slate-200/80 dark:border-slate-800/80 bg-white/80 dark:bg-slate-900/65 p-6 sm:p-8 shadow-xl mb-6 no-print">
                <div class="absolute -right-16 -top-16 w-56 h-56 bg-blue-500/20 blur-3xl rounded-full"></div>
                <div class="absolute -left-16 -bottom-16 w-56 h-56 bg-cyan-500/20 blur-3xl rounded-full"></div>

                <div class="relative flex flex-wrap items-end justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <button type="button" id="open-admin-sidebar" class="lg:hidden inline-flex items-center justify-center h-10 w-10 rounded-xl border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 shrink-0 mt-1">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                        <div>
                            <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400 mb-2">Printable Report</p>
                            <h2 class="text-2xl sm:text-3xl font-bold leading-tight">Search, review, and print notices</h2>
                            <p class="mt-2 text-sm sm:text-base text-slate-600 dark:text-slate-300">
                                Filter by keyword, category, visibility, priority, uploaded date, and include archived expired notices in the same report.
                            </p>
                        </div>
                    </div>

                    <div class="flex w-full sm:w-auto items-center gap-2">
                        <button id="theme-toggle" type="button" class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-sun"></i>
                            <span data-theme-label>Light</span>
                        </button>
                        <a href="dashboard.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/75 p-4 sm:p-6 shadow-xl mb-6 no-print">
                <form method="get" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        <div class="xl:col-span-2">
                            <label for="search" class="text-sm font-medium">Search Notices</label>
                            <div class="mt-2 relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input id="search" name="search" type="text" value="<?php echo escape($filters['search']); ?>" placeholder="Search by title, description, category, or owner..." class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                            </div>
                        </div>

                        <div>
                            <label for="from_date" class="text-sm font-medium">Uploaded From</label>
                            <input id="from_date" name="from_date" type="date" value="<?php echo escape($filters['from_date']); ?>" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Notices older than this date are excluded.</p>
                        </div>

                        <div>
                            <label for="category" class="text-sm font-medium">Category</label>
                            <select id="category" name="category" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo (int) $category['id']; ?>" <?php echo $filters['category'] === (int) $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo escape((string) $category['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="priority" class="text-sm font-medium">Priority</label>
                            <select id="priority" name="priority" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="">All Priorities</option>
                                <?php foreach (['High', 'Medium', 'Low'] as $priority): ?>
                                    <option value="<?php echo escape($priority); ?>" <?php echo $filters['priority'] === $priority ? 'selected' : ''; ?>>
                                        <?php echo escape($priority); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="visibility" class="text-sm font-medium">Visibility</label>
                            <select id="visibility" name="visibility" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="">All Visibility</option>
                                <option value="public" <?php echo $filters['visibility'] === 'public' ? 'selected' : ''; ?>>Public</option>
                                <option value="students" <?php echo $filters['visibility'] === 'students' ? 'selected' : ''; ?>>Students</option>
                                <option value="staff" <?php echo $filters['visibility'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            </select>
                        </div>

                        <div>
                            <label for="status" class="text-sm font-medium">Notice Scope</label>
                            <select id="status" name="status" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="all" <?php echo $filters['status'] === 'all' ? 'selected' : ''; ?>>Active + Expired</option>
                                <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Only Active</option>
                                <option value="expired" <?php echo $filters['status'] === 'expired' ? 'selected' : ''; ?>>Only Expired</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white px-5 py-3 text-sm font-medium shadow-lg shadow-blue-600/30">
                            <i class="fa-solid fa-filter"></i> Apply Filters
                        </button>
                        <a href="print_notices.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 px-5 py-3 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-rotate-left"></i> Reset Filters
                        </a>
                        <button type="button" id="print-results-button" <?php echo $notices ? '' : 'disabled'; ?> class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 px-5 py-3 text-sm hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-print"></i> Print Results
                        </button>
                    </div>
                </form>
            </section>

            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 no-print">
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/75 p-4 shadow-lg">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Matched Notices</p>
                    <p class="mt-2 text-2xl font-bold"><?php echo count($notices); ?></p>
                </div>
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/75 p-4 shadow-lg">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active Included</p>
                    <p class="mt-2 text-2xl font-bold"><?php echo $activeCount; ?></p>
                </div>
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/75 p-4 shadow-lg">
                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Expired Included</p>
                    <p class="mt-2 text-2xl font-bold"><?php echo $expiredCount; ?></p>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/75 p-4 sm:p-6 shadow-xl print-card">
                <div class="no-print mb-5">
                    <p class="text-xs uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400 mb-2">Current Report</p>
                    <h3 class="text-xl font-semibold">Filtered Notice Results</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><?php echo escape($summaryText); ?></p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Use the print button to save this result set as a PDF or print it on paper.</p>
                </div>

                <div class="print-only mb-4">
                    <h1 class="text-2xl font-bold">DigiNotify Report</h1>
                    <p class="print-muted" style="margin-top: 6px;">Generated by <?php echo escape(getAdminName()); ?> on <?php echo escape(date('d M Y, h:i A')); ?></p>
                    <p class="print-muted" style="margin-top: 4px;"><?php echo escape($summaryText); ?></p>
                </div>

                <?php if (!$notices): ?>
                    <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 px-4 py-12 text-center no-print">
                        <div class="mx-auto h-14 w-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                            <i class="fa-regular fa-folder-open text-slate-500 dark:text-slate-300"></i>
                        </div>
                        <p class="text-base font-semibold">No notices match your filters.</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try a broader keyword, remove a filter, or adjust the uploaded-from date.</p>
                    </div>
                    <div class="print-only">
                        <p>No notices matched the selected filters.</p>
                    </div>
                <?php else: ?>
                    <div class="md:hidden space-y-3 no-print">
                        <?php foreach ($notices as $notice): ?>
                            <?php
                                $description = trim((string) ($notice['description'] ?? ''));
                                $descriptionPreview = $description !== '' && mb_strlen($description) > 180
                                    ? mb_substr($description, 0, 177) . '...'
                                    : $description;
                                $ownerLabel = trim((string) ($notice['admin_name'] ?? ''));
                                $ownerUsername = trim((string) ($notice['admin_username'] ?? ''));
                            ?>
                            <article class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 p-4 space-y-3 shadow-lg">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold leading-snug"><?php echo escape((string) $notice['title']); ?></p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Uploaded: <?php echo escape(formatDateTimeValue((string) ($notice['createdAt'] ?? ''))); ?></p>
                                    </div>
                                    <span class="badge-pill <?php echo printStatusClass((string) $notice['notice_status']); ?>">
                                        <?php echo escape(ucfirst((string) $notice['notice_status'])); ?>
                                    </span>
                                </div>

                                <?php if ($descriptionPreview !== ''): ?>
                                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                                        <?php echo nl2br(escape($descriptionPreview)); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="flex flex-wrap gap-2 text-xs">
                                    <span class="badge-pill <?php echo printPriorityClass((string) $notice['priority']); ?>"><?php echo escape((string) $notice['priority']); ?></span>
                                    <span class="badge-pill <?php echo printVisibilityClass((string) $notice['visibility']); ?>"><?php echo escape(ucfirst((string) $notice['visibility'])); ?></span>
                                    <span class="badge-pill bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><?php echo escape((string) $notice['category_name']); ?></span>
                                    <?php if ((int) ($notice['pin'] ?? 0) === 1): ?>
                                        <span class="badge-pill bg-blue-600 text-white"><i class="fa-solid fa-thumbtack mr-1"></i>Pinned</span>
                                    <?php endif; ?>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-xs sm:text-sm">
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Expires</p>
                                        <p class="font-semibold mt-1"><?php echo escape(formatDateTimeValue((string) ($notice['expiresAt'] ?? ''))); ?></p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2">
                                        <p class="text-slate-500 dark:text-slate-400">Files / Views</p>
                                        <p class="font-semibold mt-1"><?php echo (int) ($notice['files_count'] ?? 0); ?> / <?php echo (int) ($notice['views'] ?? 0); ?></p>
                                    </div>
                                </div>

                                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-950/50 px-3 py-2 text-sm">
                                    <p class="text-slate-500 dark:text-slate-400">Published By</p>
                                    <p class="font-semibold mt-1">
                                        <?php echo escape($ownerLabel !== '' ? $ownerLabel : 'Unknown'); ?>
                                        <?php if ($ownerUsername !== ''): ?>
                                            <span class="text-xs text-slate-500 dark:text-slate-400">(@<?php echo escape($ownerUsername); ?>)</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="hidden md:block overflow-x-auto no-print">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                <tr>
                                    <th class="text-left px-4 py-3 font-semibold">Title</th>
                                    <th class="text-left px-4 py-3 font-semibold">Status</th>
                                    <th class="text-left px-4 py-3 font-semibold">Category</th>
                                    <th class="text-left px-4 py-3 font-semibold">Priority</th>
                                    <th class="text-left px-4 py-3 font-semibold">Visibility</th>
                                    <th class="text-left px-4 py-3 font-semibold">Uploaded</th>
                                    <th class="text-left px-4 py-3 font-semibold">Expires</th>
                                    <th class="text-left px-4 py-3 font-semibold">Published By</th>
                                    <th class="text-left px-4 py-3 font-semibold">Files</th>
                                    <th class="text-left px-4 py-3 font-semibold">Views</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php foreach ($notices as $notice): ?>
                                    <?php
                                        $description = trim((string) ($notice['description'] ?? ''));
                                        $descriptionPreview = $description !== '' && mb_strlen($description) > 120
                                            ? mb_substr($description, 0, 117) . '...'
                                            : $description;
                                        $ownerLabel = trim((string) ($notice['admin_name'] ?? ''));
                                        $ownerUsername = trim((string) ($notice['admin_username'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium"><?php echo escape((string) $notice['title']); ?></div>
                                            <?php if ($descriptionPreview !== ''): ?>
                                                <div class="text-xs text-slate-500 dark:text-slate-300 mt-1 leading-relaxed">
                                                    <?php echo nl2br(escape($descriptionPreview)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ((int) ($notice['pin'] ?? 0) === 1): ?>
                                                <span class="inline-flex items-center gap-1 mt-2 badge-pill bg-blue-600 text-white text-[11px]">
                                                    <i class="fa-solid fa-thumbtack"></i> Pinned
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <span class="badge-pill <?php echo printStatusClass((string) $notice['notice_status']); ?>">
                                                <?php echo escape(ucfirst((string) $notice['notice_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top"><?php echo escape((string) $notice['category_name']); ?></td>
                                        <td class="px-4 py-3 align-top">
                                            <span class="badge-pill <?php echo printPriorityClass((string) $notice['priority']); ?>">
                                                <?php echo escape((string) $notice['priority']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <span class="badge-pill <?php echo printVisibilityClass((string) $notice['visibility']); ?>">
                                                <?php echo escape(ucfirst((string) $notice['visibility'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top"><?php echo escape(formatDateTimeValue((string) ($notice['createdAt'] ?? ''))); ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo escape(formatDateTimeValue((string) ($notice['expiresAt'] ?? ''))); ?></td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium"><?php echo escape($ownerLabel !== '' ? $ownerLabel : 'Unknown'); ?></div>
                                            <?php if ($ownerUsername !== ''): ?>
                                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">@<?php echo escape($ownerUsername); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 align-top"><?php echo (int) ($notice['files_count'] ?? 0); ?></td>
                                        <td class="px-4 py-3 align-top"><?php echo (int) ($notice['views'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="print-only">
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Notice Title</th>
                                    <th>Status</th>
                                    <th>Category</th>
                                    <th>Priority</th>
                                    <th>Visibility</th>
                                    <th>Uploaded Date</th>
                                    <th>Expiry Date</th>
                                    <th>Published By</th>
                                    <th>Pinned</th>
                                    <th>Views</th>
                                    <th>Files</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notices as $notice): ?>
                                    <?php
                                        $ownerLabel = trim((string) ($notice['admin_name'] ?? ''));
                                        $ownerUsername = trim((string) ($notice['admin_username'] ?? ''));
                                        $ownerText = $ownerLabel !== '' ? $ownerLabel : 'Unknown';
                                        if ($ownerUsername !== '') {
                                            $ownerText .= ' (@' . $ownerUsername . ')';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo escape((string) $notice['title']); ?></td>
                                        <td><?php echo escape(ucfirst((string) $notice['notice_status'])); ?></td>
                                        <td><?php echo escape((string) $notice['category_name']); ?></td>
                                        <td><?php echo escape((string) $notice['priority']); ?></td>
                                        <td><?php echo escape(ucfirst((string) $notice['visibility'])); ?></td>
                                        <td><?php echo escape(formatDateTimeValue((string) ($notice['createdAt'] ?? ''))); ?></td>
                                        <td><?php echo escape(formatDateTimeValue((string) ($notice['expiresAt'] ?? ''))); ?></td>
                                        <td><?php echo escape($ownerText); ?></td>
                                        <td><?php echo (int) ($notice['pin'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td>
                                        <td><?php echo (int) ($notice['views'] ?? 0); ?></td>
                                        <td><?php echo (int) ($notice['files_count'] ?? 0); ?></td>
                                        <td><?php echo nl2br(escape(trim((string) ($notice['description'] ?? '')) !== '' ? (string) $notice['description'] : '-')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        (function () {
            const sidebar = document.getElementById('admin-sidebar-mobile');
            const overlay = document.getElementById('admin-sidebar-overlay');
            const openBtn = document.getElementById('open-admin-sidebar');
            const closeBtns = document.querySelectorAll('[data-close-admin-sidebar]');
            const printBtn = document.getElementById('print-results-button');

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

            if (window.onbTheme && typeof window.onbTheme.initThemeToggle === 'function') {
                window.onbTheme.initThemeToggle('theme-toggle');
                window.onbTheme.initThemeToggle('theme-toggle-mobile');
            }

            if (printBtn) {
                printBtn.addEventListener('click', function () {
                    window.print();
                });
            }
        })();
    </script>
</body>
</html>

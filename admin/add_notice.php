<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');

$pdo = db();
cleanupExpiredNotices($pdo);
$categories = fetchCategories($pdo);
$categoryIds = array_map(static fn(array $cat): int => (int) $cat['id'], $categories);

$errors = [];
$form = [
    'title' => '',
    'category' => '',
    'expiresAt' => '',
    'priority' => 'Low',
    'visibility' => 'public',
    'pin' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    $form['title'] = sanitizeText($_POST['title'] ?? '', 255);
    $form['category'] = (string) (int) ($_POST['category'] ?? 0);
    $form['expiresAt'] = sanitizeText($_POST['expiresAt'] ?? '', 25);
    $form['priority'] = sanitizeText($_POST['priority'] ?? 'Low', 10);
    $form['visibility'] = sanitizeText($_POST['visibility'] ?? 'public', 20);
    $form['pin'] = isset($_POST['pin']) ? 1 : 0;

    if (!verifyCsrf(is_string($token) ? $token : null)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    if ($form['title'] === '') {
        $errors[] = 'Notice title is required.';
    }

    $category = (int) $form['category'];
    if (!in_array($category, $categoryIds, true)) {
        $errors[] = 'Please select a valid category.';
    }

    $expiresAt = DateTime::createFromFormat('Y-m-d\TH:i', $form['expiresAt']);
    if (!$expiresAt) {
        $errors[] = 'Please provide a valid expiry date and time.';
    } elseif ($expiresAt <= new DateTime()) {
        $errors[] = 'Expiry date and time must be in the future.';
    }

    if (!isAllowedPriority($form['priority'])) {
        $errors[] = 'Invalid priority value.';
    }

    if (!isAllowedVisibility($form['visibility'])) {
        $errors[] = 'Invalid visibility value.';
    }

    $uploads = processUploadedFiles($_FILES['attachments'] ?? []);
    if ($uploads['errors']) {
        foreach ($uploads['errors'] as $uploadError) {
            $errors[] = $uploadError;
        }
    }

    if (!$errors && $expiresAt instanceof DateTime) {
        $expiresFormatted = $expiresAt->format('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO notice (title, category, expiresAt, file, admin_id, pin, priority, visibility)
                 VALUES (:title, :category, :expiresAt, :file, :admin_id, :pin, :priority, :visibility)'
            );

            $primaryFile = $uploads['paths'][0] ?? null;

            $stmt->execute([
                'title' => $form['title'],
                'category' => $category,
                'expiresAt' => $expiresFormatted,
                'file' => $primaryFile,
                'admin_id' => getAdminId(),
                'pin' => $form['pin'],
                'priority' => $form['priority'],
                'visibility' => $form['visibility'],
            ]);

            $noticeId = (int) $pdo->lastInsertId();

            if ($uploads['paths']) {
                $fileStmt = $pdo->prepare('INSERT INTO notice_files (notice_id, file_path) VALUES (:notice_id, :file_path)');
                foreach ($uploads['paths'] as $path) {
                    $fileStmt->execute([
                        'notice_id' => $noticeId,
                        'file_path' => $path,
                    ]);
                }
            }

            $notificationStmt = $pdo->prepare('INSERT INTO notifications (notice_id, message) VALUES (:notice_id, :message)');
            $notificationStmt->execute([
                'notice_id' => $noticeId,
                'message' => 'New notice published: ' . $form['title'],
            ]);

            $pdo->commit();

            $_SESSION['flash_message'] = 'Notice created successfully.';
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($uploads['paths'] as $savedPath) {
                removeFileByPath($savedPath);
            }

            $errors[] = 'Failed to create notice. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Notice | Online Notice Board</title>
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
    <div class="fixed inset-0 -z-10 pointer-events-none">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_12%_20%,rgba(14,165,233,0.2),transparent_35%),radial-gradient(circle_at_85%_10%,rgba(59,130,246,0.2),transparent_32%)]"></div>
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
                <a href="add_notice.php" class="sidebar-link active"><i class="fa-solid fa-plus"></i> Add Notice</a>
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
                    <a href="dashboard.php" class="sidebar-link"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
                    <a href="add_notice.php" class="sidebar-link active"><i class="fa-solid fa-plus"></i> Add Notice</a>
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
            <div class="flex items-center justify-between gap-3 mb-6">
                <div class="flex items-center gap-3">
                    <button type="button" id="open-admin-sidebar" class="lg:hidden inline-flex items-center justify-center h-10 w-10 rounded-xl border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div>
                        <h2 class="text-2xl font-bold">Add New Notice</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Create and publish a polished announcement with files, priority, and visibility.</p>
                    </div>
                </div>
                <button id="theme-toggle" type="button" class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                    <i class="fa-solid fa-sun"></i>
                    <span data-theme-label>Light</span>
                </button>
            </div>

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

            <form method="post" enctype="multipart/form-data" class="relative overflow-hidden rounded-3xl border border-slate-200 dark:border-slate-800 bg-white/90 dark:bg-slate-900/75 shadow-2xl">
                <div class="absolute -top-20 -right-16 h-56 w-56 rounded-full bg-blue-500/20 blur-3xl"></div>
                <div class="absolute -bottom-24 -left-16 h-56 w-56 rounded-full bg-cyan-500/20 blur-3xl"></div>

                <div class="relative p-5 sm:p-6 lg:p-8 space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">

                    <section class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/75 dark:bg-slate-950/55 p-4 sm:p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-blue-600 to-cyan-500 text-white flex items-center justify-center">
                                <i class="fa-solid fa-heading"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-lg">Notice Content</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Start with a clear title and target category.</p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label for="title" class="text-sm font-medium">Notice Title</label>
                                <input id="title" name="title" type="text" required maxlength="255" value="<?php echo escape($form['title']); ?>" placeholder="Example: Mid-term exam schedule updated" class="mt-2 w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                            </div>

                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="category" class="text-sm font-medium">Category</label>
                                    <select id="category" name="category" required class="mt-2 w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <option value="">Select</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo (int) $category['id']; ?>" <?php echo ((int) $form['category'] === (int) $category['id']) ? 'selected' : ''; ?>>
                                                <?php echo escape((string) $category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="expiresAt" class="text-sm font-medium">Expiry Date & Time</label>
                                    <input id="expiresAt" name="expiresAt" type="datetime-local" required value="<?php echo escape($form['expiresAt']); ?>" class="mt-2 w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/75 dark:bg-slate-950/55 p-4 sm:p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-violet-600 to-fuchsia-500 text-white flex items-center justify-center">
                                <i class="fa-solid fa-sliders"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-lg">Audience & Priority</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Control notice urgency, visibility, and pin state.</p>
                            </div>
                        </div>

                        <div class="grid sm:grid-cols-3 gap-4">
                            <div>
                                <label for="priority" class="text-sm font-medium">Priority</label>
                                <select id="priority" name="priority" class="mt-2 w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                    <?php foreach (['Low', 'Medium', 'High'] as $priority): ?>
                                        <option value="<?php echo $priority; ?>" <?php echo $form['priority'] === $priority ? 'selected' : ''; ?>><?php echo $priority; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="visibility" class="text-sm font-medium">Visibility</label>
                                <select id="visibility" name="visibility" class="mt-2 w-full rounded-2xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                    <?php foreach (['public', 'students', 'staff'] as $visibility): ?>
                                        <option value="<?php echo $visibility; ?>" <?php echo $form['visibility'] === $visibility ? 'selected' : ''; ?>><?php echo ucfirst($visibility); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="flex items-end">
                                <label class="inline-flex items-center gap-3 rounded-2xl border border-slate-300 dark:border-slate-700 px-4 py-3 w-full bg-white dark:bg-slate-900">
                                    <input type="checkbox" name="pin" <?php echo (int) $form['pin'] === 1 ? 'checked' : ''; ?> class="h-4 w-4 rounded border-slate-400 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm font-medium">Pin this notice</span>
                                </label>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/75 dark:bg-slate-950/55 p-4 sm:p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="h-10 w-10 rounded-xl bg-gradient-to-br from-emerald-600 to-teal-500 text-white flex items-center justify-center">
                                <i class="fa-solid fa-paperclip"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-lg">Attachments</h3>
                                <p class="text-sm text-slate-500 dark:text-slate-400">Upload one or more files with safe extensions.</p>
                            </div>
                        </div>

                        <label for="attachments" class="flex flex-col items-center justify-center text-center rounded-2xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/60 px-4 py-8 cursor-pointer hover:border-blue-500 hover:bg-blue-50/60 dark:hover:bg-blue-900/15 transition-colors">
                            <span class="h-12 w-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center mb-3">
                                <i class="fa-solid fa-upload"></i>
                            </span>
                            <span class="font-medium">Click to choose files</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 mt-1">PDF, DOC, DOCX, PNG, JPG, JPEG, GIF, WEBP (max 8MB each)</span>
                            <input
                                id="attachments"
                                name="attachments[]"
                                type="file"
                                multiple
                                accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp"
                                class="hidden"
                                data-attachment-preview="attachments-preview"
                                data-attachment-count="attachments-selected-count"
                            >
                        </label>
                        <p id="attachments-selected-count" class="text-xs text-slate-500 dark:text-slate-400 mt-3">No files selected yet.</p>
                        <div id="attachments-preview" class="hidden mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
                    </section>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 pt-1">
                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white px-5 py-3 text-sm font-medium shadow-lg shadow-blue-600/30">
                            <i class="fa-solid fa-paper-plane"></i> Publish Notice
                        </button>
                        <a href="dashboard.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 dark:border-slate-700 px-5 py-3 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="../assets/js/attachment-preview.js"></script>
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeText($_POST['username'] ?? '', 100);
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrf(is_string($token) ? $token : null)) {
        $error = 'Invalid form token. Please refresh and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, name, username, password FROM admin WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $admin = $stmt->fetch();

        if ($admin) {
            $passwordCheck = verifyAdminPassword(
                $password,
                (string) $admin['password'],
                (string) $admin['username']
            );
            $isValid = (bool) $passwordCheck['valid'];
            $shouldUpgradePassword = (bool) $passwordCheck['needs_rehash'];

            if ($isValid) {
                if ($shouldUpgradePassword) {
                    $rehashStmt = $pdo->prepare('UPDATE admin SET password = :password WHERE id = :id');
                    $rehashStmt->execute([
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => (int) $admin['id'],
                    ]);
                }

                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_name'] = (string) $admin['name'];
                $_SESSION['admin_username'] = (string) $admin['username'];

                header('Location: dashboard.php');
                exit;
            }
        }

        $error = 'Invalid login credentials.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Online Notice Board</title>
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
<body class="h-full flex items-center justify-center px-4 py-10 text-slate-800 dark:text-slate-100 dark:bg-slate-900 transition-colors duration-200">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-900/70 shadow-2xl p-6 sm:p-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">Secure Panel</p>
                <h1 class="text-2xl font-bold">Admin Login</h1>
            </div>
            <button id="theme-toggle" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                <i class="fa-solid fa-sun"></i>
                <span data-theme-label>Light</span>
            </button>
        </div>

        <?php if ($error !== ''): ?>
            <div class="mb-4 rounded-lg border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
                <?php echo escape($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo escape(csrfToken()); ?>">
            <div>
                <label for="username" class="text-sm font-medium">Username</label>
                <input id="username" name="username" type="text" required maxlength="100" class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
            </div>
            <div>
                <label for="password" class="text-sm font-medium">Password</label>
                <input id="password" name="password" type="password" required class="mt-2 w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
            </div>
            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium">
                <i class="fa-solid fa-right-to-bracket"></i> Login
            </button>
        </form>

        <a href="../index.php" class="mt-5 inline-flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline">
            <i class="fa-solid fa-arrow-left"></i> Back to Notice Board
        </a>
    </div>

    <script>
        if (window.onbTheme && typeof window.onbTheme.initThemeToggle === 'function') {
            window.onbTheme.initThemeToggle('theme-toggle');
        }
    </script>
</body>
</html>


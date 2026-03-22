<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DB_HOST = '127.0.0.1';
const DB_NAME = 'online_notice_board';
const DB_USER = 'root';
const DB_PASS = '';
const SYSTEM_ADMIN_USERNAME = 'admin';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    ensureNoticeDescriptionColumn($pdo);
    ensureExpiredNoticeTables($pdo);

    return $pdo;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['admin_id']);
}

function requireAdmin(string $loginPath = 'admin/login.php'): void
{
    if (!isLoggedIn()) {
        header('Location: ' . $loginPath);
        exit;
    }
}

function getAdminId(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function getAdminName(): string
{
    return (string) ($_SESSION['admin_name'] ?? '');
}

function getAdminUsername(): string
{
    return (string) ($_SESSION['admin_username'] ?? '');
}

function isSystemAdmin(): bool
{
    return isLoggedIn() && getAdminUsername() === SYSTEM_ADMIN_USERNAME;
}

function requireSystemAdmin(string $redirectPath = 'dashboard.php'): void
{
    if (!isSystemAdmin()) {
        $_SESSION['flash_message'] = 'Only the system admin can access that page.';
        header('Location: ' . $redirectPath);
        exit;
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

function verifyAdminPassword(string $inputPassword, string $storedPassword, string $username = ''): array
{
    $legacyWrongDefaultHash = '0192023a7bbd73250516f069df18b500';
    $valid = false;
    $needsRehash = false;

    if (password_verify($inputPassword, $storedPassword)) {
        $valid = true;
        $needsRehash = password_needs_rehash($storedPassword, PASSWORD_DEFAULT);
    } elseif (hash_equals($storedPassword, md5($inputPassword))) {
        $valid = true;
        $needsRehash = true;
    } elseif (hash_equals($storedPassword, $inputPassword)) {
        $valid = true;
        $needsRehash = true;
    } elseif (
        $username === SYSTEM_ADMIN_USERNAME
        && $inputPassword === 'admin@123'
        && hash_equals($storedPassword, $legacyWrongDefaultHash)
    ) {
        $valid = true;
        $needsRehash = true;
    }

    return [
        'valid' => $valid,
        'needs_rehash' => $needsRehash,
    ];
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function sanitizeText(?string $value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

function sanitizeMultilineText(?string $value, int $maxLength = 5000): string
{
    $value = trim((string) $value);
    // Keep line breaks/tabs for textarea content while stripping unsafe control chars.
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

function ensureNoticeDescriptionColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $checkStmt = $pdo->query("SHOW COLUMNS FROM notice LIKE 'description'");
        $exists = (bool) $checkStmt->fetch();
        if (!$exists) {
            $pdo->exec('ALTER TABLE notice ADD COLUMN description TEXT NULL AFTER title');
        }
    } catch (Throwable $e) {
        // Do not block app startup if schema migration cannot run here.
    }
}

function ensureExpiredNoticeTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS expired_notice (
                id INT PRIMARY KEY AUTO_INCREMENT,
                original_notice_id INT NOT NULL UNIQUE,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                category INT NULL,
                category_name VARCHAR(100) NULL,
                createdAt DATETIME NULL,
                expiresAt DATETIME NOT NULL,
                file VARCHAR(255) NULL,
                admin_id INT NULL,
                admin_name VARCHAR(100) NULL,
                pin TINYINT DEFAULT 0,
                views INT DEFAULT 0,
                priority ENUM('Low', 'Medium', 'High') DEFAULT 'Low',
                visibility ENUM('public', 'students', 'staff') DEFAULT 'public',
                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS expired_notice_files (
                id INT PRIMARY KEY AUTO_INCREMENT,
                expired_notice_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                CONSTRAINT fk_expired_notice_files_notice
                    FOREIGN KEY (expired_notice_id) REFERENCES expired_notice(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        // Do not block app startup if archive table setup cannot run here.
    }
}

function normalizeNoticeIds(array $noticeIds): array
{
    $normalized = [];

    foreach ($noticeIds as $noticeId) {
        $id = (int) $noticeId;
        if ($id > 0) {
            $normalized[] = $id;
        }
    }

    return array_values(array_unique($normalized));
}

function sqlInPlaceholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

function fetchNoticeAttachmentPaths(PDO $pdo, array $noticeIds): array
{
    $noticeIds = normalizeNoticeIds($noticeIds);
    if (!$noticeIds) {
        return [];
    }

    $placeholders = sqlInPlaceholders(count($noticeIds));
    $paths = [];

    $legacyStmt = $pdo->prepare(
        'SELECT file FROM notice WHERE id IN (' . $placeholders . ') AND file IS NOT NULL AND file <> ""'
    );
    $legacyStmt->execute($noticeIds);
    $legacyPaths = $legacyStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($legacyPaths as $path) {
        $path = trim((string) $path);
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    $filesStmt = $pdo->prepare(
        'SELECT file_path FROM notice_files WHERE notice_id IN (' . $placeholders . ')'
    );
    $filesStmt->execute($noticeIds);
    $fileRows = $filesStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fileRows as $path) {
        $path = trim((string) $path);
        if ($path !== '') {
            $paths[] = $path;
        }
    }

    return array_values(array_unique($paths));
}

function detachNoticeAttachments(PDO $pdo, array $noticeIds): array
{
    $noticeIds = normalizeNoticeIds($noticeIds);
    if (!$noticeIds) {
        return [];
    }

    $placeholders = sqlInPlaceholders(count($noticeIds));
    $paths = fetchNoticeAttachmentPaths($pdo, $noticeIds);

    $deleteFilesStmt = $pdo->prepare(
        'DELETE FROM notice_files WHERE notice_id IN (' . $placeholders . ')'
    );
    $deleteFilesStmt->execute($noticeIds);

    $clearPrimaryStmt = $pdo->prepare(
        'UPDATE notice SET file = NULL WHERE id IN (' . $placeholders . ')'
    );
    $clearPrimaryStmt->execute($noticeIds);

    return $paths;
}

function removeFilesFromDisk(array $paths): void
{
    $paths = array_values(array_unique(array_filter(array_map(static function ($path): string {
        return trim((string) $path);
    }, $paths), static function (string $path): bool {
        return $path !== '';
    })));

    foreach ($paths as $path) {
        removeFileByPath($path);
    }
}

function cleanupExpiredNotices(PDO $pdo): void
{
    $activeExpiredNoticesStmt = $pdo->query(
        "SELECT
            n.id,
            n.title,
            n.description,
            n.category,
            c.category_name,
            n.createdAt,
            n.expiresAt,
            n.file,
            n.admin_id,
            a.name AS admin_name,
            n.pin,
            n.views,
            n.priority,
            n.visibility,
            n.updated_at
         FROM notice n
         LEFT JOIN category c ON c.id = n.category
         LEFT JOIN admin a ON a.id = n.admin_id
         WHERE n.is_deleted = 0
           AND n.expiresAt < NOW()"
    );
    $activeExpiredNotices = $activeExpiredNoticesStmt->fetchAll();
    $activeExpiredIds = normalizeNoticeIds(array_column($activeExpiredNotices, 'id'));

    $softDeletedExpiredIdsStmt = $pdo->query(
        'SELECT id FROM notice WHERE is_deleted = 1 AND expiresAt < NOW()'
    );
    $softDeletedExpiredIds = normalizeNoticeIds($softDeletedExpiredIdsStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$activeExpiredIds && !$softDeletedExpiredIds) {
        return;
    }

    $filesByNoticeId = [];
    if ($activeExpiredIds) {
        $placeholders = sqlInPlaceholders(count($activeExpiredIds));
        $fileRowsStmt = $pdo->prepare(
            'SELECT notice_id, file_path FROM notice_files WHERE notice_id IN (' . $placeholders . ') ORDER BY id ASC'
        );
        $fileRowsStmt->execute($activeExpiredIds);
        $fileRows = $fileRowsStmt->fetchAll();

        foreach ($fileRows as $fileRow) {
            $noticeId = (int) ($fileRow['notice_id'] ?? 0);
            if ($noticeId <= 0) {
                continue;
            }

            if (!isset($filesByNoticeId[$noticeId])) {
                $filesByNoticeId[$noticeId] = [];
            }

            $path = trim((string) ($fileRow['file_path'] ?? ''));
            if ($path !== '') {
                $filesByNoticeId[$noticeId][] = $path;
            }
        }
    }

    try {
        $pdo->beginTransaction();

        if ($activeExpiredIds) {
            $archiveStmt = $pdo->prepare(
                'INSERT INTO expired_notice (
                    original_notice_id,
                    title,
                    description,
                    category,
                    category_name,
                    createdAt,
                    expiresAt,
                    file,
                    admin_id,
                    admin_name,
                    pin,
                    views,
                    priority,
                    visibility,
                    updated_at
                ) VALUES (
                    :original_notice_id,
                    :title,
                    :description,
                    :category,
                    :category_name,
                    :createdAt,
                    :expiresAt,
                    :file,
                    :admin_id,
                    :admin_name,
                    :pin,
                    :views,
                    :priority,
                    :visibility,
                    :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    category = VALUES(category),
                    category_name = VALUES(category_name),
                    createdAt = VALUES(createdAt),
                    expiresAt = VALUES(expiresAt),
                    file = VALUES(file),
                    admin_id = VALUES(admin_id),
                    admin_name = VALUES(admin_name),
                    pin = VALUES(pin),
                    views = VALUES(views),
                    priority = VALUES(priority),
                    visibility = VALUES(visibility),
                    updated_at = VALUES(updated_at)'
            );

            $expiredNoticeIdStmt = $pdo->prepare(
                'SELECT id FROM expired_notice WHERE original_notice_id = :original_notice_id LIMIT 1'
            );
            $clearExpiredFilesStmt = $pdo->prepare(
                'DELETE FROM expired_notice_files WHERE expired_notice_id = :expired_notice_id'
            );
            $insertExpiredFileStmt = $pdo->prepare(
                'INSERT INTO expired_notice_files (expired_notice_id, file_path) VALUES (:expired_notice_id, :file_path)'
            );

            foreach ($activeExpiredNotices as $expiredNotice) {
                $originalNoticeId = (int) ($expiredNotice['id'] ?? 0);
                if ($originalNoticeId <= 0) {
                    continue;
                }

                $archiveStmt->execute([
                    'original_notice_id' => $originalNoticeId,
                    'title' => (string) ($expiredNotice['title'] ?? ''),
                    'description' => (string) ($expiredNotice['description'] ?? ''),
                    'category' => isset($expiredNotice['category']) ? (int) $expiredNotice['category'] : null,
                    'category_name' => ($expiredNotice['category_name'] ?? null) !== null ? (string) $expiredNotice['category_name'] : null,
                    'createdAt' => ($expiredNotice['createdAt'] ?? null) !== null ? (string) $expiredNotice['createdAt'] : null,
                    'expiresAt' => (string) ($expiredNotice['expiresAt'] ?? ''),
                    'file' => ($expiredNotice['file'] ?? null) !== null && (string) $expiredNotice['file'] !== '' ? (string) $expiredNotice['file'] : null,
                    'admin_id' => isset($expiredNotice['admin_id']) ? (int) $expiredNotice['admin_id'] : null,
                    'admin_name' => ($expiredNotice['admin_name'] ?? null) !== null ? (string) $expiredNotice['admin_name'] : null,
                    'pin' => (int) ($expiredNotice['pin'] ?? 0),
                    'views' => (int) ($expiredNotice['views'] ?? 0),
                    'priority' => (string) ($expiredNotice['priority'] ?? 'Low'),
                    'visibility' => (string) ($expiredNotice['visibility'] ?? 'public'),
                    'updated_at' => ($expiredNotice['updated_at'] ?? null) !== null ? (string) $expiredNotice['updated_at'] : null,
                ]);

                $expiredNoticeIdStmt->execute(['original_notice_id' => $originalNoticeId]);
                $expiredNoticeId = (int) $expiredNoticeIdStmt->fetchColumn();
                if ($expiredNoticeId <= 0) {
                    continue;
                }

                $clearExpiredFilesStmt->execute(['expired_notice_id' => $expiredNoticeId]);

                $uniquePaths = array_values(array_unique($filesByNoticeId[$originalNoticeId] ?? []));
                foreach ($uniquePaths as $path) {
                    $insertExpiredFileStmt->execute([
                        'expired_notice_id' => $expiredNoticeId,
                        'file_path' => $path,
                    ]);
                }
            }

            $deleteArchivedStmt = $pdo->prepare(
                'DELETE FROM notice WHERE id IN (' . sqlInPlaceholders(count($activeExpiredIds)) . ')'
            );
            $deleteArchivedStmt->execute($activeExpiredIds);
        }

        if ($softDeletedExpiredIds) {
            $deleteSoftDeletedStmt = $pdo->prepare(
                'DELETE FROM notice WHERE id IN (' . sqlInPlaceholders(count($softDeletedExpiredIds)) . ')'
            );
            $deleteSoftDeletedStmt->execute($softDeletedExpiredIds);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fetchCategories(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, category_name FROM category ORDER BY category_name ASC');
    return $stmt->fetchAll();
}

function ensureUploadDirectory(): string
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

function allowedUploadExtensions(): array
{
    return ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
}

function isAllowedVisibility(string $visibility): bool
{
    return in_array($visibility, ['public', 'students', 'staff'], true);
}

function isAllowedPriority(string $priority): bool
{
    return in_array($priority, ['Low', 'Medium', 'High'], true);
}

function processUploadedFiles(array $fileInput, int $existingCount = 0): array
{
    $result = [
        'paths' => [],
        'errors' => [],
    ];

    $maxAttachments = 5;
    if ($existingCount < 0) {
        $existingCount = 0;
    }

    if ($existingCount >= $maxAttachments) {
        $result['errors'][] = 'Maximum ' . $maxAttachments . ' attachments allowed per notice.';
        return $result;
    }

    if (!isset($fileInput['name']) || !is_array($fileInput['name'])) {
        return $result;
    }

    $uploadDir = ensureUploadDirectory();
    $allowedExtensions = allowedUploadExtensions();
    $maxSize = 8 * 1024 * 1024;
    $pendingUploads = [];
    $seenSignatures = [];

    foreach ($fileInput['name'] as $index => $originalName) {
        $errorCode = (int) ($fileInput['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            $result['errors'][] = 'Upload failed for file index ' . $index . '.';
            continue;
        }

        $tmpName = (string) ($fileInput['tmp_name'][$index] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $result['errors'][] = 'Invalid uploaded file at index ' . $index . '.';
            continue;
        }

        $size = (int) ($fileInput['size'][$index] ?? 0);
        $baseName = basename((string) $originalName);
        if ($baseName === '' || $baseName === '.' || $baseName === '..') {
            $baseName = 'attachment_' . $index;
        }
        $extension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            $result['errors'][] = 'Invalid file type: ' . $baseName;
            continue;
        }

        if ($size <= 0 || $size > $maxSize) {
            $result['errors'][] = 'File size must be between 1B and 8MB: ' . $baseName;
            continue;
        }

        $hash = hash_file('sha256', $tmpName);
        $signature = $hash !== false
            ? ($size . '|' . $hash)
            : (strtolower($baseName) . '|' . $size);
        if (isset($seenSignatures[$signature])) {
            $result['errors'][] = 'Duplicate attachment selected: ' . $baseName;
            continue;
        }
        $seenSignatures[$signature] = true;

        $pendingUploads[] = [
            'tmp_name' => $tmpName,
            'base_name' => $baseName,
        ];
    }

    if ($result['errors']) {
        return $result;
    }

    if (($existingCount + count($pendingUploads)) > $maxAttachments) {
        $result['errors'][] = 'Maximum ' . $maxAttachments . ' attachments allowed per notice.';
        return $result;
    }

    foreach ($pendingUploads as $upload) {
        $tmpName = (string) $upload['tmp_name'];
        $baseName = (string) $upload['base_name'];
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName) ?? 'file';
        $newName = bin2hex(random_bytes(10)) . '_' . $safeName;
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($tmpName, $destination)) {
            $result['errors'][] = 'Unable to save file: ' . $baseName;
            continue;
        }

        $result['paths'][] = 'assets/uploads/' . $newName;
    }

    return $result;
}

function removeFileByPath(string $relativePath): void
{
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function softDeleteNoticeAndCleanupFiles(PDO $pdo, int $noticeId, ?int $adminId = null): bool
{
    if ($noticeId <= 0) {
        return false;
    }

    $checkSql = 'SELECT id FROM notice WHERE id = :id AND is_deleted = 0';
    $params = ['id' => $noticeId];

    if ($adminId !== null) {
        $checkSql .= ' AND admin_id = :admin_id';
        $params['admin_id'] = $adminId;
    }

    $checkSql .= ' LIMIT 1';
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute($params);
    if (!$checkStmt->fetch()) {
        return false;
    }

    $deletedPaths = [];

    try {
        $pdo->beginTransaction();
        $deletedPaths = detachNoticeAttachments($pdo, [$noticeId]);

        $updateSql = 'UPDATE notice SET is_deleted = 1, file = NULL WHERE id = :id AND is_deleted = 0';
        if ($adminId !== null) {
            $updateSql .= ' AND admin_id = :admin_id';
        }

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);

        if ($updateStmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    removeFilesFromDisk($deletedPaths);
    return true;
}

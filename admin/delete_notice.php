<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
requireAdmin('login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$token = $_POST['csrf_token'] ?? null;
if (!verifyCsrf(is_string($token) ? $token : null)) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 419);
}

$noticeId = (int) ($_POST['id'] ?? 0);
if ($noticeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Invalid notice ID.'], 422);
}

$pdo = db();
try {
    $isDeleted = isSystemAdmin()
        ? softDeleteNoticeAndCleanupFiles($pdo, $noticeId, null)
        : softDeleteNoticeAndCleanupFiles($pdo, $noticeId, getAdminId());
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Unable to delete notice right now.'], 500);
}

if (!$isDeleted) {
    jsonResponse(['success' => false, 'message' => 'Notice not found or already deleted.'], 404);
}

jsonResponse([
    'success' => true,
    'message' => 'Notice deleted successfully and linked files removed.',
    'id' => $noticeId,
]);

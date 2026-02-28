<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$noticeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($noticeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Invalid notice ID.'], 422);
}

$pdo = db();
cleanupExpiredNotices($pdo);

$stmt = $pdo->prepare(
    "SELECT n.id, n.title, n.createdAt, n.expiresAt, n.file, n.pin, n.views, n.priority, n.visibility,
            c.category_name,
            a.name AS admin_name
     FROM notice n
     INNER JOIN category c ON c.id = n.category
     INNER JOIN admin a ON a.id = n.admin_id
     WHERE n.id = :id
       AND n.is_deleted = 0
       AND n.expiresAt >= NOW()"
);
$stmt->execute(['id' => $noticeId]);
$notice = $stmt->fetch();

if (!$notice) {
    jsonResponse(['success' => false, 'message' => 'Notice not found or expired.'], 404);
}

$incrementStmt = $pdo->prepare('UPDATE notice SET views = views + 1 WHERE id = :id');
$incrementStmt->execute(['id' => $noticeId]);
$notice['views'] = (int) $notice['views'] + 1;

$filesStmt = $pdo->prepare('SELECT id, file_path FROM notice_files WHERE notice_id = :id ORDER BY id DESC');
$filesStmt->execute(['id' => $noticeId]);
$files = $filesStmt->fetchAll();

if (!empty($notice['file'])) {
    $primaryFile = (string) $notice['file'];
    $exists = false;

    foreach ($files as $fileRow) {
        if ((string) $fileRow['file_path'] === $primaryFile) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $files[] = ['id' => 0, 'file_path' => $primaryFile];
    }
}

jsonResponse([
    'success' => true,
    'notice' => [
        'id' => (int) $notice['id'],
        'title' => (string) $notice['title'],
        'category_name' => (string) $notice['category_name'],
        'admin_name' => (string) $notice['admin_name'],
        'createdAt' => (string) $notice['createdAt'],
        'expiresAt' => (string) $notice['expiresAt'],
        'pin' => (int) $notice['pin'],
        'views' => (int) $notice['views'],
        'priority' => (string) $notice['priority'],
        'visibility' => (string) $notice['visibility'],
        'files' => $files,
    ],
]);

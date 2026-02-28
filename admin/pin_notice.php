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
$findSql = 'SELECT pin FROM notice WHERE id = :id AND is_deleted = 0';
$findParams = ['id' => $noticeId];
if (!isSystemAdmin()) {
    $findSql .= ' AND admin_id = :admin_id';
    $findParams['admin_id'] = getAdminId();
}
$findSql .= ' LIMIT 1';
$findStmt = $pdo->prepare($findSql);
$findStmt->execute($findParams);
$notice = $findStmt->fetch();

if (!$notice) {
    jsonResponse(['success' => false, 'message' => 'Notice not found.'], 404);
}

$newPin = ((int) $notice['pin'] === 1) ? 0 : 1;
$updateSql = 'UPDATE notice SET pin = :pin WHERE id = :id';
$updateParams = [
    'pin' => $newPin,
    'id' => $noticeId,
];
if (!isSystemAdmin()) {
    $updateSql .= ' AND admin_id = :admin_id';
    $updateParams['admin_id'] = getAdminId();
}
$updateStmt = $pdo->prepare($updateSql);
$updateStmt->execute($updateParams);

jsonResponse([
    'success' => true,
    'message' => $newPin === 1 ? 'Notice pinned.' : 'Notice unpinned.',
    'id' => $noticeId,
    'pin' => $newPin,
]);

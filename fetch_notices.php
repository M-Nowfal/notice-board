<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = db();
cleanupExpiredNotices($pdo);

$search = sanitizeText($_GET['search'] ?? '', 255);
$categoryId = isset($_GET['category']) && $_GET['category'] !== '' ? (int) $_GET['category'] : null;
$visibility = sanitizeText($_GET['visibility'] ?? '', 20);
$pinnedOnly = isset($_GET['pinned_only']) ? (int) $_GET['pinned_only'] : 0;

$sql = "
    SELECT
        n.id,
        n.title,
        n.description,
        n.createdAt,
        n.expiresAt,
        n.file,
        n.pin,
        n.views,
        n.priority,
        n.visibility,
        c.category_name,
        a.name AS admin_name,
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

if ($search !== '') {
    $sql .= " AND (n.title LIKE :search OR COALESCE(n.description, '') LIKE :search) ";
    $params['search'] = '%' . $search . '%';
}

if ($categoryId && $categoryId > 0) {
    $sql .= " AND n.category = :category ";
    $params['category'] = $categoryId;
}

if ($visibility !== '' && isAllowedVisibility($visibility)) {
    $sql .= " AND n.visibility = :visibility ";
    $params['visibility'] = $visibility;
}

if ($pinnedOnly === 1) {
    $sql .= " AND n.pin = 1 ";
}

$sql .= " ORDER BY n.pin DESC, n.createdAt DESC ";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->execute();
$notices = $stmt->fetchAll();

function badgeColor(string $priority): string
{
    return match ($priority) {
        'High' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'Medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    };
}

function priorityStripe(string $priority): string
{
    return match ($priority) {
        'High' => 'bg-gradient-to-r from-rose-500 via-red-500 to-orange-400',
        'Medium' => 'bg-gradient-to-r from-amber-500 via-yellow-500 to-lime-400',
        default => 'bg-gradient-to-r from-emerald-500 via-teal-500 to-cyan-400',
    };
}

function priorityGlow(string $priority): string
{
    return match ($priority) {
        'High' => 'bg-rose-500/50',
        'Medium' => 'bg-amber-500/50',
        default => 'bg-cyan-500/50',
    };
}

function visibilityColor(string $visibility): string
{
    return match ($visibility) {
        'students' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'staff' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };
}

ob_start();

if (!$notices) {
    ?>
    <div class="sm:col-span-2 xl:col-span-3 rounded-3xl border border-dashed border-slate-300 dark:border-slate-700 p-10 text-center bg-white/80 dark:bg-slate-900/60">
        <div class="mx-auto h-14 w-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
            <i class="fa-regular fa-folder-open text-slate-500 dark:text-slate-300"></i>
        </div>
        <p class="text-base font-semibold">No active notices match your filters.</p>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try changing category, visibility, or pinned filter.</p>
    </div>
    <?php
}

foreach ($notices as $notice):
    $priority = (string) $notice['priority'];
    $priorityClass = badgeColor($priority);
    $priorityStripe = priorityStripe($priority);
    $priorityGlow = priorityGlow($priority);
    $visibilityClass = visibilityColor((string) $notice['visibility']);
    $filePath = (string) ($notice['file'] ?? '');
    $fileCount = (int) ($notice['files_count'] ?? 0);
    $createdAt = date('d M Y, h:i A', strtotime((string) $notice['createdAt']));
    $expiresAt = date('d M Y, h:i A', strtotime((string) $notice['expiresAt']));
    $attachments = max($fileCount, $filePath !== '' ? 1 : 0);
    $description = trim((string) ($notice['description'] ?? ''));
    $descriptionPreview = $description !== '' && mb_strlen($description) > 180
        ? mb_substr($description, 0, 177) . '...'
        : $description;
    ?>
    <article class="notice-card group relative overflow-hidden rounded-3xl border border-slate-200/80 dark:border-slate-700/70 bg-white/90 dark:bg-slate-900/75 shadow-[0_12px_35px_-20px_rgba(15,23,42,0.9)]" data-card-id="<?php echo (int) $notice['id']; ?>">
        <div class="h-1.5 <?php echo $priorityStripe; ?>"></div>
        <div class="absolute -right-10 -top-10 h-28 w-28 rounded-full <?php echo $priorityGlow; ?> blur-2xl opacity-40"></div>

        <div class="relative p-5 flex flex-col gap-4 h-full">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-semibold leading-snug"><?php echo escape((string) $notice['title']); ?></h3>
                <?php if ((int) $notice['pin'] === 1): ?>
                    <span class="badge-pill bg-blue-600 text-white inline-flex items-center gap-1 shrink-0">
                        <i class="fa-solid fa-thumbtack"></i> Pinned
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($descriptionPreview !== ''): ?>
                <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                    <?php echo nl2br(escape($descriptionPreview)); ?>
                </p>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="badge-pill <?php echo $priorityClass; ?>"><?php echo escape($priority); ?> Priority</span>
                <span class="badge-pill <?php echo $visibilityClass; ?>"><?php echo escape(ucfirst((string) $notice['visibility'])); ?></span>
                <span class="badge-pill bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><?php echo escape((string) $notice['category_name']); ?></span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs sm:text-sm">
                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 px-3 py-2">
                    <p class="text-slate-500 dark:text-slate-400">By</p>
                    <p class="font-semibold truncate"><?php echo escape((string) $notice['admin_name']); ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 px-3 py-2">
                    <p class="text-slate-500 dark:text-slate-400">Views</p>
                    <p class="font-semibold"><span data-view-count="<?php echo (int) $notice['id']; ?>"><?php echo (int) $notice['views']; ?></span></p>
                </div>
                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 px-3 py-2 col-span-2">
                    <p class="text-slate-500 dark:text-slate-400">Created</p>
                    <p class="font-semibold"><?php echo $createdAt; ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 px-3 py-2 col-span-2">
                    <p class="text-slate-500 dark:text-slate-400">Expires</p>
                    <p class="font-semibold"><?php echo $expiresAt; ?></p>
                </div>
            </div>

            <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 px-3 py-2 text-sm text-slate-600 dark:text-slate-300">
                <i class="fa-regular fa-file mr-1"></i>
                Attachments: <span class="font-semibold"><?php echo $attachments; ?></span>
            </div>

            <div class="mt-auto flex flex-col sm:flex-row gap-2">
                <button type="button" data-open-notice="<?php echo (int) $notice['id']; ?>" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-cyan-500 hover:from-blue-700 hover:to-cyan-600 text-white text-sm font-medium shadow-lg shadow-blue-600/30">
                    <i class="fa-solid fa-up-right-from-square"></i> Open Notice
                </button>
                <?php if ($filePath !== ''): ?>
                    <a href="<?php echo escape($filePath); ?>" target="_blank" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
endforeach;

$html = ob_get_clean();
jsonResponse([
    'success' => true,
    'count' => count($notices),
    'html' => $html,
]);


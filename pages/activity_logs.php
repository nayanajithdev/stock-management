<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$search = trim((string) ($_GET['q'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$userFilter = (int) ($_GET['user_id'] ?? 0);
$startDate = activity_valid_date((string) ($_GET['start_date'] ?? ''));
$endDate = activity_valid_date((string) ($_GET['end_date'] ?? ''));
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($pageNumber - 1) * $perPage;
$logs = [];
$users = [];
$actions = [];
$totalLogs = 0;

if ($dbReady && $pdo !== null) {
    $users = $pdo->query(
        'SELECT id, full_name, role
         FROM users
         ORDER BY role = "owner" DESC, full_name ASC'
    )->fetchAll();

    $actions = $pdo->query(
        'SELECT DISTINCT action
         FROM activity_logs
         ORDER BY action ASC'
    )->fetchAll(PDO::FETCH_COLUMN);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(al.description LIKE :search OR al.action LIKE :search OR u.full_name LIKE :search OR u.username LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($actionFilter !== '') {
        $where[] = 'al.action = :action';
        $params['action'] = $actionFilter;
    }

    if ($userFilter > 0) {
        $where[] = 'al.user_id = :user_id';
        $params['user_id'] = $userFilter;
    }

    if ($startDate !== '') {
        $where[] = 'al.created_at >= :start_date';
        $params['start_date'] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $where[] = 'al.created_at <= :end_date';
        $params['end_date'] = $endDate . ' 23:59:59';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id' . $whereSql
    );

    foreach ($params as $key => $value) {
        $countStatement->bindValue(':' . $key, $value, $key === 'user_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $countStatement->execute();
    $totalLogs = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalLogs / $perPage));

    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
        $offset = ($pageNumber - 1) * $perPage;
    }

    $logStatement = $pdo->prepare(
        'SELECT al.id,
                al.action,
                al.description,
                al.created_at,
                u.full_name,
                u.username,
                u.role
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id' .
         $whereSql .
        ' ORDER BY al.created_at DESC, al.id DESC
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $logStatement->bindValue(':' . $key, $value, $key === 'user_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $logStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $logStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logStatement->execute();
    $logs = $logStatement->fetchAll();
} else {
    $totalPages = 1;
}
?>

<section class="panel table-panel">
    <div class="panel-header">
        <div>
            <p class="panel-label">Audit Trail</p>
            <h2>Activity Logs</h2>
        </div>
        <span class="dashboard-pill"><?php echo (int) $totalLogs; ?> record(s)</span>
    </div>

    <?php if (! $dbReady): ?>
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing activity logs.</p>
    <?php else: ?>
        <form class="activity-filter-form" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="activity-logs">
            <label class="field">
                <span>Search</span>
                <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Action, user, or details">
            </label>
            <label class="field">
                <span>Action</span>
                <select name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?php echo e($action); ?>" <?php echo $actionFilter === (string) $action ? 'selected' : ''; ?>><?php echo e(activity_action_label((string) $action)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>User</span>
                <select name="user_id">
                    <option value="0">All users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo (int) $user['id']; ?>" <?php echo $userFilter === (int) $user['id'] ? 'selected' : ''; ?>>
                            <?php echo e($user['full_name'] . ' (' . auth_role_label((string) $user['role']) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>From</span>
                <input type="date" name="start_date" value="<?php echo e($startDate); ?>">
            </label>
            <label class="field">
                <span>To</span>
                <input type="date" name="end_date" value="<?php echo e($endDate); ?>">
            </label>
            <button class="top-action" type="submit">
                <i data-lucide="search"></i>
                Filter
            </button>
        </form>

        <?php if ($search !== '' || $actionFilter !== '' || $userFilter > 0 || $startDate !== '' || $endDate !== ''): ?>
            <a class="muted-link activity-clear-link" href="<?php echo e(app_url('?page=activity-logs')); ?>">Clear filters</a>
        <?php endif; ?>

        <div class="table-wrap activity-table">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs === []): ?>
                        <tr><td colspan="4">No activity logs found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <strong class="table-title"><?php echo e(date('Y-m-d H:i', strtotime((string) $log['created_at']))); ?></strong>
                                <span class="table-subtitle"><?php echo e(activity_relative_time((string) $log['created_at'])); ?></span>
                            </td>
                            <td>
                                <strong class="table-title"><?php echo e($log['full_name'] ?: 'System'); ?></strong>
                                <span class="table-subtitle"><?php echo e($log['username'] ? '@' . $log['username'] : 'No user'); ?></span>
                            </td>
                            <td><span class="status <?php echo e(activity_action_class((string) $log['action'])); ?>"><?php echo e(activity_action_label((string) $log['action'])); ?></span></td>
                            <td><?php echo e($log['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-row">
                <?php $previousQuery = activity_page_query($pageNumber - 1); ?>
                <?php $nextQuery = activity_page_query($pageNumber + 1); ?>
                <a class="ghost-button <?php echo $pageNumber <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($pageNumber <= 1 ? '#' : app_url('?' . $previousQuery)); ?>">Previous</a>
                <span>Page <?php echo (int) $pageNumber; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="ghost-button <?php echo $pageNumber >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo e($pageNumber >= $totalPages ? '#' : app_url('?' . $nextQuery)); ?>">Next</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
function activity_valid_date(string $date): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
}

function activity_action_label(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}

function activity_action_class(string $action): string
{
    return match (true) {
        str_contains($action, 'delete'), str_contains($action, 'archive'), str_contains($action, 'inactive') => 'status-pending',
        str_contains($action, 'login'), str_contains($action, 'create'), str_contains($action, 'save') => 'status-active',
        str_contains($action, 'update'), str_contains($action, 'adjust') => 'status-warranty',
        default => 'status-ready',
    };
}

function activity_relative_time(string $createdAt): string
{
    $timestamp = strtotime($createdAt);

    if ($timestamp === false) {
        return '';
    }

    $seconds = time() - $timestamp;

    if ($seconds < 60) {
        return 'Just now';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' hour(s) ago';
    }

    return floor($seconds / 86400) . ' day(s) ago';
}

function activity_page_query(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = 'activity-logs';
    $query['p'] = max(1, $pageNumber);

    return http_build_query($query);
}

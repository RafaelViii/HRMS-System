<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_conn();
$uid = (int)($_SESSION['user']['id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min($limit, 50));

$items = [];
$unreadCount = 0;

try {
    ensure_notification_reads($pdo);

    $stmt = $pdo->prepare(
        "SELECT n.id,
                COALESCE(NULLIF(n.title, ''), LEFT(n.message, 120)) AS title,
                COALESCE(NULLIF(n.body, ''), n.message) AS body,
                n.payload,
                n.created_at,
                n.user_id,
                CASE WHEN n.user_id IS NULL THEN (r.notification_id IS NULL) ELSE (NOT n.is_read) END AS is_unread
         FROM notifications n
         LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
         WHERE n.user_id IS NULL OR n.user_id = :uid
         ORDER BY n.id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $payload = null;
        if (!empty($row['payload'])) {
            try {
                $decoded = defined('JSON_THROW_ON_ERROR')
                    ? json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR)
                    : json_decode($row['payload'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (Throwable $e) {
                $payload = null;
            }
        }

        $items[] = [
            'id' => (int)$row['id'],
            'title' => (string)($row['title'] ?? 'Notification'),
            'body' => (string)($row['body'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'created_human' => format_datetime_display($row['created_at'] ?? null, true, ''),
            'is_unread' => !empty($row['is_unread']),
            'is_global' => $row['user_id'] === null,
            'payload' => $payload,
        ];
    }

    $countStmt = $pdo->prepare(
        'SELECT (
            SELECT COUNT(*) FROM notifications n WHERE n.user_id = :uid AND n.is_read = FALSE
        ) + (
            SELECT COUNT(*) FROM notifications g
            LEFT JOIN notification_reads r ON r.notification_id = g.id AND r.user_id = :uid
            WHERE g.user_id IS NULL AND r.notification_id IS NULL
        ) AS cnt'
    );
    $countStmt->execute([':uid' => $uid]);
    $unreadCount = (int)($countStmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    sys_log('NOTIF-FEED', 'Failed loading notifications feed: ' . $e->getMessage(), ['module' => 'notifications', 'file' => __FILE__, 'line' => __LINE__]);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load notifications']);
    exit;
}

echo json_encode([
    'items' => $items,
    'unread_count' => $unreadCount,
]);

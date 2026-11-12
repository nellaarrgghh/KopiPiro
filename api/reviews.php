<?php
require_once __DIR__ . '/../helpers.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $pid = (int)($_GET['product_id'] ?? 0);
    $where = $pid > 0 ? "WHERE r.product_id = ?" : "";
    $stmt = $pdo->prepare(
        "SELECT r.*, COALESCE(NULLIF(u.name, ''), u.username) AS reviewer_name
         FROM reviews r
         JOIN users u ON u.id = r.user_id
         $where
         ORDER BY r.id DESC LIMIT 50"
    );
    $stmt->execute($pid > 0 ? [$pid] : []);
    out(['reviews' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $u = need_role(['customer', 'owner']);
    $b = body();
    $pid     = (int)($b['product_id'] ?? 0);
    $rating  = (int)($b['rating'] ?? 0);
    $comment = trim($b['comment'] ?? '');

    if ($pid <= 0 || $rating < 1 || $rating > 5) {
        out(['message' => 'Valid product_id and rating (1-5) required'], 422);
    }

    $stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?, ?, ?, ?)");
    $stmt->execute([$pid, $u['id'], $rating, $comment]);

    $avg_stmt = $pdo->prepare("SELECT ROUND(AVG(rating), 1) AS avg_r, COUNT(*) AS cnt FROM reviews WHERE product_id = ?");
    $avg_stmt->execute([$pid]);
    $stat = $avg_stmt->fetch();
    if ($stat && $stat['cnt'] > 0) {
        $label = $stat['avg_r'] . " / 5 (" . $stat['cnt'] . " reviews)";
        $pdo->prepare("UPDATE product SET rating = ? WHERE id = ?")->execute([$label, $pid]);
    }

    out(['message' => 'Review saved', 'rating' => (float)$stat['avg_r'], 'reviews' => (int)$stat['cnt']], 201);
}

out(['message' => 'Method not allowed'], 405);
?>

<?php
require_once __DIR__ . '/../helpers.php';
need_role(['owner']);

$valid_statuses = "('paid', 'done', 'Completed')";

$daily = $pdo->query(
    "SELECT DATE(created_at) AS day, COUNT(*) AS orders, SUM(total) AS revenue
     FROM orders WHERE status IN $valid_statuses
     GROUP BY DATE(created_at) ORDER BY day ASC LIMIT 14"
)->fetchAll();

$top = $pdo->query(
    "SELECT COALESCE(p.name, oi.product_name) AS name, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN product p ON p.id = oi.product_id
     WHERE o.status IN $valid_statuses
     GROUP BY COALESCE(p.id, oi.product_name), COALESCE(p.name, oi.product_name)
     ORDER BY qty DESC LIMIT 8"
)->fetchAll();

$by_category = $pdo->query(
    "SELECT oi.category, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN $valid_statuses
     GROUP BY oi.category
     ORDER BY revenue DESC"
)->fetchAll();

$by_cashier = $pdo->query(
    "SELECT COALESCE(NULLIF(u.name, ''), u.username, 'Unassigned') AS cashier_name,
            COUNT(o.id) AS orders, SUM(o.total) AS revenue
     FROM orders o
     LEFT JOIN users u ON u.id = o.cashier_id
     WHERE o.status IN $valid_statuses
     GROUP BY o.cashier_id, cashier_name
     ORDER BY revenue DESC"
)->fetchAll();

out([
    'daily'       => $daily,
    'top'         => $top,
    'by_category' => $by_category,
    'by_cashier'  => $by_cashier
]);
?>

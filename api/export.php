<?php
require_once __DIR__ . '/../helpers.php';
need_role(['owner']);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="kopipiro-orders-' . date('Ymd') . '.xls"');

$rows = $pdo->query(
    "SELECT o.order_code, o.customer_name, COALESCE(NULLIF(c.name, ''), c.username) AS cashier, o.status,
            o.subtotal, o.discount, o.total, o.created_at
     FROM orders o LEFT JOIN users c ON c.id = o.cashier_id
     ORDER BY o.id DESC"
)->fetchAll();

echo "<table border='1'><tr>
        <th>Order Code</th><th>Customer</th><th>Cashier</th><th>Status</th>
        <th>Subtotal</th><th>Discount</th><th>Total</th><th>Created At</th>
      </tr>";
foreach ($rows as $r) {
    echo '<tr>';
    foreach ($r as $cell) {
        echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
    }
    echo '</tr>';
}
echo '</table>';
?>

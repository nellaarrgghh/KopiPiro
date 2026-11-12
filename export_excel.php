<?php
include 'auth.php';
include 'config.php';

require_admin();

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=kopipiro_orders_" . date('Y-m-d_His') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

$sql = "SELECT o.*, u.username AS cashier_username
        FROM orders o
        LEFT JOIN users u ON o.cashier_id = u.id
        ORDER BY o.id DESC";
$res = mysqli_query($conn, $sql);

$items_map = [];
$oi_res = mysqli_query($conn, "SELECT * FROM order_items ORDER BY id ASC");
if ($oi_res) {
    while ($oi = mysqli_fetch_assoc($oi_res)) {
        $items_map[(int)$oi['order_id']][] = $oi['product_name'] . ' (' . $oi['variant'] . ') x' . $oi['qty'];
    }
}

echo "<table border='1'>";
echo "<thead>";
echo "<tr style='background-color:#212529;color:#ffffff;font-weight:bold;'>";
echo "<th>Order ID</th>";
echo "<th>Date &amp; Time</th>";
echo "<th>Customer Name</th>";
echo "<th>Customer Type</th>";
echo "<th>Assigned Cashier</th>";
echo "<th>Status</th>";
echo "<th>Ordered Items</th>";
echo "<th>Subtotal (Rp)</th>";
echo "<th>Member Discount (Rp)</th>";
echo "<th>Total Paid (Rp)</th>";
echo "</tr>";
echo "</thead>";
echo "<tbody>";

$grand_sub = 0;
$grand_disc = 0;
$grand_tot = 0;

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $oid = (int)$row['id'];
        $item_str = isset($items_map[$oid]) ? implode('; ', $items_map[$oid]) : 'Legacy order';
        $type_str = ((int)$row['is_member'] === 1) ? 'Member (10% Off)' : 'Guest (Normal)';
        $cashier_str = $row['cashier_username'] ?? 'Unassigned';

        if ($row['status'] !== 'Cancelled') {
            $grand_sub += (float)$row['subtotal'];
            $grand_disc += (float)$row['discount'];
            $grand_tot += (float)$row['total'];
        }

        echo "<tr>";
        echo "<td>#" . $oid . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "<td>" . htmlspecialchars($row['customer_name']) . "</td>";
        echo "<td>" . htmlspecialchars($type_str) . "</td>";
        echo "<td>" . htmlspecialchars($cashier_str) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($item_str) . "</td>";
        echo "<td>" . number_format((float)$row['subtotal'], 0, ',', '.') . "</td>";
        echo "<td>" . number_format((float)$row['discount'], 0, ',', '.') . "</td>";
        echo "<td>" . number_format((float)$row['total'], 0, ',', '.') . "</td>";
        echo "</tr>";
    }
}

echo "<tr style='font-weight:bold;background-color:#e9ecef;'>";
echo "<td colspan='7' align='right'>ACTIVE ORDERS TOTAL</td>";
echo "<td>" . number_format($grand_sub, 0, ',', '.') . "</td>";
echo "<td>" . number_format($grand_disc, 0, ',', '.') . "</td>";
echo "<td>" . number_format($grand_tot, 0, ',', '.') . "</td>";
echo "</tr>";
echo "</tbody>";
echo "</table>";
exit;
?>


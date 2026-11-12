<?php
include 'auth.php';
include 'config.php';

require_login();

$uid = (int)$_SESSION['user_id'];
$uname = $_SESSION['username'] ?? '';

$stmt = mysqli_prepare($conn, "SELECT o.*, u.username AS cashier_username
                               FROM orders o
                               LEFT JOIN users u ON o.cashier_id = u.id
                               WHERE o.user_id = ? OR o.customer_name = ?
                               ORDER BY o.id DESC");
mysqli_stmt_bind_param($stmt, "is", $uid, $uname);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$my_orders = [];
while ($r = mysqli_fetch_assoc($res)) {
    $my_orders[] = $r;
}

$items_map = [];
$oi_res = mysqli_query($conn, "SELECT * FROM order_items ORDER BY id ASC");
if ($oi_res) {
    while ($oi = mysqli_fetch_assoc($oi_res)) {
        $items_map[(int)$oi['order_id']][] = $oi;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - Kopipiro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
            <div>
                <h1 class="h3 mb-1">My Order History (<?= htmlspecialchars($uname) ?>)</h1>
                <p class="text-muted mb-0">View your past Kopipiro orders and 10% member discount savings.</p>
            </div>
            <a href="order.php" class="btn btn-primary">Place New Order</a>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h2 class="h5 mb-0">Your Kopipiro Orders (<?= count($my_orders) ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (empty($my_orders)): ?>
                    <div class="text-center py-4">
                        <h3 class="h5">You have not placed any orders yet</h3>
                        <p class="text-muted mb-3">Explore the Kopipiro menu and place your first order with your 10% member discount.</p>
                        <a href="index.php" class="btn btn-primary">Explore Menu &amp; Order</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th scope="col">Order ID</th>
                                    <th scope="col">Date &amp; Time</th>
                                    <th scope="col">Items Ordered</th>
                                    <th scope="col">Cashier</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Subtotal</th>
                                    <th scope="col" class="text-end">10% Discount</th>
                                    <th scope="col" class="text-end">Total Paid</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_orders as $ord):
                                    $oid = (int)$ord['id'];
                                    $items = $items_map[$oid] ?? [];
                                ?>
                                <tr>
                                    <td class="fw-bold">#<?= $oid ?></td>
                                    <td class="small"><?= htmlspecialchars($ord['created_at']) ?></td>
                                    <td>
                                        <?php if (!empty($items)): ?>
                                            <ul class="mb-0 ps-3 small">
                                                <?php foreach ($items as $it): ?>
                                                    <li>
                                                        <strong><?= htmlspecialchars($it['product_name']) ?></strong>
                                                        (<?= htmlspecialchars($it['variant']) ?>) x <?= (int)$it['qty'] ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span class="small text-muted">Order #<?= $oid ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($ord['cashier_username'] ?? 'Assigned') ?></td>
                                    <td><span class="badge bg-success"><?= htmlspecialchars($ord['status']) ?></span></td>
                                    <td class="text-end"><?= format_rp($ord['subtotal']) ?></td>
                                    <td class="text-end text-success">- <?= format_rp($ord['discount']) ?></td>
                                    <td class="text-end fw-bold"><?= format_rp($ord['total']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>


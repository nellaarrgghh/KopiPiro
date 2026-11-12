<?php
include 'auth.php';
include 'config.php';

require_staff_or_admin();

$status_msg = "";
$error_msg = "";

// Handle updating order status or cashier assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['status'] ?? 'paid';
    if (!in_array($new_status, ['pending', 'paid', 'done', 'Pending', 'Completed', 'Cancelled'], true)) {
        $new_status = 'paid';
    }

    if (is_admin()) {
        $new_cashier = isset($_POST['cashier_id']) && (int)$_POST['cashier_id'] > 0 ? (int)$_POST['cashier_id'] : null;
        $stmt = mysqli_prepare($conn, "UPDATE orders SET status = ?, cashier_id = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $new_status, $new_cashier, $order_id);
        mysqli_stmt_execute($stmt);
        $status_msg = "Order #$order_id updated.";
    } elseif (is_cashier()) {
        // Cashiers can only update orders assigned to them
        $my_id = (int)$_SESSION['user_id'];
        $stmt = mysqli_prepare($conn, "UPDATE orders SET status = ? WHERE id = ? AND cashier_id = ?");
        mysqli_stmt_bind_param($stmt, "sii", $new_status, $order_id, $my_id);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) >= 0) {
            $status_msg = "Status for your assigned Order #$order_id updated to $new_status.";
        } else {
            $error_msg = "You can only update orders assigned to you.";
        }
    }
}

// Fetch cashiers list for admin assignment and filter
$cashiers = [];
$c_res = mysqli_query($conn, "SELECT id, username FROM users WHERE role = 'cashier' ORDER BY id ASC");
if ($c_res) {
    while ($c = mysqli_fetch_assoc($c_res)) {
        $cashiers[$c['id']] = $c['username'];
    }
}

$filter_cashier = isset($_GET['cashier_id']) ? (int)$_GET['cashier_id'] : 0;
$filter_status = trim($_GET['status'] ?? '');

// Build Orders Query: Cashiers strictly see ONLY their assigned orders (Feature 5)
$sql = "SELECT o.*, u.username AS cashier_username
        FROM orders o
        LEFT JOIN users u ON o.cashier_id = u.id
        WHERE 1=1";
$params = [];
$types = "";

if (is_cashier()) {
    $sql .= " AND o.cashier_id = ?";
    $params[] = (int)$_SESSION['user_id'];
    $types .= "i";
} elseif (is_admin() && $filter_cashier > 0) {
    $sql .= " AND o.cashier_id = ?";
    $params[] = $filter_cashier;
    $types .= "i";
}

if (in_array($filter_status, ['pending', 'paid', 'done', 'Pending', 'Completed', 'Cancelled'], true)) {
    $sql .= " AND o.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

$sql .= " ORDER BY o.id DESC";
$stmt_o = mysqli_prepare($conn, $sql);
if ($stmt_o && !empty($params)) {
    mysqli_stmt_bind_param($stmt_o, $types, ...$params);
}
$orders_list = [];
if ($stmt_o) {
    mysqli_stmt_execute($stmt_o);
    $res_o = mysqli_stmt_get_result($stmt_o);
    while ($row = mysqli_fetch_assoc($res_o)) {
        $orders_list[] = $row;
    }
}

// Map order items by order_id
$order_items_map = [];
$oi_res = mysqli_query($conn, "SELECT * FROM order_items ORDER BY id ASC");
if ($oi_res) {
    while ($oi = mysqli_fetch_assoc($oi_res)) {
        $order_items_map[(int)$oi['order_id']][] = $oi;
    }
}

// Owner Analytics & Sales Charts Data (Feature 6 - Real Database Queries Only)
$cat_sales = [];
$max_cat_rev = 0.0;
$cashier_sales = [];
$max_cashier_rev = 0.0;
$top_products = [];
$max_prod_qty = 0;
$summary_totals = ['orders_count' => 0, 'gross_sales' => 0.0, 'total_discounts' => 0.0, 'net_revenue' => 0.0];

if (is_admin()) {
    $sum_res = mysqli_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(subtotal),0) AS gross, COALESCE(SUM(discount),0) AS disc, COALESCE(SUM(total),0) AS net FROM orders WHERE status != 'Cancelled'");
    if ($sum_res && ($srow = mysqli_fetch_assoc($sum_res))) {
        $summary_totals = [
            'orders_count'    => (int)$srow['cnt'],
            'gross_sales'     => (float)$srow['gross'],
            'total_discounts' => (float)$srow['disc'],
            'net_revenue'     => (float)$srow['net']
        ];
    }

    // 1. Net revenue by product category
    $q_cat = "SELECT oi.category, SUM(oi.qty) AS items_sold, SUM(oi.line_total) AS revenue
              FROM order_items oi
              INNER JOIN orders o ON oi.order_id = o.id
              WHERE o.status != 'Cancelled'
              GROUP BY oi.category
              ORDER BY revenue DESC";
    $r_cat = mysqli_query($conn, $q_cat);
    if ($r_cat) {
        while ($r = mysqli_fetch_assoc($r_cat)) {
            $rev = (float)$r['revenue'];
            if ($rev > $max_cat_rev) {
                $max_cat_rev = $rev;
            }
            $cat_sales[] = $r;
        }
    }

    // 2. Revenue managed per cashier
    $q_cash = "SELECT COALESCE(u.username, 'Unassigned') AS cashier_name, COUNT(o.id) AS order_count, COALESCE(SUM(o.total), 0) AS revenue
               FROM orders o
               LEFT JOIN users u ON o.cashier_id = u.id
               WHERE o.status != 'Cancelled'
               GROUP BY o.cashier_id, u.username
               ORDER BY revenue DESC";
    $r_cash = mysqli_query($conn, $q_cash);
    if ($r_cash) {
        while ($r = mysqli_fetch_assoc($r_cash)) {
            $rev = (float)$r['revenue'];
            if ($rev > $max_cashier_rev) {
                $max_cashier_rev = $rev;
            }
            $cashier_sales[] = $r;
        }
    }

    // 3. Top ordered products by quantity
    $q_prod = "SELECT oi.product_name, oi.category, SUM(oi.qty) AS total_qty, SUM(oi.line_total) AS total_rev
               FROM order_items oi
               INNER JOIN orders o ON oi.order_id = o.id
               WHERE o.status != 'Cancelled'
               GROUP BY oi.product_name, oi.category
               ORDER BY total_qty DESC, total_rev DESC
               LIMIT 8";
    $r_prod = mysqli_query($conn, $q_prod);
    if ($r_prod) {
        while ($r = mysqli_fetch_assoc($r_prod)) {
            $q = (int)$r['total_qty'];
            if ($q > $max_prod_qty) {
                $max_prod_qty = $q;
            }
            $top_products[] = $r;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_admin() ? 'All Orders, Sales Charts & Excel Export - Kopipiro Owner' : 'My Assigned Orders - Kopipiro Cashier' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <?php if (is_admin()): ?>
                    <h1 class="h3 mb-1">Owner Sales Dashboard &amp; All Orders</h1>
                    <p class="text-muted mb-0">Inspect store-wide revenue charts, manage cashier assignments, and export order records to Excel.</p>
                <?php else: ?>
                    <h1 class="h3 mb-1">Assigned Orders for Cashier: <?= htmlspecialchars($_SESSION['username']) ?></h1>
                    <p class="text-muted mb-0">You can view and manage only the orders assigned to your cashier account.</p>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if (is_admin()): ?>
                    <a href="export_excel.php" class="btn btn-success">Download Orders Excel (.xls)</a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-secondary">Back to Menu</a>
            </div>
        </div>

        <?php if ($status_msg !== ''): ?>
            <div class="alert alert-success" role="status">
                <strong>Updated:</strong> <?= htmlspecialchars($status_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Error:</strong> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <?php if (is_admin()): ?>
            <!-- Owner Sales Summary & Charts Section (Feature 6) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Store Revenue Overview (Active Orders: <?= $summary_totals['orders_count'] ?>)</h2>
                    <span class="badge bg-success fs-6">Net Sales: <?= format_rp($summary_totals['net_revenue']) ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <div class="p-3 border rounded">
                                <div class="text-muted small">Gross Sales (Before Member Discount)</div>
                                <div class="h4 mb-0 fw-bold"><?= format_rp($summary_totals['gross_sales']) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="p-3 border rounded">
                                <div class="text-muted small">Total 10% Member Discounts Granted</div>
                                <div class="h4 mb-0 fw-bold text-success">- <?= format_rp($summary_totals['total_discounts']) ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="p-3 border rounded">
                                <div class="text-muted small">Net Revenue Collected</div>
                                <div class="h4 mb-0 fw-bold text-primary"><?= format_rp($summary_totals['net_revenue']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Chart.js Daily Revenue & Top Products (PDF Section 5.2 api/sales.php) -->
                        <div class="col-12 col-lg-6">
                            <h3 class="h6 fw-bold mb-2">Daily Revenue Trend (Chart.js via <code>api/sales.php</code>)</h3>
                            <div style="height: 220px;">
                                <canvas id="dailyRevenueChart" aria-label="Daily Revenue Bar Chart"></canvas>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <h3 class="h6 fw-bold mb-2">Top Products by Quantity Sold (Chart.js via <code>api/sales.php</code>)</h3>
                            <div style="height: 220px;">
                                <canvas id="topProductsChart" aria-label="Top Products Bar Chart"></canvas>
                            </div>
                        </div>

                        <!-- Chart 1: Category Revenue -->
                        <div class="col-12 col-lg-6">
                            <h3 class="h6 fw-bold mb-3">Which Menu Categories Generate the Most Net Revenue?</h3>
                            <?php if (empty($cat_sales)): ?>
                                <p class="text-muted small">No completed order items yet. Place an order on the checkout page to populate category revenue.</p>
                            <?php else: ?>
                                <?php foreach ($cat_sales as $cs):
                                    $pct = $max_cat_rev > 0 ? round(((float)$cs['revenue'] / $max_cat_rev) * 100) : 0;
                                ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <strong><?= htmlspecialchars($cs['category']) ?> (<?= (int)$cs['items_sold'] ?> items)</strong>
                                            <span class="fw-semibold"><?= format_rp($cs['revenue']) ?></span>
                                        </div>
                                        <div class="chart-bar-track" role="img" aria-label="<?= htmlspecialchars($cs['category']) ?> revenue <?= format_rp($cs['revenue']) ?>">
                                            <div class="chart-bar-fill" style="width: <?= max(4, $pct) ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Chart 2: Cashier Performance -->
                        <div class="col-12 col-lg-6">
                            <h3 class="h6 fw-bold mb-3">How Much Revenue Does Each Cashier Manage?</h3>
                            <?php if (empty($cashier_sales)): ?>
                                <p class="text-muted small">No cashier sales recorded yet.</p>
                            <?php else: ?>
                                <?php foreach ($cashier_sales as $ch):
                                    $pct = $max_cashier_rev > 0 ? round(((float)$ch['revenue'] / $max_cashier_rev) * 100) : 0;
                                ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <strong>Cashier: <?= htmlspecialchars($ch['cashier_name']) ?> (<?= (int)$ch['order_count'] ?> orders)</strong>
                                            <span class="fw-semibold"><?= format_rp($ch['revenue']) ?></span>
                                        </div>
                                        <div class="chart-bar-track" role="img" aria-label="Cashier <?= htmlspecialchars($ch['cashier_name']) ?> revenue <?= format_rp($ch['revenue']) ?>">
                                            <div class="chart-bar-fill" style="width: <?= max(4, $pct) ?>%; background-color: #146c43;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Chart 3: Top Products Sold -->
                        <div class="col-12">
                            <h3 class="h6 fw-bold mb-3 pt-2 border-top">Which Individual Menu Items Sell the Highest Quantity?</h3>
                            <?php if (empty($top_products)): ?>
                                <p class="text-muted small mb-0">No product quantities recorded yet.</p>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($top_products as $tp):
                                        $pct = $max_prod_qty > 0 ? round(((int)$tp['total_qty'] / $max_prod_qty) * 100) : 0;
                                    ?>
                                        <div class="col-12 col-md-6">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span><strong><?= htmlspecialchars($tp['product_name']) ?></strong> <span class="text-muted">(<?= htmlspecialchars($tp['category']) ?>)</span></span>
                                                <span class="fw-semibold"><?= (int)$tp['total_qty'] ?> sold | <?= format_rp($tp['total_rev']) ?></span>
                                            </div>
                                            <div class="chart-bar-track" role="img" aria-label="<?= htmlspecialchars($tp['product_name']) ?> sold <?= (int)$tp['total_qty'] ?>">
                                                <div class="chart-bar-fill" style="width: <?= max(6, $pct) ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Controls -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="orders.php" class="row g-3 align-items-end">
                    <?php if (is_admin()): ?>
                        <div class="col-12 col-md-4">
                            <label for="filterCashier" class="form-label fw-semibold">Filter by Assigned Cashier</label>
                            <select id="filterCashier" name="cashier_id" class="form-select">
                                <option value="0">All Cashiers</option>
                                <?php foreach ($cashiers as $cid => $cname): ?>
                                    <option value="<?= (int)$cid ?>" <?= $filter_cashier === (int)$cid ? 'selected' : '' ?>>
                                        Cashier: <?= htmlspecialchars($cname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-12 col-md-4">
                        <label for="filterStatus" class="form-label fw-semibold">Filter by Order Status</label>
                        <select id="filterStatus" name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="paid" <?= $filter_status === 'paid' ? 'selected' : '' ?>>paid</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>pending</option>
                            <option value="done" <?= $filter_status === 'done' ? 'selected' : '' ?>>done</option>
                            <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $filter_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Filter Orders</button>
                        <?php if ($filter_cashier > 0 || $filter_status !== ''): ?>
                            <a href="orders.php" class="btn btn-secondary">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">
                    <?= is_admin() ? 'All Store Orders' : 'Orders Assigned to ' . htmlspecialchars($_SESSION['username']) ?>
                    (<?= count($orders_list) ?>)
                </h2>
                <?php if (is_admin()): ?>
                    <a href="export_excel.php" class="btn btn-light btn-sm text-dark fw-semibold">Export to Excel (.xls)</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($orders_list)): ?>
                    <!-- Empty State per R-27 -->
                    <div class="text-center py-4">
                        <h3 class="h5">No orders match this view</h3>
                        <p class="text-muted mb-3">
                            <?php if (is_cashier()): ?>
                                There are currently no orders assigned to your cashier account (<?= htmlspecialchars($_SESSION['username']) ?>) matching the selected status filter.
                            <?php else: ?>
                                No orders were found for the selected filter criteria.
                            <?php endif; ?>
                        </p>
                        <a href="order.php" class="btn btn-primary">Create a New Order</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th scope="col">Order ID</th>
                                    <th scope="col">Date &amp; Customer</th>
                                    <th scope="col">Ordered Products</th>
                                    <th scope="col" class="text-end">Subtotal &amp; Discount</th>
                                    <th scope="col" class="text-end">Total Paid</th>
                                    <th scope="col">Assigned Cashier &amp; Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders_list as $ord):
                                    $oid = (int)$ord['id'];
                                    $items = $order_items_map[$oid] ?? [];
                                ?>
                                <tr>
                                    <td class="fw-bold">
                                        #<?= $oid ?>
                                        <?php if (!empty($ord['order_code'])): ?>
                                            <div class="small font-monospace text-muted"><?= htmlspecialchars($ord['order_code']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><strong><?= htmlspecialchars($ord['customer_name']) ?></strong></div>
                                        <?php if ((int)$ord['is_member'] === 1): ?>
                                            <span class="badge bg-success">Member (-10%)</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Guest (Normal)</span>
                                        <?php endif; ?>
                                        <div class="small text-muted mt-1"><?= htmlspecialchars($ord['created_at']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($items)): ?>
                                            <ul class="mb-0 ps-3 small">
                                                <?php foreach ($items as $it): ?>
                                                    <li>
                                                        <strong><?= htmlspecialchars($it['product_name']) ?></strong>
                                                        (<?= htmlspecialchars($it['variant']) ?>) x <?= (int)$it['qty'] ?>
                                                        = <?= format_rp($it['line_total']) ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span class="small text-muted">Legacy order record</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end small">
                                        <div>Subtotal: <?= format_rp($ord['subtotal']) ?></div>
                                        <div class="text-success">Discount: - <?= format_rp($ord['discount']) ?></div>
                                    </td>
                                    <td class="text-end fw-bold"><?= format_rp($ord['total']) ?></td>
                                    <td>
                                        <form method="POST" action="orders.php" class="d-flex flex-column gap-2">
                                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                                            <?php if (is_admin()): ?>
                                                <div>
                                                    <label for="cashier_<?= $oid ?>" class="form-label small mb-1">Cashier:</label>
                                                    <select id="cashier_<?= $oid ?>" name="cashier_id" class="form-select form-select-sm">
                                                        <?php foreach ($cashiers as $cid => $cname): ?>
                                                            <option value="<?= (int)$cid ?>" <?= (int)$ord['cashier_id'] === (int)$cid ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($cname) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php else: ?>
                                                <div class="small">
                                                    <strong>Cashier:</strong> <?= htmlspecialchars($ord['cashier_username'] ?? $_SESSION['username']) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <label for="status_<?= $oid ?>" class="form-label small mb-1">Status:</label>
                                                <select id="status_<?= $oid ?>" name="status" class="form-select form-select-sm">
                                                    <option value="paid" <?= $ord['status'] === 'paid' ? 'selected' : '' ?>>paid</option>
                                                    <option value="pending" <?= $ord['status'] === 'pending' ? 'selected' : '' ?>>pending</option>
                                                    <option value="done" <?= $ord['status'] === 'done' ? 'selected' : '' ?>>done</option>
                                                    <option value="Completed" <?= $ord['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                                    <option value="Cancelled" <?= $ord['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                </select>
                                            </div>
                                            <button type="submit" name="update_order" value="1" class="btn btn-primary btn-sm">Save Update</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if (is_admin()): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (async function loadSalesCharts() {
        try {
            const res = await fetch('api/sales.php', { credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();

            const dailyCtx = document.getElementById('dailyRevenueChart');
            if (dailyCtx && Array.isArray(data.daily)) {
                new Chart(dailyCtx, {
                    type: 'bar',
                    data: {
                        labels: data.daily.map(d => d.day),
                        datasets: [{
                            label: 'Revenue (Rp)',
                            data: data.daily.map(d => Number(d.revenue)),
                            backgroundColor: '#5c3317',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            }

            const topCtx = document.getElementById('topProductsChart');
            if (topCtx && Array.isArray(data.top)) {
                new Chart(topCtx, {
                    type: 'bar',
                    data: {
                        labels: data.top.map(t => t.name.replace(/Frapppe/i, 'Frappe').split(' (')[0]),
                        datasets: [{
                            label: 'Units Sold',
                            data: data.top.map(t => Number(t.qty)),
                            backgroundColor: '#2d5a3c',
                            borderRadius: 4
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } }
                    }
                });
            }
        } catch (err) {
            console.error(err);
        }
    })();
    </script>
    <?php endif; ?>
    <?php include 'footer.php'; ?>
</body>
</html>


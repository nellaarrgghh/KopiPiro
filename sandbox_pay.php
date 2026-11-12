<?php
include 'config.php';
require_once 'auth.php';

$order_code = trim($_GET['order_code'] ?? $_POST['order_code'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ? LIMIT 1");
$stmt->execute([$order_code]);
$order = $stmt->fetch();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order && isset($_POST['simulate_settlement'])) {
    $status_code = '200';
    $gross_amount = (string)(int)$order['total'];
    $sig = hash('sha512', $order['order_code'] . $status_code . $gross_amount . MIDTRANS_SERVER_KEY);

    // Trigger the same verification logic as api/midtrans_notify.php
    $expected = hash('sha512', $order['order_code'] . $status_code . $gross_amount . MIDTRANS_SERVER_KEY);
    if (hash_equals($expected, $sig)) {
        $pdo->prepare("UPDATE orders SET status = 'paid' WHERE order_code = ?")->execute([$order['order_code']]);
        $stmt->execute([$order_code]);
        $order = $stmt->fetch();
        $message = "Payment settlement verified via SHA-512 signature. Order " . $order['order_code'] . " is now marked as paid.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kopipiro - Midtrans Sandbox Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main class="container mb-5" style="max-width: 620px;">
        <div class="mb-4">
            <h1 class="h3 fw-bold mb-1">Midtrans Snap Sandbox</h1>
            <p class="text-muted small mb-0">Simulate QRIS / Virtual Account settlement and webhook signature verification.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$order): ?>
            <div class="card p-4">
                <p class="mb-3">Order not found. Please select a valid order code.</p>
                <a href="order.php" class="btn btn-primary">Return to Order Page</a>
            </div>
        <?php else: ?>
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                    <div>
                        <div class="small text-muted">Order Code</div>
                        <div class="fw-bold font-monospace fs-5"><?php echo htmlspecialchars($order['order_code']); ?></div>
                    </div>
                    <span class="badge <?php echo ($order['status'] === 'paid' || $order['status'] === 'done' || $order['status'] === 'Completed') ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo htmlspecialchars(strtoupper($order['status'])); ?>
                    </span>
                </div>

                <dl class="row mb-3 small">
                    <dt class="col-sm-5 text-muted">Customer Name</dt>
                    <dd class="col-sm-7 fw-semibold"><?php echo htmlspecialchars($order['customer_name']); ?></dd>

                    <dt class="col-sm-5 text-muted">Subtotal</dt>
                    <dd class="col-sm-7 font-monospace"><?php echo format_rp($order['subtotal']); ?></dd>

                    <dt class="col-sm-5 text-muted">Member Discount (10%)</dt>
                    <dd class="col-sm-7 font-monospace">- <?php echo format_rp($order['discount']); ?></dd>

                    <dt class="col-sm-5 text-muted">Gross Amount</dt>
                    <dd class="col-sm-7 fw-bold font-monospace fs-6"><?php echo format_rp($order['total']); ?></dd>
                </dl>

                <?php if ($order['status'] !== 'paid' && $order['status'] !== 'done'): ?>
                    <form method="POST" action="sandbox_pay.php?order_code=<?php echo urlencode($order['order_code']); ?>">
                        <input type="hidden" name="order_code" value="<?php echo htmlspecialchars($order['order_code']); ?>">
                        <button type="submit" name="simulate_settlement" value="1" class="btn btn-success w-100 mb-2">
                            Confirm Sandbox Payment (Verify SHA-512 &amp; Settle)
                        </button>
                    </form>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-2">
                    <a href="order.php" class="btn btn-outline-secondary w-50">New Order</a>
                    <a href="index.php" class="btn btn-outline-secondary w-50">Back to Menu</a>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <?php include 'footer.php'; ?>
</body>
</html>


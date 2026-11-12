<?php
include 'auth.php';
include 'config.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$is_member = has_member_discount();
$discount_rate = $is_member ? 0.10 : 0.00;
$default_customer = $is_member ? ($_SESSION['username'] ?? 'Member') : 'Guest';

$error = "";
$status_msg = "";
$order_success = false;
$receipt = null;

// Load cashiers and pick the cashier with the fewest unfinished orders as default
$cashiers = [];
$c_res = mysqli_query($conn, "SELECT id, username, name FROM users WHERE role = 'cashier' ORDER BY id ASC");
if ($c_res) {
    while ($c = mysqli_fetch_assoc($c_res)) {
        $cashiers[] = $c;
    }
}
$least_busy_res = mysqli_query(
    $conn,
    "SELECT u.id FROM users u
     LEFT JOIN orders o ON o.cashier_id = u.id AND o.status <> 'done'
     WHERE u.role = 'cashier'
     GROUP BY u.id ORDER BY COUNT(o.id) ASC, u.id ASC LIMIT 1"
);
$least_busy_row = $least_busy_res ? mysqli_fetch_assoc($least_busy_res) : null;
$default_cashier_id = $least_busy_row ? (int)$least_busy_row['id'] : (!empty($cashiers) ? (int)$cashiers[0]['id'] : null);

// Remove single item from cart
if (isset($_GET['remove'])) {
    $rm_key = $_GET['remove'];
    if (isset($_SESSION['cart'][$rm_key])) {
        unset($_SESSION['cart'][$rm_key]);
        $status_msg = "Item removed from your checkout list.";
    }
}

// Clear entire cart
if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    $_SESSION['cart'] = [];
    $status_msg = "Your checkout cart has been cleared.";
}

// Update quantities in existing cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    $cart_qtys = $_POST['cart_qty'] ?? [];
    foreach ($cart_qtys as $k => $q) {
        $q = max(0, (int)$q);
        if ($q <= 0) {
            unset($_SESSION['cart'][$k]);
        } elseif (isset($_SESSION['cart'][$k])) {
            $_SESSION['cart'][$k]['qty'] = $q;
        }
    }
    $status_msg = "Checkout quantities and totals updated.";
}

// Add multiple products from the quick selector on order.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_order_page'])) {
    $qtys = $_POST['qty'] ?? [];
    $temps = $_POST['temp'] ?? [];
    $flavors = $_POST['flavor'] ?? [];
    $added = 0;

    foreach ($qtys as $pid => $raw_qty) {
        $pid = (int)$pid;
        $qty = max(0, (int)$raw_qty);
        if ($qty <= 0) {
            continue;
        }
        $stmt = mysqli_prepare($conn, "SELECT id, name, category, temp_type, price, price_cold, price_hot FROM product WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $pid);
        mysqli_stmt_execute($stmt);
        $prod = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$prod) {
            continue;
        }

        $chosen_temp = $temps[$pid] ?? 'default';
        $unit_price = (float)$prod['price'];
        $variant_parts = [];

        if ($prod['temp_type'] === 'both') {
            if ($chosen_temp === 'hot' && $prod['price_hot'] !== null) {
                $unit_price = (float)$prod['price_hot'];
                $variant_parts[] = 'Hot (H)';
            } else {
                $unit_price = (float)($prod['price_cold'] ?? $prod['price']);
                $variant_parts[] = 'Cold (C)';
            }
        } elseif ($prod['temp_type'] === 'cold_only') {
            $unit_price = (float)($prod['price_cold'] ?? $prod['price']);
            $variant_parts[] = 'Cold (C)';
        } elseif ($prod['temp_type'] === 'hot_only') {
            $unit_price = (float)($prod['price_hot'] ?? $prod['price']);
            $variant_parts[] = 'Hot (H)';
        }

        if (!empty($flavors[$pid])) {
            $variant_parts[] = 'Flavor: ' . trim($flavors[$pid]);
        }

        $variant_str = empty($variant_parts) ? 'Standard' : implode(', ', $variant_parts);
        $cart_key = $pid . '_' . md5($variant_str);

        if (isset($_SESSION['cart'][$cart_key])) {
            $_SESSION['cart'][$cart_key]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$cart_key] = [
                'product_id' => $pid,
                'name'       => $prod['name'],
                'category'   => $prod['category'],
                'variant'    => $variant_str,
                'unit_price' => $unit_price,
                'qty'        => $qty
            ];
        }
        $added += $qty;
    }

    if ($added === 0) {
        $error = "Please enter a quantity of at least 1 on one or more products to add them to checkout.";
    } else {
        $status_msg = "Added $added item(s) to your checkout product list below.";
    }
}

// Finalize and place order from the checkout list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    // Also update any adjusted quantities submitted with place_order
    if (!empty($_POST['cart_qty']) && is_array($_POST['cart_qty'])) {
        foreach ($_POST['cart_qty'] as $k => $q) {
            $q = max(0, (int)$q);
            if ($q <= 0) {
                unset($_SESSION['cart'][$k]);
            } elseif (isset($_SESSION['cart'][$k])) {
                $_SESSION['cart'][$k]['qty'] = $q;
            }
        }
    }

    if (empty($_SESSION['cart'])) {
        $error = "Your checkout list is empty. Please add at least one product before placing your order.";
    } else {
        $customer_name = trim($_POST['customer_name'] ?? $default_customer);
        if ($customer_name === '') {
            $customer_name = $default_customer;
        }
        $cashier_id = isset($_POST['cashier_id']) && (int)$_POST['cashier_id'] > 0 ? (int)$_POST['cashier_id'] : $default_cashier_id;
        $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $is_member_flag = $is_member ? 1 : 0;

        $subtotal = 0.0;
        $total_discount = 0.0;
        $receipt_items = [];

        foreach ($_SESSION['cart'] as $item) {
            $qty = (int)$item['qty'];
            $unit_price = (float)$item['unit_price'];
            $line_normal = $unit_price * $qty;
            // Apply 10% discount when customer is logged in, 0% when Guest
            $unit_disc = $is_member ? round($unit_price * $discount_rate, 2) : 0.0;
            $final_unit = $unit_price - $unit_disc;
            $line_total = round($final_unit * $qty, 2);
            $line_disc = round($line_normal - $line_total, 2);

            $subtotal += $line_normal;
            $total_discount += $line_disc;

            $receipt_items[] = [
                'product_id'      => (int)$item['product_id'],
                'name'            => $item['name'],
                'category'        => $item['category'],
                'variant'         => $item['variant'],
                'qty'             => $qty,
                'unit_price'      => $unit_price,
                'discount_amount' => $unit_disc,
                'final_unit_price'=> $final_unit,
                'line_total'      => $line_total
            ];
        }

        $grand_total = round($subtotal - $total_discount, 2);

        $member_id = isset($_SESSION['member_id']) ? (int)$_SESSION['member_id'] : null;
        if ($member_id === null && $user_id !== null) {
            $stmt_find_m = mysqli_prepare($conn, "SELECT id FROM members WHERE user_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_find_m, "i", $user_id);
            mysqli_stmt_execute($stmt_find_m);
            $mrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_find_m));
            if ($mrow) {
                $member_id = (int)$mrow['id'];
                $_SESSION['member_id'] = $member_id;
            }
        }

        $order_code = 'KP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        mysqli_begin_transaction($conn);
        $stmt_o = mysqli_prepare($conn, "INSERT INTO orders (order_code, user_id, member_id, customer_name, is_member, cashier_id, status, subtotal, discount, total) VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?)");
        mysqli_stmt_bind_param($stmt_o, "siisiiddd", $order_code, $user_id, $member_id, $customer_name, $is_member_flag, $cashier_id, $subtotal, $total_discount, $grand_total);
        $ok_order = mysqli_stmt_execute($stmt_o);
        $order_id = (int)mysqli_insert_id($conn);

        $ok_items = true;
        if ($ok_order && $order_id > 0) {
            $stmt_oi = mysqli_prepare($conn, "INSERT INTO order_items (order_id, product_id, product_name, category, variant, qty, unit_price, discount_amount, final_unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($receipt_items as $ri) {
                mysqli_stmt_bind_param(
                    $stmt_oi,
                    "iisssidddd",
                    $order_id,
                    $ri['product_id'],
                    $ri['name'],
                    $ri['category'],
                    $ri['variant'],
                    $ri['qty'],
                    $ri['unit_price'],
                    $ri['discount_amount'],
                    $ri['final_unit_price'],
                    $ri['line_total']
                );
                if (!mysqli_stmt_execute($stmt_oi)) {
                    $ok_items = false;
                    break;
                }
            }
        } else {
            $ok_items = false;
        }

        if ($ok_order && $ok_items) {
            mysqli_commit($conn);
        } else {
            mysqli_rollback($conn);
            $error = "Failed to save order to the database: " . mysqli_error($conn);
        }

        if ($error === '') {
            $cashier_name = 'Unassigned';
            foreach ($cashiers as $c) {
                if ((int)$c['id'] === (int)$cashier_id) {
                    $cashier_name = $c['username'];
                    break;
                }
            }

            $receipt = [
                'id'           => $order_id,
                'order_code'   => $order_code,
                'customer'     => $customer_name,
                'is_member'    => $is_member,
                'member_id'    => $member_id,
                'cashier_name' => $cashier_name,
                'items'        => $receipt_items,
                'subtotal'     => $subtotal,
                'discount'     => $total_discount,
                'total'        => $grand_total,
                'datetime'     => date('Y-m-d H:i:s')
            ];

            $_SESSION['cart'] = [];
            $order_success = true;
        }
    }
}

$all_products_res = mysqli_query($conn, "SELECT id, name, category, temp_type, price, price_cold, price_hot, description, rating, url_image FROM product WHERE is_active = 1 ORDER BY FIELD(category, 'Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots'), id ASC");
$grouped_products = [];
if ($all_products_res) {
    while ($p = mysqli_fetch_assoc($all_products_res)) {
        $grouped_products[$p['category']][] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order &amp; Checkout - Kopipiro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
            <div>
                <h1 class="h3 mb-1">Kopipiro Order Checkout</h1>
                <p class="text-muted mb-0">Review your selected products, verify discounts, and place your order.</p>
            </div>
            <a href="index.php" class="btn btn-secondary">Back to Full Menu Catalog</a>
        </div>

        <!-- Discount Status Banner -->
        <div class="alert <?= $is_member ? 'alert-success' : 'alert-warning' ?> d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2" role="region" aria-label="Customer pricing status">
            <?php if ($is_member): ?>
                <div>
                    <strong>Status: Registered Member (<?= htmlspecialchars($_SESSION['username']) ?>)</strong><br>
                    <span>A <strong>10% member discount</strong> is applied to every item on your checkout list.</span>
                </div>
            <?php else: ?>
                <div>
                    <strong>Status: Guest Buyer (Not Logged In)</strong><br>
                    <span>Normal menu prices apply (0% discount). Log in or register before confirming to save 10%.</span>
                </div>
                <div class="d-flex gap-2">
                    <a href="login.php" class="btn btn-primary btn-sm">Log In for 10% Off</a>
                    <a href="register.php" class="btn btn-dark btn-sm">Register Now</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($status_msg !== ''): ?>
            <div class="alert alert-success" role="status">
                <strong>Updated:</strong> <?= htmlspecialchars($status_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Order Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($order_success && $receipt): ?>
            <!-- Order Receipt View -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">Order Confirmed - Kopipiro Official Receipt #<?= (int)$receipt['id'] ?></h2>
                    <button type="button" class="btn btn-light btn-sm text-dark" onclick="window.print()">Print Receipt</button>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-12 col-md-6">
                            <p class="mb-1"><strong>Order Code:</strong> <code><?= htmlspecialchars($receipt['order_code']) ?></code> (#<?= (int)$receipt['id'] ?>)</p>
                            <p class="mb-1"><strong>Date &amp; Time:</strong> <?= htmlspecialchars($receipt['datetime']) ?></p>
                        </div>
                        <div class="col-12 col-md-6">
                            <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($receipt['customer']) ?> (<?= $receipt['is_member'] ? 'Member - 10% Off' : 'Guest - Normal Price' ?>)</p>
                            <p class="mb-1"><strong>Assigned Cashier:</strong> <?= htmlspecialchars($receipt['cashier_name']) ?></p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">Option / Variant</th>
                                    <th scope="col" class="text-center">Qty</th>
                                    <th scope="col" class="text-end">Normal Price</th>
                                    <th scope="col" class="text-end">Member Discount</th>
                                    <th scope="col" class="text-end">Net Unit Price</th>
                                    <th scope="col" class="text-end">Line Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($receipt['items'] as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['name']) ?></strong>
                                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars($item['category']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($item['variant']) ?></td>
                                    <td class="text-center"><?= (int)$item['qty'] ?></td>
                                    <td class="text-end"><?= format_rp($item['unit_price']) ?></td>
                                    <td class="text-end text-success">
                                        <?= $item['discount_amount'] > 0 ? '- ' . format_rp($item['discount_amount']) . ' (10%)' : 'Rp 0 (0%)' ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= format_rp($item['final_unit_price']) ?></td>
                                    <td class="text-end fw-bold"><?= format_rp($item['line_total']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-end">Subtotal (Normal Price)</th>
                                    <td class="text-end"><?= format_rp($receipt['subtotal']) ?></td>
                                </tr>
                                <tr>
                                    <th colspan="6" class="text-end">Total Discount <?= $receipt['is_member'] ? '(10% Member Discount)' : '(0% Guest)' ?></th>
                                    <td class="text-end text-success">- <?= format_rp($receipt['discount']) ?></td>
                                </tr>
                                <tr class="table-secondary">
                                    <th colspan="6" class="text-end fs-5">TOTAL TO PAY</th>
                                    <td class="text-end fs-5 fw-bold"><?= format_rp($receipt['total']) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a href="sandbox_pay.php?order_code=<?= urlencode($receipt['order_code']) ?>" class="btn btn-success">Pay Online (Midtrans Sandbox)</a>
                        <a href="order.php" class="btn btn-primary">Place Another Order</a>
                        <a href="index.php" class="btn btn-secondary">Explore Menu</a>
                        <?php if (is_logged_in() && is_customer()): ?>
                            <a href="my_orders.php" class="btn btn-dark">View My Order History</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Section 1: Checkout Product List & Price Breakdown (Feature 3) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">1. Checkout Product List &amp; Discount Summary</h2>
                    <?php if (!empty($_SESSION['cart'])): ?>
                        <a href="order.php?clear=1" class="btn btn-danger btn-sm" onclick="return confirm('Clear all items from your checkout list?');">Clear List</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($_SESSION['cart'])): ?>
                        <!-- Empty State per R-27 -->
                        <div class="text-center py-4">
                            <h3 class="h5">Your checkout list is currently empty</h3>
                            <p class="text-muted mb-2">Use the Multi-Product Selector right below or browse the menu catalog to add drinks, toast, desserts, and add-ons.</p>
                            <a href="#quickSelector" class="btn btn-primary">Select Products Below</a>
                        </div>
                    <?php else: ?>
                        <form method="POST" action="order.php">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th scope="col">No</th>
                                            <th scope="col">Product &amp; Category</th>
                                            <th scope="col">Option / Variant</th>
                                            <th scope="col" class="text-end">Normal Price</th>
                                            <th scope="col" class="text-end">Discount <?= $is_member ? '(10%)' : '(0%)' ?></th>
                                            <th scope="col" class="text-end">Discounted Price</th>
                                            <th scope="col" style="width: 120px;">Quantity</th>
                                            <th scope="col" class="text-end">Line Total</th>
                                            <th scope="col" class="text-center">Remove</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $idx = 1;
                                        $calc_subtotal = 0.0;
                                        $calc_discount = 0.0;
                                        foreach ($_SESSION['cart'] as $ckey => $citem):
                                            $u_price = (float)$citem['unit_price'];
                                            $qty = (int)$citem['qty'];
                                            $u_disc = $is_member ? round($u_price * $discount_rate, 2) : 0.0;
                                            $u_net = $u_price - $u_disc;
                                            $line_normal = $u_price * $qty;
                                            $line_net = round($u_net * $qty, 2);
                                            $calc_subtotal += $line_normal;
                                            $calc_discount += round($line_normal - $line_net, 2);
                                        ?>
                                        <tr>
                                            <td><?= $idx++ ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($citem['name']) ?></strong><br>
                                                <span class="badge bg-secondary"><?= htmlspecialchars($citem['category']) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars($citem['variant']) ?></td>
                                            <td class="text-end"><?= format_rp($u_price) ?></td>
                                            <td class="text-end text-success">
                                                <?= $u_disc > 0 ? '- ' . format_rp($u_disc) : 'Rp 0' ?>
                                            </td>
                                            <td class="text-end fw-semibold">
                                                <?php if ($is_member && $u_price > 0): ?>
                                                    <del class="text-muted small me-1"><?= format_rp($u_price) ?></del>
                                                    <span class="text-success"><?= format_rp($u_net) ?></span>
                                                <?php else: ?>
                                                    <?= format_rp($u_net) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <label for="cqty_<?= htmlspecialchars($ckey) ?>" class="visually-hidden">Quantity for <?= htmlspecialchars($citem['name']) ?></label>
                                                <input type="number" id="cqty_<?= htmlspecialchars($ckey) ?>" name="cart_qty[<?= htmlspecialchars($ckey) ?>]" value="<?= $qty ?>" min="0" max="99" class="form-control form-control-sm">
                                            </td>
                                            <td class="text-end fw-bold"><?= format_rp($line_net) ?></td>
                                            <td class="text-center">
                                                <a href="order.php?remove=<?= urlencode($ckey) ?>" class="btn btn-danger btn-sm" aria-label="Remove <?= htmlspecialchars($citem['name']) ?>">Remove</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="7" class="text-end">Subtotal (Normal Price)</th>
                                            <td colspan="2" class="text-end fw-semibold"><?= format_rp($calc_subtotal) ?></td>
                                        </tr>
                                        <tr>
                                            <th colspan="7" class="text-end">Member Discount <?= $is_member ? '(10% Applied)' : '(0% - Not Logged In)' ?></th>
                                            <td colspan="2" class="text-end text-success fw-semibold">- <?= format_rp($calc_discount) ?></td>
                                        </tr>
                                        <tr class="table-secondary">
                                            <th colspan="7" class="text-end fs-5">GRAND TOTAL TO PAY</th>
                                            <td colspan="2" class="text-end fs-5 fw-bold"><?= format_rp($calc_subtotal - $calc_discount) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <div class="row g-3 align-items-end mt-2 pt-3 border-top">
                                <div class="col-12 col-md-4">
                                    <label for="customer_name" class="form-label fw-semibold">Customer Name</label>
                                    <input type="text" id="customer_name" name="customer_name" class="form-control" value="<?= htmlspecialchars($default_customer) ?>" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="cashier_id" class="form-label fw-semibold">Assigned Cashier Counter</label>
                                    <select id="cashier_id" name="cashier_id" class="form-select">
                                        <?php foreach ($cashiers as $c): ?>
                                            <option value="<?= (int)$c['id'] ?>">Cashier: <?= htmlspecialchars($c['username']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
                                    <button type="submit" name="update_cart" value="1" class="btn btn-secondary">Recalculate Quantities</button>
                                    <button type="submit" name="place_order" value="1" class="btn btn-success flex-grow-1">Confirm &amp; Place Order</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Section 2: Multi-Product Selector right on order.php (Feature 2) -->
            <div class="card shadow-sm" id="quickSelector">
                <div class="card-header bg-primary text-white d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                    <h2 class="h5 mb-0">2. Add Products to Your Order (Select Multiple Items)</h2>
                    <button type="submit" form="quickOrderForm" name="add_from_order_page" value="1" class="btn btn-light btn-sm text-dark fw-semibold">
                        Add Selected Items to Checkout List Above
                    </button>
                </div>
                <div class="card-body">
                    <form method="POST" action="order.php" id="quickOrderForm">
                        <input type="hidden" name="add_from_order_page" value="1">
                        <?php foreach ($grouped_products as $cat_name => $items): ?>
                            <h3 class="h5 mt-3 mb-2 pb-1 border-bottom"><?= htmlspecialchars($cat_name) ?></h3>
                            <div class="table-responsive mb-3">
                                <table class="table table-bordered table-sm align-middle">
                                    <thead class="table-dark">
                                        <tr>
                                            <th scope="col" style="width: 96px;">Image</th>
                                            <th scope="col" class="name-col">Item Name</th>
                                            <th scope="col" class="desc-col">Description &amp; Rating</th>
                                            <th scope="col" class="price-col">Price<?= $is_member ? ' (10% Off)' : '' ?></th>
                                            <th scope="col" style="width: 240px;">Option &amp; Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $p):
                                            $pid = (int)$p['id'];
                                            $is_frappe = (stripos($p['name'], 'Frapppe') !== false || stripos($p['name'], 'Frappe') !== false);
                                            $is_icecream = (stripos($p['name'], 'Ice cream') !== false);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty(trim((string)$p['url_image']))): ?>
                                                    <img src="<?= htmlspecialchars($p['url_image']) ?>" width="80" height="80" class="product-thumb" alt="<?= htmlspecialchars($p['name']) ?>">
                                                <?php else: ?>
                                                    <div class="img-placeholder" role="img" aria-label="No product image linked for <?= htmlspecialchars($p['name']) ?>">
                                                        No image linked
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-semibold name-col"><?= format_product_name($p['name']) ?></td>
                                            <td class="small desc-col">
                                                <?= !empty($p['description']) ? htmlspecialchars($p['description']) : '<span class="text-muted">Add-on</span>' ?>
                                                <?php if (!empty($p['rating'])): ?>
                                                    <div class="mt-1"><span class="badge bg-primary"><?= htmlspecialchars($p['rating']) ?></span></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small price-col">
                                                <?php if ($p['temp_type'] === 'both'): ?>
                                                    <div class="mb-1"><strong>Cold (C):</strong> <?= $is_member ? '<del class="text-muted">' . format_rp($p['price_cold']) . '</del> <strong class="text-success ms-1">' . format_rp($p['price_cold'] * 0.9) . '</strong>' : format_rp($p['price_cold']) ?></div>
                                                    <div><strong>Hot (H):</strong> <?= $is_member ? '<del class="text-muted">' . format_rp($p['price_hot']) . '</del> <strong class="text-success ms-1">' . format_rp($p['price_hot'] * 0.9) . '</strong>' : format_rp($p['price_hot']) ?></div>
                                                <?php elseif ($p['temp_type'] === 'cold_only'): ?>
                                                    <?php $base = $p['price_cold'] ?? $p['price']; ?>
                                                    <div><strong>Cold (C):</strong> <?= ($is_member && $base > 0) ? '<del class="text-muted">' . format_rp($base) . '</del> <strong class="text-success ms-1">' . format_rp($base * 0.9) . '</strong>' : format_rp($base) ?></div>
                                                <?php elseif ($p['temp_type'] === 'hot_only'): ?>
                                                    <?php $base = $p['price_hot'] ?? $p['price']; ?>
                                                    <div><strong>Hot (H):</strong> <?= ($is_member && $base > 0) ? '<del class="text-muted">' . format_rp($base) . '</del> <strong class="text-success ms-1">' . format_rp($base * 0.9) . '</strong>' : format_rp($base) ?></div>
                                                <?php else: ?>
                                                    <?php $base = $p['price_cold'] ?? $p['price']; ?>
                                                    <div><?= ($is_member && $base > 0) ? '<del class="text-muted">' . format_rp($base) . '</del> <strong class="text-success ms-1">' . format_rp($base * 0.9) . '</strong>' : '<strong>' . format_rp($base) . '</strong>' ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column gap-1">
                                                    <?php if ($p['temp_type'] === 'both'): ?>
                                                        <label for="qtemp_<?= $pid ?>" class="visually-hidden">Temperature for <?= htmlspecialchars($p['name']) ?></label>
                                                        <select id="qtemp_<?= $pid ?>" name="temp[<?= $pid ?>]" class="form-select form-select-sm">
                                                            <option value="cold">Cold (C)</option>
                                                            <option value="hot">Hot (H)</option>
                                                        </select>
                                                    <?php endif; ?>
                                                    <?php if ($is_frappe): ?>
                                                        <label for="qflav_<?= $pid ?>" class="visually-hidden">Flavor for <?= htmlspecialchars($p['name']) ?></label>
                                                        <select id="qflav_<?= $pid ?>" name="flavor[<?= $pid ?>]" class="form-select form-select-sm">
                                                            <option value="Matcha">Matcha</option>
                                                            <option value="Vanilla">Vanilla</option>
                                                            <option value="Chocolate">Chocolate</option>
                                                            <option value="Caramel">Caramel</option>
                                                        </select>
                                                    <?php elseif ($is_icecream): ?>
                                                        <label for="qflav_<?= $pid ?>" class="visually-hidden">Flavor for <?= htmlspecialchars($p['name']) ?></label>
                                                        <select id="qflav_<?= $pid ?>" name="flavor[<?= $pid ?>]" class="form-select form-select-sm">
                                                            <option value="Matcha">Matcha</option>
                                                            <option value="Chocolate">Chocolate</option>
                                                            <option value="Vanilla">Vanilla</option>
                                                            <option value="Strawberry">Strawberry</option>
                                                            <option value="Ube">Ube</option>
                                                            <option value="Durian">Durian</option>
                                                        </select>
                                                    <?php endif; ?>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <label for="qqty_<?= $pid ?>" class="small mb-0">Qty:</label>
                                                        <input type="number" id="qqty_<?= $pid ?>" name="qty[<?= $pid ?>]" value="0" min="0" max="99" class="form-control form-control-sm">
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>

                        <div class="d-flex justify-content-end pt-2 border-top">
                            <button type="submit" name="add_from_order_page" value="1" class="btn btn-primary">
                                Add Selected Items to Checkout List
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>

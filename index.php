<?php
include 'auth.php';
include 'config.php';

$status_msg = "";
$error_msg = "";

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') {
        $status_msg = "Product added to the Kopipiro menu.";
    } elseif ($_GET['msg'] === 'updated') {
        $status_msg = "Product details updated.";
    } elseif ($_GET['msg'] === 'deleted') {
        $status_msg = "Product removed from the menu.";
    } elseif ($_GET['msg'] === 'added') {
        $status_msg = "Selected items added to your order cart.";
    }
}

// Handle adding one or multiple products from the catalog to the session cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['catalog_order'])) {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $qtys = $_POST['qty'] ?? [];
    $temps = $_POST['temp'] ?? [];
    $flavors = $_POST['flavor'] ?? [];
    $added_count = 0;

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
        $added_count += $qty;
    }

    if ($added_count === 0) {
        $error_msg = "Please set a quantity of at least 1 on one or more products before adding to your order.";
    } else {
        if (isset($_POST['go_checkout']) && $_POST['go_checkout'] === '1') {
            header("Location: order.php");
            exit;
        }
        header("Location: index.php?msg=added");
        exit;
    }
}

$selected_category = trim($_GET['category'] ?? '');
$search_term = trim($_GET['q'] ?? '');

$categories_res = mysqli_query($conn, "SELECT DISTINCT category FROM product ORDER BY FIELD(category, 'Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots'), category");
$categories = [];
if ($categories_res) {
    while ($c = mysqli_fetch_assoc($categories_res)) {
        $categories[] = $c['category'];
    }
}

$sql = "SELECT id, name, category, temp_type, price, price_cold, price_hot, description, rating, url_image FROM product WHERE is_active = 1";
$params = [];
$types = "";

if ($selected_category !== '') {
    $sql .= " AND category = ?";
    $params[] = $selected_category;
    $types .= "s";
}
if ($search_term !== '') {
    $sql .= " AND (name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $like = "%" . $search_term . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}
$sql .= " ORDER BY FIELD(category, 'Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots'), id ASC";

$stmt = mysqli_prepare($conn, $sql);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = false;
    $error_msg = "Could not load menu products from the database. Check your database connection.";
}

$is_member = has_member_discount();
$weather = fetch_jakarta_weather();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kopipiro Menu &amp; Coffee Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h1 class="h3 mb-1">Kopipiro Menu Catalog</h1>
                <p class="text-muted mb-0">Explore coffee, non-coffee beverages, toast, desserts, and add-ons. Select quantities for multiple products to order together.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if (is_admin()): ?>
                    <a href="create_product.php" class="btn btn-success">Add New Product</a>
                    <a href="orders.php" class="btn btn-primary">View All Orders &amp; Sales Charts</a>
                <?php elseif (is_cashier()): ?>
                    <a href="orders.php" class="btn btn-primary">View My Assigned Orders</a>
                <?php endif; ?>
                <a href="order.php" class="btn btn-dark">
                    Go to Checkout (<?= cart_item_count() ?> items)
                </a>
            </div>
        </div>

        <!-- Membership Discount Status Banner -->
        <div class="alert <?= $is_member ? 'alert-success' : 'alert-info' ?> d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2" role="region" aria-label="Discount status">
            <?php if ($is_member): ?>
                <div>
                    <strong>Member Discount Active (10% Off):</strong> Logged in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>. All prices below show your 10% member discount.
                </div>
                <a href="order.php" class="btn btn-success btn-sm">Review Order &amp; Checkout</a>
            <?php else: ?>
                <div>
                    <strong>Guest Mode (Normal Prices):</strong> You are browsing without logging in. Register and log in to receive a <strong>10% discount</strong> on your order.
                </div>
                <div class="d-flex gap-2">
                    <a href="login.php" class="btn btn-primary btn-sm">Log In for 10% Off</a>
                    <a href="register.php" class="btn btn-secondary btn-sm">Register Account</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($status_msg !== ''): ?>
            <div class="alert alert-success" role="status">
                <strong>Success:</strong> <?= htmlspecialchars($status_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Action Required:</strong> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Live Open-Meteo Weather Bar via PHP cURL (PDF Section 4.2 & Exercise 5) -->
        <?php if (isset($weather['current'])):
            $temp_c = (float)($weather['current']['temperature_2m'] ?? 28);
            $wind_k = (float)($weather['current']['wind_speed_10m'] ?? 0);
            $weather_tip = $temp_c >= 28.0
                ? "Warm day in Jakarta ({$temp_c} C) - try our Iced Mont Blanc or Cold Spanish Latte."
                : "Pleasant weather in Jakarta ({$temp_c} C) - a Hot Cappuccino or Hot Butterscotch Latte pairs well today.";
        ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body py-2 px-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 small">
                    <div>
                        <strong>Live Store Weather (Open-Meteo API via cURL):</strong>
                        <?= htmlspecialchars($weather_tip) ?>
                    </div>
                    <span class="badge bg-secondary font-monospace">
                        Temp: <?= htmlspecialchars((string)$temp_c) ?> C | Wind: <?= htmlspecialchars((string)$wind_k) ?> km/h
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter & Search Bar -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" action="index.php" class="row g-3 align-items-end">
                    <div class="col-12 col-md-4">
                        <label for="categoryFilter" class="form-label fw-semibold">Filter by Category</label>
                        <select id="categoryFilter" name="category" class="form-select">
                            <option value="">All Categories (Coffee, Non-Coffee, Toast, Dessert, Add-ons)</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $selected_category === $cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-5">
                        <label for="searchQuery" class="form-label fw-semibold">Search Menu</label>
                        <input type="text" id="searchQuery" name="q" class="form-control" value="<?= htmlspecialchars($search_term) ?>" placeholder="Search by item name or description (e.g. Mont Blanc, Matcha, Toast)">
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">Apply Filter</button>
                        <?php if ($selected_category !== '' || $search_term !== ''): ?>
                            <a href="index.php" class="btn btn-secondary">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Product Catalog Table inside Multi-Product Order Form -->
        <form method="POST" action="index.php" id="catalogOrderForm">
            <input type="hidden" name="catalog_order" value="1">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                    <h2 class="h5 mb-0">Explore Products &amp; Select Quantities</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="go_checkout" value="0" class="btn btn-light btn-sm text-dark fw-semibold">
                            Add Selected to Cart
                        </button>
                        <button type="submit" name="go_checkout" value="1" class="btn btn-dark btn-sm fw-semibold">
                            Add Selected &amp; Proceed to Checkout
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!$result || mysqli_num_rows($result) === 0): ?>
                        <!-- Empty State per R-27 -->
                        <div class="text-center py-5">
                            <h3 class="h5">No menu items match your current filter</h3>
                            <p class="text-muted mb-3">
                                <?php if ($selected_category !== '' || $search_term !== ''): ?>
                                    No products were found for category "<?= htmlspecialchars($selected_category ?: 'All') ?>" and search "<?= htmlspecialchars($search_term) ?>".
                                <?php else: ?>
                                    The menu catalog currently has no products stored.
                                <?php endif; ?>
                            </p>
                            <div class="d-flex justify-content-center gap-2">
                                <a href="index.php" class="btn btn-primary">Show All Menu Items</a>
                                <?php if (is_admin()): ?>
                                    <a href="create_product.php" class="btn btn-success">Add First Product</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-hover align-middle mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">No</th>
                                        <th scope="col">Image</th>
                                        <th scope="col" class="name-col">Product &amp; Category</th>
                                        <th scope="col" class="desc-col">Description &amp; Rating</th>
                                        <th scope="col" class="price-col">Price<?= $is_member ? ' (10% Off)' : ' (Normal)' ?></th>
                                        <th scope="col" style="min-width: 220px;">Select Option &amp; Quantity</th>
                                        <?php if (is_admin()): ?>
                                            <th scope="col" class="text-center">Admin Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; while ($row = mysqli_fetch_assoc($result)):
                                        $pid = (int)$row['id'];
                                        $has_flavors_frappe = (stripos($row['name'], 'Frapppe') !== false || stripos($row['name'], 'Frappe') !== false);
                                        $has_flavors_icecream = (stripos($row['name'], 'Ice cream') !== false);
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <?php if (!empty(trim($row['url_image']))): ?>
                                                <img src="<?= htmlspecialchars($row['url_image']) ?>" width="80" height="80" class="product-thumb" alt="<?= htmlspecialchars($row['name']) ?>">
                                            <?php else: ?>
                                                <div class="img-placeholder" role="img" aria-label="No product image linked for <?= htmlspecialchars($row['name']) ?>">
                                                    No image linked
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="name-col">
                                            <div class="fw-bold"><?= format_product_name($row['name']) ?></div>
                                            <span class="badge bg-secondary mt-1"><?= htmlspecialchars($row['category']) ?></span>
                                        </td>
                                        <td class="desc-col">
                                            <?php if (!empty(trim((string)$row['description']))): ?>
                                                <div><?= htmlspecialchars($row['description']) ?></div>
                                            <?php else: ?>
                                                <div class="text-muted small">Add-on item (no description)</div>
                                            <?php endif; ?>

                                            <?php if (!empty(trim((string)$row['rating']))): ?>
                                                <div class="mt-1 small">
                                                    <strong>Rating:</strong> <span class="badge bg-primary"><?= htmlspecialchars($row['rating']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="price-col">
                                            <?php if ($row['temp_type'] === 'both'): ?>
                                                <div class="mb-1">
                                                    <strong>Cold (C):</strong>
                                                    <?php if ($is_member && $row['price_cold'] > 0): ?>
                                                        <del class="text-muted"><?= format_rp($row['price_cold']) ?></del>
                                                        <strong class="text-success ms-1"><?= format_rp($row['price_cold'] * 0.9) ?></strong>
                                                    <?php else: ?>
                                                        <span><?= format_rp($row['price_cold']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong>Hot (H):</strong>
                                                    <?php if ($is_member && $row['price_hot'] > 0): ?>
                                                        <del class="text-muted"><?= format_rp($row['price_hot']) ?></del>
                                                        <strong class="text-success ms-1"><?= format_rp($row['price_hot'] * 0.9) ?></strong>
                                                    <?php else: ?>
                                                        <span><?= format_rp($row['price_hot']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($row['temp_type'] === 'cold_only'): ?>
                                                <div>
                                                    <strong>Cold (C):</strong>
                                                    <?php $p_c = $row['price_cold'] ?? $row['price']; ?>
                                                    <?php if ($is_member && $p_c > 0): ?>
                                                        <del class="text-muted"><?= format_rp($p_c) ?></del>
                                                        <strong class="text-success ms-1"><?= format_rp($p_c * 0.9) ?></strong>
                                                    <?php else: ?>
                                                        <span><?= format_rp($p_c) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php elseif ($row['temp_type'] === 'hot_only'): ?>
                                                <div>
                                                    <strong>Hot (H):</strong>
                                                    <?php $p_h = $row['price_hot'] ?? $row['price']; ?>
                                                    <?php if ($is_member && $p_h > 0): ?>
                                                        <del class="text-muted"><?= format_rp($p_h) ?></del>
                                                        <strong class="text-success ms-1"><?= format_rp($p_h * 0.9) ?></strong>
                                                    <?php else: ?>
                                                        <span><?= format_rp($p_h) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div>
                                                    <?php if ($is_member && $row['price'] > 0): ?>
                                                        <del class="text-muted"><?= format_rp($row['price']) ?></del>
                                                        <strong class="text-success ms-1"><?= format_rp($row['price'] * 0.9) ?></strong>
                                                    <?php else: ?>
                                                        <strong><?= format_rp($row['price']) ?></strong>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['temp_type'] === 'both'): ?>
                                                <div class="mb-2">
                                                    <label for="temp_<?= $pid ?>" class="form-label small mb-1">Temperature</label>
                                                    <select id="temp_<?= $pid ?>" name="temp[<?= $pid ?>]" class="form-select form-select-sm">
                                                        <option value="cold">Cold (C) - <?= format_rp($is_member ? $row['price_cold'] * 0.9 : $row['price_cold']) ?></option>
                                                        <option value="hot">Hot (H) - <?= format_rp($is_member ? $row['price_hot'] * 0.9 : $row['price_hot']) ?></option>
                                                    </select>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($has_flavors_frappe): ?>
                                                <div class="mb-2">
                                                    <label for="flavor_<?= $pid ?>" class="form-label small mb-1">Flavor</label>
                                                    <select id="flavor_<?= $pid ?>" name="flavor[<?= $pid ?>]" class="form-select form-select-sm">
                                                        <option value="Matcha">Matcha</option>
                                                        <option value="Vanilla">Vanilla</option>
                                                        <option value="Chocolate">Chocolate</option>
                                                        <option value="Caramel">Caramel</option>
                                                    </select>
                                                </div>
                                            <?php elseif ($has_flavors_icecream): ?>
                                                <div class="mb-2">
                                                    <label for="flavor_<?= $pid ?>" class="form-label small mb-1">Flavor</label>
                                                    <select id="flavor_<?= $pid ?>" name="flavor[<?= $pid ?>]" class="form-select form-select-sm">
                                                        <option value="Matcha">Matcha</option>
                                                        <option value="Chocolate">Chocolate</option>
                                                        <option value="Vanilla">Vanilla</option>
                                                        <option value="Strawberry">Strawberry</option>
                                                        <option value="Ube">Ube</option>
                                                        <option value="Durian">Durian</option>
                                                    </select>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <label for="qty_<?= $pid ?>" class="form-label small mb-1">Quantity</label>
                                                <input type="number" id="qty_<?= $pid ?>" name="qty[<?= $pid ?>]" value="0" min="0" max="99" class="form-control form-control-sm">
                                            </div>
                                        </td>
                                        <?php if (is_admin()): ?>
                                            <td class="text-center">
                                                <div class="d-flex flex-column gap-2">
                                                    <a href="edit_product.php?id=<?= $pid ?>" class="btn btn-primary btn-sm">Edit / Image</a>
                                                    <a href="delete_product.php?id=<?= $pid ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['name'])) ?> from the menu?');">Delete</a>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-2 mt-3 pt-3 border-top">
                            <div class="text-muted small">
                                Set quantities above for as many items as you want, then add them to your order cart or go straight to checkout.
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" name="go_checkout" value="0" class="btn btn-secondary">
                                    Add Selected Items to Cart
                                </button>
                                <button type="submit" name="go_checkout" value="1" class="btn btn-primary">
                                    Add Selected &amp; Go to Checkout
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>

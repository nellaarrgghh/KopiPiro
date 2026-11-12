<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$cart_count = function_exists('cart_item_count') ? cart_item_count() : 0;
?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<header>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark py-2" aria-label="Primary navigation">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">Kopipiro</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#kopipiroNav" aria-controls="kopipiroNav" aria-expanded="false" aria-label="Toggle navigation menu">
                <span class="navbar-toggler-icon me-1" aria-hidden="true"></span>
                <span>Menu</span>
            </button>
            <div class="collapse navbar-collapse" id="kopipiroNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'index.php' ? 'active fw-semibold' : '' ?>" href="index.php">Explore Menu</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $current_page === 'order.php' ? 'active fw-semibold' : '' ?>" href="order.php">
                            Order &amp; Checkout
                            <?php if ($cart_count > 0): ?>
                                <span class="badge bg-primary ms-1"><?= $cart_count ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php if (function_exists('is_admin') && is_admin()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'create_product.php' ? 'active fw-semibold' : '' ?>" href="create_product.php">Add Product</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'orders.php' ? 'active fw-semibold' : '' ?>" href="orders.php">All Orders &amp; Sales</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'users.php' ? 'active fw-semibold' : '' ?>" href="users.php">Users</a>
                        </li>
                    <?php elseif (function_exists('is_cashier') && is_cashier()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'orders.php' ? 'active fw-semibold' : '' ?>" href="orders.php">Assigned Orders</a>
                        </li>
                    <?php elseif (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'my_orders.php' ? 'active fw-semibold' : '' ?>" href="my_orders.php">My Orders</a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <span class="text-white small px-2 py-1">
                            <?= htmlspecialchars($_SESSION['username']) ?>
                            <?php
                            $role_label = $_SESSION['role'] ?? 'customer';
                            if ($role_label === 'admin' || $role_label === 'owner') {
                                $role_display = 'Owner (Admin)';
                            } elseif ($role_label === 'cashier') {
                                $role_display = 'Cashier';
                            } else {
                                $role_display = 'Customer (-10%)';
                            }
                            ?>
                            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($role_display) ?></span>
                        </span>
                        <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
                    <?php else: ?>
                        <span class="text-white small px-1">Guest (Normal Price)</span>
                        <a href="login.php" class="btn btn-outline-light btn-sm">Log In</a>
                        <a href="register.php" class="btn btn-primary btn-sm">Register (10% Off)</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
</header>

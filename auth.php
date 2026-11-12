<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    $role = $_SESSION['role'] ?? '';
    return ($role === 'admin' || $role === 'owner');
}

function is_cashier() {
    return ($_SESSION['role'] ?? '') === 'cashier';
}

function is_customer() {
    return ($_SESSION['role'] ?? '') === 'customer';
}

function has_member_discount() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die("403 - Access denied. This page is for the Kopipiro Owner (Admin) only.");
    }
}

function require_staff_or_admin() {
    require_login();
    if (!is_admin() && !is_cashier()) {
        http_response_code(403);
        die("403 - Access denied. This page is for Cashiers and the Owner only.");
    }
}

function cart_item_count() {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return 0;
    }
    $count = 0;
    foreach ($_SESSION['cart'] as $item) {
        $count += (int)($item['qty'] ?? 0);
    }
    return $count;
}
?>

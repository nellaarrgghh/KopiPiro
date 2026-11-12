<?php
require_once __DIR__ . '/../helpers.php';
$method = $_SERVER['REQUEST_METHOD'];

// POST - create order (guest or logged-in customer)
if ($method === 'POST') {
    $user  = me();
    $b     = body();
    $items = $b['items'] ?? [];
    if (!is_array($items) || !count($items)) {
        out(['message' => 'Cart is empty'], 422);
    }

    // 1. Clean quantities; merge duplicate product IDs
    $wanted = [];
    $variants = [];
    foreach ($items as $it) {
        $pid = (int)($it['product_id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        $var = trim($it['variant'] ?? '');
        if ($pid > 0 && $qty > 0) {
            $wanted[$pid] = ($wanted[$pid] ?? 0) + min($qty, 99);
            if ($var !== '') {
                $variants[$pid] = $var;
            }
        }
    }
    if (!$wanted) {
        out(['message' => 'No valid items'], 422);
    }

    // 2. Read real prices from the database (never trust the client)
    $ids  = array_keys($wanted);
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, category, price, price_cold, price_hot FROM product WHERE id IN ($in) AND is_active = 1");
    $stmt->execute($ids);
    $dbProducts = $stmt->fetchAll(PDO::FETCH_UNIQUE);
    if (count($dbProducts) !== count($ids)) {
        out(['message' => 'Some products are unavailable'], 422);
    }

    // 3. Calculate totals and member discount on the server
    $is_member = $user && ($user['role'] === 'customer' || isset($_SESSION['member_id']));
    $subtotal = 0;
    $lines    = [];
    foreach ($wanted as $pid => $qty) {
        $prod = $dbProducts[$pid];
        $var  = $variants[$pid] ?? '';
        $unit = (int)$prod['price'];
        if (stripos($var, 'Cold') !== false && $prod['price_cold'] !== null) {
            $unit = (int)$prod['price_cold'];
        } elseif (stripos($var, 'Hot') !== false && $prod['price_hot'] !== null) {
            $unit = (int)$prod['price_hot'];
        }
        $disc_amt   = $is_member ? round($unit * DISCOUNT_RATE, 2) : 0;
        $final_unit = $unit - $disc_amt;
        $line_tot   = $final_unit * $qty;
        $subtotal  += $unit * $qty;
        $lines[] = [
            'product_id'       => $pid,
            'product_name'     => str_ireplace('Frapppe', 'Frappe', $prod['name']),
            'category'         => $prod['category'],
            'variant'          => $var,
            'qty'              => $qty,
            'unit_price'       => $unit,
            'discount_amount'  => $disc_amt,
            'final_unit_price' => $final_unit,
            'line_total'       => $line_tot,
        ];
    }
    $discount = $is_member ? (int)round($subtotal * DISCOUNT_RATE) : 0;
    $total    = $subtotal - $discount;

    // 4. Pick the cashier with the fewest unfinished orders (or requested cashier)
    $cashierId = !empty($b['cashier_id']) ? (int)$b['cashier_id'] : null;
    if (!$cashierId) {
        $cashierId = $pdo->query(
            "SELECT u.id FROM users u
             LEFT JOIN orders o ON o.cashier_id = u.id AND o.status <> 'done'
             WHERE u.role = 'cashier'
             GROUP BY u.id ORDER BY COUNT(o.id) ASC, u.id ASC LIMIT 1"
        )->fetchColumn() ?: null;
    }

    $customerName = trim($b['customer_name'] ?? ($user['name'] ?? 'Guest'));
    if ($customerName === '') {
        $customerName = 'Guest';
    }
    $code = 'KP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $memberId = isset($_SESSION['member_id']) ? (int)$_SESSION['member_id'] : null;

    // 5. Save header + items inside a transaction
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "INSERT INTO orders (order_code, user_id, member_id, customer_name, is_member, cashier_id, status, subtotal, discount, total)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
    );
    $stmt->execute([$code, $user['id'] ?? null, $memberId, $customerName, $is_member ? 1 : 0, $cashierId, $subtotal, $discount, $total]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        "INSERT INTO order_items (order_id, product_id, product_name, category, variant, qty, unit_price, discount_amount, final_unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($lines as $ln) {
        $itemStmt->execute([
            $orderId,
            $ln['product_id'],
            $ln['product_name'],
            $ln['category'],
            $ln['variant'],
            $ln['qty'],
            $ln['unit_price'],
            $ln['discount_amount'],
            $ln['final_unit_price'],
            $ln['line_total']
        ]);
    }
    $pdo->commit();

    out([
        'id'         => $orderId,
        'order_code' => $code,
        'subtotal'   => $subtotal,
        'discount'   => $discount,
        'total'      => $total,
        'cashier_id' => $cashierId ? (int)$cashierId : null
    ], 201);
}

// GET - list orders, filtered by role
if ($method === 'GET') {
    $u = need_role(['owner', 'cashier', 'customer']);
    $where  = '1=1';
    $params = [];
    if ($u['role'] === 'cashier') {
        $where  = 'o.cashier_id = ?';
        $params = [$u['id']];
    } elseif ($u['role'] === 'customer') {
        $where  = 'o.user_id = ?';
        $params = [$u['id']];
    }

    $stmt = $pdo->prepare(
        "SELECT o.*, COALESCE(NULLIF(c.name, ''), c.username) AS cashier_name
         FROM orders o LEFT JOIN users c ON c.id = o.cashier_id
         WHERE $where ORDER BY o.id DESC LIMIT 200"
    );
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    if ($orders) {
        $ids = array_column($orders, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $it  = $pdo->prepare(
            "SELECT oi.*, COALESCE(p.name, oi.product_name) AS name
             FROM order_items oi
             LEFT JOIN product p ON p.id = oi.product_id
             WHERE oi.order_id IN ($in)"
        );
        $it->execute($ids);
        $byOrder = [];
        foreach ($it->fetchAll() as $row) {
            $byOrder[$row['order_id']][] = $row;
        }
        foreach ($orders as &$o) {
            $o['items'] = $byOrder[$o['id']] ?? [];
        }
    }
    out(['orders' => $orders]);
}

// PUT ?id=123 - cashier or owner updates order status
if ($method === 'PUT') {
    $u      = need_role(['owner', 'cashier']);
    $id     = (int)($_GET['id'] ?? 0);
    $status = body()['status'] ?? '';
    if (!in_array($status, ['pending', 'paid', 'done', 'Completed', 'Cancelled'], true)) {
        out(['message' => 'Invalid status'], 422);
    }

    $sql = "UPDATE orders SET status = ? WHERE id = ?";
    $params = [$status, $id];
    if ($u['role'] === 'cashier') {
        $sql .= " AND cashier_id = ?";
        $params[] = $u['id'];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->rowCount()) {
        out(['message' => 'Order not found or not yours'], 404);
    }
    out(['message' => 'Status updated']);
}

out(['message' => 'Method not allowed'], 405);
?>

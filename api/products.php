<?php
require_once __DIR__ . '/../helpers.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = me();
    $cats = $pdo->query("SELECT id, name FROM categories ORDER BY id")->fetchAll();
    $sql = "SELECT p.*, c.name AS category_name,
                   ROUND(AVG(r.rating), 1) AS rating_avg, COUNT(r.id) AS reviews
            FROM product p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN reviews r ON r.product_id = p.id
            WHERE p.is_active = 1
            GROUP BY p.id
            ORDER BY p.id";
    $rows = $pdo->query($sql)->fetchAll();

    foreach ($rows as &$p) {
        $p['id']          = (int)$p['id'];
        $p['category_id'] = (int)$p['category_id'];
        $p['price']       = (int)$p['price'];
        $p['price_cold']  = $p['price_cold'] !== null ? (int)$p['price_cold'] : null;
        $p['price_hot']   = $p['price_hot'] !== null ? (int)$p['price_hot'] : null;
        $p['final_price'] = final_price($p['price'], $user);
        $p['final_cold']  = $p['price_cold'] !== null ? final_price($p['price_cold'], $user) : null;
        $p['final_hot']   = $p['price_hot'] !== null ? final_price($p['price_hot'], $user) : null;
        $img_source       = !empty($p['image']) ? $p['image'] : $p['url_image'];
        $p['image']       = image_url($img_source);
        $p['rating_text'] = $p['rating'];
        $p['rating']      = $p['rating_avg'] !== null ? (float)$p['rating_avg'] : 5.0;
        $p['reviews']     = (int)$p['reviews'];
    }
    out([
        'categories'    => $cats,
        'products'      => $rows,
        'discount_rate' => $user && ($user['role'] === 'customer' || isset($_SESSION['member_id'])) ? DISCOUNT_RATE : 0
    ]);
}

// POST (owner only) - add product with optional image upload (multipart/form-data or JSON)
if ($method === 'POST') {
    need_role(['owner']);
    $input = !empty($_POST) ? $_POST : body();
    $name  = trim($input['name'] ?? '');
    $catId = (int)($input['category_id'] ?? 0);
    $catName = trim($input['category'] ?? '');
    $price = (int)($input['price'] ?? 0);
    $desc  = trim($input['description'] ?? '');

    if ($catId <= 0 && $catName !== '') {
        $stmt_c = $pdo->prepare("SELECT id, name FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt_c->execute([$catName]);
        $found_c = $stmt_c->fetch();
        if ($found_c) {
            $catId   = (int)$found_c['id'];
            $catName = $found_c['name'];
        }
    } elseif ($catId > 0 && $catName === '') {
        $stmt_c = $pdo->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
        $stmt_c->execute([$catId]);
        $found_c = $stmt_c->fetch();
        if ($found_c) {
            $catName = $found_c['name'];
        }
    }
    if ($catName === '') {
        $catName = 'Coffee';
    }

    if (!$name || $catId <= 0 || $price <= 0) {
        out(['message' => 'Name, category, and positive price required'], 422);
    }

    $filename = null;
    $url_img  = 'assets/Americano.jpg';
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            out(['message' => 'Upload error'], 400);
        }
        if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
            out(['message' => 'Max 2 MB'], 400);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['image']['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            out(['message' => 'JPG, PNG, or WEBP only'], 400);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        $dir = __DIR__ . '/../uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$filename");
        $url_img = 'uploads/' . $filename;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO product (category_id, name, category, temp_type, price, price_cold, price_hot, description, rating, url_image, image, is_active)
         VALUES (?, ?, ?, 'both', ?, ?, ?, ?, '5.0 / 5 (New)', ?, ?, 1)"
    );
    $stmt->execute([$catId, $name, $catName, $price, $price, $price, $desc, $url_img, $filename ?? $url_img]);
    out(['id' => (int)$pdo->lastInsertId(), 'image' => image_url($filename ?? $url_img)], 201);
}

// PUT ?id=123 (owner only) - update price
if ($method === 'PUT') {
    need_role(['owner']);
    $id    = (int)($_GET['id'] ?? 0);
    $b     = body();
    $price = (int)($b['price'] ?? 0);
    if ($id <= 0 || $price <= 0) {
        out(['message' => 'Valid id and price required'], 422);
    }
    $pdo->prepare("UPDATE product SET price = ?, price_cold = ?, price_hot = ? WHERE id = ?")->execute([$price, $price, $price, $id]);
    out(['message' => 'Price updated']);
}

out(['message' => 'Method not allowed'], 405);
?>

<?php
include 'auth.php';
include 'config.php';

require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM product WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$product) {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? 'Coffee');
    $temp_type   = trim($_POST['temp_type'] ?? 'none');
    $price_cold  = isset($_POST['price_cold']) && $_POST['price_cold'] !== '' ? max(0, (int)$_POST['price_cold']) : 0;
    $price_hot   = isset($_POST['price_hot']) && $_POST['price_hot'] !== '' ? max(0, (int)$_POST['price_hot']) : null;
    $description = trim($_POST['description'] ?? '');
    $rating      = trim($_POST['rating'] ?? '');
    $url_image   = trim($_POST['url_image'] ?? '');

    if ($name === '') {
        $error = "Product name is required.";
    } else {
        $uploaded_file_name = null;
        if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                $error = "Upload error occurred while receiving the image file.";
            } elseif ($_FILES['image_file']['size'] > 2 * 1024 * 1024) {
                http_response_code(400);
                $error = "Image file size exceeds the 2 MB maximum limit.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES['image_file']['tmp_name']);
                $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed_mimes[$mime])) {
                    http_response_code(400);
                    $error = "Invalid file MIME type (" . htmlspecialchars((string)$mime) . "). Only real JPG, PNG, or WEBP images are allowed.";
                } else {
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $uploaded_file_name = bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$mime];
                    if (move_uploaded_file($_FILES['image_file']['tmp_name'], $upload_dir . $uploaded_file_name)) {
                        $url_image = 'uploads/' . $uploaded_file_name;
                    } else {
                        $error = "Failed to save uploaded file.";
                    }
                }
            }
        }

        if ($error === '') {
            $base_price = $price_cold;
            $c_val = ($temp_type === 'both' || $temp_type === 'cold_only') ? $price_cold : null;
            $h_val = ($temp_type === 'both' || $temp_type === 'hot_only') ? ($price_hot ?? $price_cold) : null;
            if ($temp_type === 'hot_only' && $h_val !== null) {
                $base_price = $h_val;
            }

            $cat_id = 1;
            $stmt_cat = mysqli_prepare($conn, "SELECT id FROM categories WHERE name = ? LIMIT 1");
            if ($stmt_cat) {
                mysqli_stmt_bind_param($stmt_cat, "s", $category);
                mysqli_stmt_execute($stmt_cat);
                $cat_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cat));
                if ($cat_row) {
                    $cat_id = (int)$cat_row['id'];
                }
            }
            $img_col = $uploaded_file_name ?? $url_image;

            $up = mysqli_prepare($conn, "UPDATE product SET category_id = ?, name = ?, category = ?, temp_type = ?, price = ?, price_cold = ?, price_hot = ?, description = ?, rating = ?, url_image = ?, image = ? WHERE id = ?");
            mysqli_stmt_bind_param($up, "isssiiissssi", $cat_id, $name, $category, $temp_type, $base_price, $c_val, $h_val, $description, $rating, $url_image, $img_col, $id);
            mysqli_stmt_execute($up);

            header("Location: index.php?msg=updated");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - Kopipiro Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h1 class="h5 mb-0">Edit Product: <?= htmlspecialchars($product['name']) ?></h1>
                        <a href="index.php" class="btn btn-light btn-sm text-dark">Back to Menu</a>
                    </div>
                    <div class="card-body">
                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger" role="alert">
                                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="edit_product.php?id=<?= $id ?>" enctype="multipart/form-data">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <div class="row g-3">
                                <div class="col-12 col-md-7">
                                    <label for="name" class="form-label fw-semibold">Product Name</label>
                                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label for="category" class="form-label fw-semibold">Category</label>
                                    <select class="form-select" id="category" name="category" required>
                                        <?php
                                        $cats = ['Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots'];
                                        foreach ($cats as $c):
                                        ?>
                                            <option value="<?= htmlspecialchars($c) ?>" <?= $product['category'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label for="temp_type" class="form-label fw-semibold">Temperature Option</label>
                                    <select class="form-select" id="temp_type" name="temp_type">
                                        <option value="both" <?= $product['temp_type'] === 'both' ? 'selected' : '' ?>>Cold (C) &amp; Hot (H)</option>
                                        <option value="cold_only" <?= $product['temp_type'] === 'cold_only' ? 'selected' : '' ?>>Cold (C) Only</option>
                                        <option value="hot_only" <?= $product['temp_type'] === 'hot_only' ? 'selected' : '' ?>>Hot (H) Only</option>
                                        <option value="none" <?= $product['temp_type'] === 'none' ? 'selected' : '' ?>>Standard (Toast / Dessert / Add-on)</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="price_cold" class="form-label fw-semibold">Standard / Cold Price (Rp)</label>
                                    <input type="number" class="form-control" id="price_cold" name="price_cold" value="<?= (int)($product['price_cold'] ?? $product['price']) ?>" min="0" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="price_hot" class="form-label fw-semibold">Hot Price (Rp)</label>
                                    <input type="number" class="form-control" id="price_hot" name="price_hot" value="<?= $product['price_hot'] !== null ? (int)$product['price_hot'] : '' ?>" min="0" placeholder="Optional">
                                </div>

                                <div class="col-12 col-md-8">
                                    <label for="description" class="form-label fw-semibold">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars((string)$product['description']) ?></textarea>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="rating" class="form-label fw-semibold">Rating</label>
                                    <input type="text" class="form-control" id="rating" name="rating" value="<?= htmlspecialchars((string)$product['rating']) ?>">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="url_image" class="form-label fw-semibold">Image URL (paste image link here)</label>
                                    <input type="text" class="form-control" id="url_image" name="url_image" value="<?= htmlspecialchars((string)$product['url_image']) ?>" placeholder="Leave blank or paste image URL">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="image_file" class="form-label fw-semibold">Or Upload Image File</label>
                                    <input type="file" class="form-control" id="image_file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif">
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" name="update_product" class="btn btn-primary">Save Product Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>


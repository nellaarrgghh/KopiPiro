<?php
include 'auth.php';
include 'config.php';

require_admin();

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
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
                        $error = "Failed to save uploaded file. Check folder permissions or paste an image URL instead.";
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

            $stmt = mysqli_prepare($conn, "INSERT INTO product (category_id, name, category, temp_type, price, price_cold, price_hot, description, rating, url_image, image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            mysqli_stmt_bind_param($stmt, "isssiiissss", $cat_id, $name, $category, $temp_type, $base_price, $c_val, $h_val, $description, $rating, $url_image, $img_col);
            mysqli_stmt_execute($stmt);

            header("Location: index.php?msg=created");
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
    <title>Add Product - Kopipiro Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h1 class="h5 mb-0">Add New Kopipiro Product</h1>
                        <a href="index.php" class="btn btn-light btn-sm text-dark">Back to Menu</a>
                    </div>
                    <div class="card-body">
                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger" role="alert">
                                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="create_product.php" enctype="multipart/form-data">
                            <div class="row g-3">
                                <div class="col-12 col-md-7">
                                    <label for="name" class="form-label fw-semibold">Product Name</label>
                                    <input type="text" class="form-control" id="name" name="name" placeholder="e.g. Caramel Macchiato" required>
                                </div>
                                <div class="col-12 col-md-5">
                                    <label for="category" class="form-label fw-semibold">Category</label>
                                    <select class="form-select" id="category" name="category" required>
                                        <option value="Coffee">Coffee</option>
                                        <option value="Non-Coffee">Non-Coffee</option>
                                        <option value="Toast">Toast</option>
                                        <option value="Dessert">Dessert</option>
                                        <option value="Add-ons: Milk">Add-ons: Milk</option>
                                        <option value="Add-ons: Syrup">Add-ons: Syrup</option>
                                        <option value="Add-ons: Shots">Add-ons: Shots</option>
                                    </select>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label for="temp_type" class="form-label fw-semibold">Temperature Option</label>
                                    <select class="form-select" id="temp_type" name="temp_type">
                                        <option value="both">Cold (C) &amp; Hot (H)</option>
                                        <option value="cold_only">Cold (C) Only</option>
                                        <option value="hot_only">Hot (H) Only</option>
                                        <option value="none">Standard (Toast / Dessert / Add-on)</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="price_cold" class="form-label fw-semibold">Standard / Cold Price (Rp)</label>
                                    <input type="number" class="form-control" id="price_cold" name="price_cold" value="15000" min="0" required>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="price_hot" class="form-label fw-semibold">Hot Price (Rp, if Hot available)</label>
                                    <input type="number" class="form-control" id="price_hot" name="price_hot" value="13000" min="0" placeholder="Leave blank if not Hot">
                                </div>

                                <div class="col-12 col-md-8">
                                    <label for="description" class="form-label fw-semibold">Description (leave blank for Add-ons)</label>
                                    <textarea class="form-control" id="description" name="description" rows="2" placeholder="Describe the taste and ingredients..."></textarea>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label for="rating" class="form-label fw-semibold">Rating (leave blank for Add-ons)</label>
                                    <input type="text" class="form-control" id="rating" name="rating" placeholder="e.g. 10/10">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="url_image" class="form-label fw-semibold">Image URL (optional, can be added later)</label>
                                    <input type="text" class="form-control" id="url_image" name="url_image" value="" placeholder="https://example.com/image.jpg">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="image_file" class="form-label fw-semibold">Or Upload Image File (optional)</label>
                                    <input type="file" class="form-control" id="image_file" name="image_file" accept=".jpg,.jpeg,.png,.webp,.gif">
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" name="save_product" class="btn btn-success">Save Product to Menu</button>
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


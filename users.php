<?php
include 'auth.php';
include 'config.php';

require_admin();

$status_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'customer';

    if ($username === '' || strlen($password) < 4) {
        $error_msg = "Username is required and password must be at least 4 characters.";
    } elseif (!in_array($role, ['admin', 'cashier', 'customer'], true)) {
        $error_msg = "Invalid role selected.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sss", $username, $hash, $role);
        if (mysqli_stmt_execute($stmt)) {
            $new_uid = (int)mysqli_insert_id($conn);
            if ($role === 'customer') {
                $stmt_m = mysqli_prepare($conn, "INSERT IGNORE INTO members (user_id, username, password, discount_rate) VALUES (?, ?, ?, 0.10)");
                mysqli_stmt_bind_param($stmt_m, "iss", $new_uid, $username, $hash);
                mysqli_stmt_execute($stmt_m);
            }
            $status_msg = "User '$username' added with role '$role'.";
        } else {
            $error_msg = "Username '$username' is already registered.";
        }
    }
}

$users = mysqli_query($conn, "SELECT id, username, role, created_at FROM users ORDER BY FIELD(role, 'admin', 'owner', 'cashier', 'customer'), id ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Kopipiro Owner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Kopipiro User &amp; Staff Management</h1>
            <a href="index.php" class="btn btn-secondary">Back to Menu</a>
        </div>

        <?php if ($status_msg !== ''): ?>
            <div class="alert alert-success" role="status">
                <strong>Success:</strong> <?= htmlspecialchars($status_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Error:</strong> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-md-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h2 class="h5 mb-0">Add New User Account</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="users.php">
                            <div class="mb-3">
                                <label for="username" class="form-label fw-semibold">Username</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="e.g. cashier3" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label fw-semibold">Password</label>
                                <input type="password" class="form-control" id="password" name="password" minlength="4" placeholder="Minimum 4 characters" required>
                            </div>
                            <div class="mb-4">
                                <label for="role" class="form-label fw-semibold">Role</label>
                                <select class="form-select" id="role" name="role">
                                    <option value="customer">Customer (10% Member Discount)</option>
                                    <option value="cashier">Cashier (View Assigned Orders)</option>
                                    <option value="admin">Owner / Admin (Full Store Access)</option>
                                </select>
                            </div>
                            <div class="d-grid">
                                <button type="submit" name="add_user" value="1" class="btn btn-success">Save User Account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h2 class="h5 mb-0">Registered Accounts (Owner, Cashiers, Customers)</h2>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped align-middle mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th scope="col">No</th>
                                        <th scope="col">Username</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Permissions / Perks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; while ($u = mysqli_fetch_assoc($users)): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($u['username']) ?></strong>
                                            <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
                                                <span class="badge bg-primary ms-1">Current Session</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($u['role'] === 'admin' || $u['role'] === 'owner'): ?>
                                                <span class="badge bg-danger">Owner / Admin</span>
                                            <?php elseif ($u['role'] === 'cashier'): ?>
                                                <span class="badge bg-primary">Cashier</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Customer</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?php if ($u['role'] === 'admin' || $u['role'] === 'owner'): ?>
                                                Manage products, images, prices, all orders, sales charts, Excel export
                                            <?php elseif ($u['role'] === 'cashier'): ?>
                                                View and manage only orders assigned to <?= htmlspecialchars($u['username']) ?>
                                            <?php else: ?>
                                                10% automatic member discount on orders
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>
</body>
</html>


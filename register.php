<?php
include 'auth.php';
include 'config.php';

if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $role     = 'customer';

    if ($username === '' || strlen($username) < 3) {
        $error = "Username must be at least 3 characters.";
    } elseif (strlen($password) < 4) {
        $error = "Password must be at least 4 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $email = strtolower($username) . '@kopipiro.test';
        $stmt = mysqli_prepare($conn, "INSERT INTO users (username, name, email, password, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssssss", $username, $username, $email, $hash, $hash, $role);
        if (mysqli_stmt_execute($stmt)) {
            $new_id = (int)mysqli_insert_id($conn);

            $stmt_m = mysqli_prepare($conn, "INSERT INTO members (user_id, username, password, discount_rate) VALUES (?, ?, ?, 0.10)");
            mysqli_stmt_bind_param($stmt_m, "iss", $new_id, $username, $hash);
            mysqli_stmt_execute($stmt_m);
            $member_id = (int)mysqli_insert_id($conn);

            session_regenerate_id(true);
            $_SESSION['user_id']   = $new_id;
            $_SESSION['member_id'] = $member_id;
            $_SESSION['username']  = $username;
            $_SESSION['role']      = 'customer';
            $_SESSION['member']    = $username;
            $_SESSION['user']      = [
                'id'       => $new_id,
                'name'     => $username,
                'username' => $username,
                'email'    => $email,
                'role'     => 'customer'
            ];
            header("Location: index.php");
            exit;
        } else {
            $error = "That username is already registered. Please choose a different username or log in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Customer Account - Kopipiro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main id="main-content" class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h1 class="h5 mb-0">Register Kopipiro Customer Account</h1>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Register and log in to receive a <strong>10% discount</strong> on your Kopipiro orders.
                        </p>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger" role="alert">
                                <strong>Registration Error:</strong> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="register.php">
                            <input type="hidden" id="role" name="role" value="customer">
                            <div class="mb-3">
                                <label for="username" class="form-label fw-semibold">Choose Username</label>
                                <input type="text" class="form-control" id="username" name="username" placeholder="Minimum 3 characters" minlength="3" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label fw-semibold">Create Password</label>
                                <input type="password" class="form-control" id="password" name="password" placeholder="Minimum 4 characters" minlength="4" required>
                            </div>
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label fw-semibold">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" minlength="4" required>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" name="register" value="1" class="btn btn-primary">Register &amp; Activate 10% Discount</button>
                                <a href="login.php" class="btn btn-secondary">Already Registered? Log In</a>
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

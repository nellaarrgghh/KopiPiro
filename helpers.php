<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = json_decode((string)$raw, true);
        if (!is_array($b)) {
            $b = !empty($_POST) ? $_POST : [];
        }
    }
    return $b;
}

function me(): ?array {
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    if (isset($_SESSION['user_id'])) {
        $role = $_SESSION['role'] ?? 'customer';
        if ($role === 'admin') {
            $role = 'owner';
        }
        return [
            'id'       => (int)$_SESSION['user_id'],
            'name'     => $_SESSION['username'] ?? 'User',
            'username' => $_SESSION['username'] ?? 'User',
            'role'     => $role
        ];
    }
    return null;
}

function need_role(array $roles): array {
    $u = me();
    if (!$u) {
        out(['message' => 'Please log in'], 401);
    }
    $normalized_role = ($u['role'] === 'admin') ? 'owner' : $u['role'];
    $allowed = $roles;
    if (in_array('owner', $allowed, true) && !in_array('admin', $allowed, true)) {
        $allowed[] = 'admin';
    }
    if (!in_array($u['role'], $allowed, true) && !in_array($normalized_role, $allowed, true)) {
        out(['message' => 'Forbidden'], 403);
    }
    $u['role'] = $normalized_role;
    return $u;
}

// The 10% discount rule lives on the server
function final_price(int $price, ?array $user): int {
    $member = $user && ($user['role'] === 'customer' || isset($_SESSION['member_id']));
    return $member ? (int)round($price * (1 - DISCOUNT_RATE)) : $price;
}

function image_url(?string $img): ?string {
    if (!$img) {
        return null;
    }
    if (preg_match('#^(https?://|assets/|uploads/)#', $img)) {
        return $img;
    }
    return 'uploads/' . $img;
}
?>


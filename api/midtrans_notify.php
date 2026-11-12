<?php
require_once __DIR__ . '/../helpers.php';
$b = body();

// Verify the SHA-512 signature from Midtrans
$expected = hash(
    'sha512',
    ($b['order_id'] ?? '') . ($b['status_code'] ?? '') . ($b['gross_amount'] ?? '') . MIDTRANS_SERVER_KEY
);
if (!hash_equals($expected, $b['signature_key'] ?? '')) {
    out(['message' => 'Invalid signature'], 403);
}

$tx = $b['transaction_status'] ?? '';
if (in_array($tx, ['settlement', 'capture'], true)) {
    $pdo->prepare("UPDATE orders SET status = 'paid' WHERE order_code = ?")
        ->execute([$b['order_id']]);
}
out(['message' => 'OK']);
?>

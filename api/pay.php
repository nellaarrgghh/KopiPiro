<?php
require_once __DIR__ . '/../helpers.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['message' => 'POST only'], 405);
}

$code = trim(body()['order_code'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
$stmt->execute([$code]);
$o = $stmt->fetch();
if (!$o) {
    out(['message' => 'Order not found'], 404);
}

// If the instructor/student has not pasted a live Midtrans Sandbox key yet,
// provide a local interactive sandbox simulator URL so the payment flow is testable immediately.
if (MIDTRANS_SERVER_KEY === 'PASTE-YOUR-SANDBOX-SERVER-KEY' || MIDTRANS_SERVER_KEY === '') {
    out([
        'token'        => 'sim-' . bin2hex(random_bytes(8)),
        'redirect_url' => 'sandbox_pay.php?order_code=' . urlencode($o['order_code']),
        'mode'         => 'local_sandbox'
    ]);
}

$payload = json_encode([
    'transaction_details' => [
        'order_id'     => $o['order_code'],
        'gross_amount' => (int)$o['total'],
    ],
    'customer_details' => [
        'first_name' => $o['customer_name'],
    ],
]);

$ch = curl_init('https://app.sandbox.midtrans.com/snap/v1/transactions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(MIDTRANS_SERVER_KEY . ':'),
    ],
]);
$res  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode((string)$res, true) ?: ['message' => 'Payment gateway error'];
out($data, $http ?: 502);
?>

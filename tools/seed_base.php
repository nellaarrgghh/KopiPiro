<?php
require_once __DIR__ . '/../config.php';

$cats = ['Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots', 'Food'];
$stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
foreach ($cats as $c) {
    $stmt->execute([$c]);
}

$hash = password_hash('password123', PASSWORD_DEFAULT);
$users = [
    ['owner',    'Kopipiro Owner', 'owner@kopipiro.test',    'owner'],
    ['cashier1', 'Cashier Ani',    'cashier1@kopipiro.test', 'cashier'],
    ['cashier2', 'Cashier Budi',   'cashier2@kopipiro.test', 'cashier'],
    ['customer', 'Demo Customer',  'customer@kopipiro.test', 'customer'],
];
$uStmt = $pdo->prepare(
    "INSERT INTO users (username, name, email, password, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), password_hash = VALUES(password_hash)"
);
foreach ($users as $u) {
    $uStmt->execute([$u[0], $u[1], $u[2], $hash, $hash, $u[3]]);
}

echo "Base categories and demo accounts ready (password: password123)\n";
?>

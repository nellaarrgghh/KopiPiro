<?php
require_once __DIR__ . '/../config.php';

function fetch_json(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'KopipiroSeeder/1.0',
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) {
        throw new RuntimeException("Failed to fetch $url (HTTP $code)");
    }
    return json_decode($raw, true) ?? [];
}

$catMap = $pdo->query("SELECT name, id FROM categories")->fetchAll(PDO::FETCH_KEY_PAIR);
$insert = $pdo->prepare(
    "INSERT INTO product (category_id, name, category, temp_type, price, price_cold, price_hot, description, rating, url_image, image, is_active)
     VALUES (?, ?, 'Coffee', 'both', ?, ?, ?, ?, '4.8 / 5 (API)', ?, ?, 0)"
);
$check = $pdo->prepare("SELECT id FROM product WHERE LOWER(name) = LOWER(?) LIMIT 1");

// Fetch hot coffee from SampleAPIs (stored with is_active=0 if not already in menu so the 26 core Kopipiro items stay clean)
$hot = fetch_json('https://api.sampleapis.com/coffee/hot');
$added = 0;
foreach (array_slice($hot, 0, 8) as $item) {
    $title = trim($item['title'] ?? '');
    if (!$title) {
        continue;
    }
    $check->execute([$title]);
    if ($check->fetch()) {
        continue;
    }
    $price = rand(18, 32) * 1000;
    $img   = $item['image'] ?? 'assets/Americano.jpg';
    $desc  = substr($item['description'] ?? 'Freshly brewed coffee.', 0, 240);
    $insert->execute([$catMap['Coffee'], $title, $price, $price, $price, $desc, $img, $img]);
    $added++;
}

echo "Checked SampleAPIs Coffee endpoint ($added new reference items stored).\n";
?>

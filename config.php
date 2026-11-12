<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$db   = "coffee_shop";

if (!defined('DISCOUNT_RATE')) {
    define('DISCOUNT_RATE', 0.10);
}
if (!defined('MIDTRANS_SERVER_KEY')) {
    define('MIDTRANS_SERVER_KEY', getenv('MIDTRANS_SERVER_KEY') ?: 'PASTE-YOUR-SANDBOX-SERVER-KEY');
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($host, $user, $pass);
$pdo_host = $host;
if (!$conn) {
    $conn = @mysqli_connect("127.0.0.1", $user, $pass);
    $pdo_host = "127.0.0.1";
}

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($conn, $db);

try {
    $pdo = new PDO(
        "mysql:host=$pdo_host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]
    );
} catch (PDOException $e) {
    $pdo = new PDO(
        "mysql:unix_socket=/tmp/mysql.sock;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]
    );
}

function format_rp($amount) {
    $num = (float)$amount;
    if ($num == 0) {
        return "Free (Rp 0)";
    }
    if (floor($num) != $num) {
        return "Rp " . number_format($num, 1, ',', '.');
    }
    return "Rp " . number_format($num, 0, ',', '.');
}

function format_product_name($name) {
    $clean = str_ireplace('Frapppe', 'Frappe', (string)$name);
    $pos = strpos($clean, ' (');
    if ($pos !== false) {
        $main = substr($clean, 0, $pos);
        $sub  = substr($clean, $pos + 1);
        return htmlspecialchars($main) . '<br><span class="fw-normal small">' . htmlspecialchars($sub) . '</span>';
    }
    return htmlspecialchars($clean);
}

function fetch_jakarta_weather() {
    if (!function_exists('curl_init')) {
        return ['error' => 'cURL extension is not enabled'];
    }
    if (isset($_SESSION['cached_weather']) && isset($_SESSION['cached_weather_time']) && (time() - $_SESSION['cached_weather_time'] < 600)) {
        return $_SESSION['cached_weather'];
    }
    $url = "https://api.open-meteo.com/v1/forecast?latitude=-6.2&longitude=106.8&current=temperature_2m,wind_speed_10m";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $respon = curl_exec($ch);
    $kode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($respon === false || $kode !== 200) {
        return ['error' => $error ?: "HTTP $kode"];
    }
    $decoded = json_decode((string)$respon, true);
    if (!is_array($decoded) || !isset($decoded['current'])) {
        return ['error' => 'Invalid weather response'];
    }
    $_SESSION['cached_weather'] = $decoded;
    $_SESSION['cached_weather_time'] = time();
    return $decoded;
}

function ensure_kopipiro_schema($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $default_cats = ['Coffee', 'Non-Coffee', 'Toast', 'Dessert', 'Add-ons: Milk', 'Add-ons: Syrup', 'Add-ons: Shots', 'Food'];
    $stmt_cat = mysqli_prepare($conn, "INSERT IGNORE INTO `categories` (`name`) VALUES (?)");
    if ($stmt_cat) {
        foreach ($default_cats as $cat_name) {
            mysqli_stmt_bind_param($stmt_cat, "s", $cat_name);
            mysqli_stmt_execute($stmt_cat);
        }
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(100) NOT NULL DEFAULT '',
        `email` VARCHAR(120) DEFAULT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
        `role` ENUM('admin', 'owner', 'cashier', 'customer') NOT NULL DEFAULT 'customer',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_users_role` (`role`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $u_cols = [];
    $u_res = mysqli_query($conn, "SHOW COLUMNS FROM `users`");
    if ($u_res) {
        while ($c = mysqli_fetch_assoc($u_res)) {
            $u_cols[$c['Field']] = true;
        }
    }
    if (!isset($u_cols['name'])) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `username`");
    }
    if (!isset($u_cols['email'])) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `email` VARCHAR(120) DEFAULT NULL UNIQUE AFTER `name`");
    }
    if (!isset($u_cols['password_hash'])) {
        @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN `password_hash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `password`");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `members` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT DEFAULT NULL UNIQUE,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `discount_rate` DECIMAL(4, 2) NOT NULL DEFAULT 0.10,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `product` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT NOT NULL DEFAULT 1,
        `name` VARCHAR(120) NOT NULL,
        `category` VARCHAR(50) NOT NULL DEFAULT 'Coffee',
        `temp_type` ENUM('both', 'cold_only', 'hot_only', 'none') NOT NULL DEFAULT 'none',
        `price` INT NOT NULL DEFAULT 0,
        `price_cold` INT DEFAULT NULL,
        `price_hot` INT DEFAULT NULL,
        `description` TEXT DEFAULT NULL,
        `rating` VARCHAR(60) DEFAULT NULL,
        `url_image` VARCHAR(255) NOT NULL DEFAULT '',
        `image` VARCHAR(255) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_product_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $p_cols = [];
    $p_res = mysqli_query($conn, "SHOW COLUMNS FROM `product`");
    if ($p_res) {
        while ($c = mysqli_fetch_assoc($p_res)) {
            $p_cols[$c['Field']] = true;
        }
    }
    if (!isset($p_cols['category_id'])) {
        @mysqli_query($conn, "ALTER TABLE `product` ADD COLUMN `category_id` INT NOT NULL DEFAULT 1 AFTER `id`");
    }
    if (!isset($p_cols['image'])) {
        @mysqli_query($conn, "ALTER TABLE `product` ADD COLUMN `image` VARCHAR(255) DEFAULT NULL AFTER `url_image`");
    }
    if (!isset($p_cols['is_active'])) {
        @mysqli_query($conn, "ALTER TABLE `product` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `image`");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `reviews` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `rating` TINYINT NOT NULL,
        `comment` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `product`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_code` VARCHAR(30) DEFAULT NULL UNIQUE,
        `user_id` INT DEFAULT NULL,
        `member_id` INT DEFAULT NULL,
        `customer_name` VARCHAR(100) NOT NULL,
        `is_member` TINYINT(1) NOT NULL DEFAULT 0,
        `cashier_id` INT DEFAULT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'paid',
        `subtotal` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `discount` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `total` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_orders_status_date` (`status`, `created_at`),
        CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_orders_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_orders_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ord_cols = [];
    $ord_res = mysqli_query($conn, "SHOW COLUMNS FROM `orders`");
    if ($ord_res) {
        while ($c = mysqli_fetch_assoc($ord_res)) {
            $ord_cols[$c['Field']] = true;
        }
    }
    if (!isset($ord_cols['order_code'])) {
        @mysqli_query($conn, "ALTER TABLE `orders` ADD COLUMN `order_code` VARCHAR(30) DEFAULT NULL UNIQUE AFTER `id`");
    }
    @mysqli_query($conn, "ALTER TABLE `orders` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'paid'");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `order_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `product_id` INT DEFAULT NULL,
        `product_name` VARCHAR(150) NOT NULL,
        `category` VARCHAR(50) NOT NULL DEFAULT 'Coffee',
        `variant` VARCHAR(60) NOT NULL DEFAULT '',
        `qty` INT NOT NULL DEFAULT 1,
        `unit_price` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `discount_amount` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `final_unit_price` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        `line_total` DECIMAL(12, 2) NOT NULL DEFAULT 0,
        INDEX `idx_order_items_category` (`category`),
        CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `product`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure demo customer account exists for PDF testing alongside existing accounts
    $cust_demo_check = mysqli_query($conn, "SELECT id FROM `users` WHERE `username` = 'customer' OR `email` = 'customer@kopipiro.test' LIMIT 1");
    if ($cust_demo_check && mysqli_num_rows($cust_demo_check) === 0) {
        $demo_hash = password_hash('password123', PASSWORD_DEFAULT);
        $stmt_demo = mysqli_prepare($conn, "INSERT INTO `users` (`username`, `name`, `email`, `password`, `password_hash`, `role`) VALUES ('customer', 'Demo Customer', 'customer@kopipiro.test', ?, ?, 'customer')");
        if ($stmt_demo) {
            mysqli_stmt_bind_param($stmt_demo, "ss", $demo_hash, $demo_hash);
            @mysqli_stmt_execute($stmt_demo);
            $new_demo_uid = mysqli_insert_id($conn);
            if ($new_demo_uid > 0) {
                $stmt_dm = mysqli_prepare($conn, "INSERT IGNORE INTO `members` (`user_id`, `username`, `password`, `discount_rate`) VALUES (?, 'customer', ?, 0.10)");
                if ($stmt_dm) {
                    mysqli_stmt_bind_param($stmt_dm, "is", $new_demo_uid, $demo_hash);
                    @mysqli_stmt_execute($stmt_dm);
                }
            }
        }
    }

    // Backfill email, name, and password_hash on users for full PDF API compatibility
    mysqli_query($conn, "UPDATE `users` SET `password_hash` = `password` WHERE `password_hash` = ''");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Kopipiro Owner', `email` = 'owner@kopipiro.test' WHERE `username` = 'owner' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Store Admin', `email` = 'admin@kopipiro.test' WHERE `username` = 'admin' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Cashier Ani', `email` = 'cashier1@kopipiro.test' WHERE `username` = 'cashier1' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Cashier Budi', `email` = 'cashier2@kopipiro.test' WHERE `username` = 'cashier2' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Demo Customer', `email` = 'customer@kopipiro.test' WHERE `username` = 'customer' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = 'Amba', `email` = 'amba@kopipiro.test' WHERE `username` = 'Amba' AND (`email` IS NULL OR `email` = '')");
    mysqli_query($conn, "UPDATE `users` SET `name` = `username` WHERE `name` = ''");

    // Sync category_id and image on product rows
    mysqli_query($conn, "UPDATE `product` p JOIN `categories` c ON c.name = p.category SET p.category_id = c.id");
    mysqli_query($conn, "UPDATE `product` SET `image` = `url_image` WHERE (`image` IS NULL OR `image` = '') AND `url_image` != ''");

    // Backfill order_code on existing orders
    mysqli_query($conn, "UPDATE `orders` SET `order_code` = CONCAT('KP-261112-000', `id`) WHERE `order_code` IS NULL OR `order_code` = ''");

    // Seed initial reviews if empty so AVG(r.rating) works in api/products.php
    $rev_check = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM `reviews`");
    $rev_row = $rev_check ? mysqli_fetch_assoc($rev_check) : ['cnt' => 0];
    if ((int)$rev_row['cnt'] === 0) {
        $cust_res = mysqli_query($conn, "SELECT id FROM `users` WHERE `role` = 'customer' LIMIT 1");
        $cust = $cust_res ? mysqli_fetch_assoc($cust_res) : null;
        if ($cust) {
            $cid = (int)$cust['id'];
            mysqli_query($conn, "INSERT INTO `reviews` (`product_id`, `user_id`, `rating`, `comment`)
                                 SELECT `id`, $cid, 5, 'Delicious Kopipiro menu item!' FROM `product`");
        }
    }
}

ensure_kopipiro_schema($conn);
?>

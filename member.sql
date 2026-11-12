-- Database: coffee_shop (Kopipiro Coffee & Toast)
CREATE DATABASE IF NOT EXISTS `coffee_shop` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `coffee_shop`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `members`;
DROP TABLE IF EXISTS `product`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `categories`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Categories table
CREATE TABLE `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'Coffee'),
(2, 'Non-Coffee'),
(3, 'Toast'),
(4, 'Dessert'),
(5, 'Add-ons: Milk'),
(6, 'Add-ons: Syrup'),
(7, 'Add-ons: Shots'),
(8, 'Food');

-- 2. Users table (Owner/Admin, Cashier, Customer)
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `email` VARCHAR(120) DEFAULT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
    `role` ENUM('admin', 'owner', 'cashier', 'customer') NOT NULL DEFAULT 'customer',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `username`, `name`, `email`, `password`, `password_hash`, `role`) VALUES
(1, 'owner',    'Kopipiro Owner', 'owner@kopipiro.test',    '$2y$10$ohR/Rt6evAFD9AXbHkYYXuKGpNEBGDHnOJBujEBCokBJE6lTZEgKq', '$2y$10$ohR/Rt6evAFD9AXbHkYYXuKGpNEBGDHnOJBujEBCokBJE6lTZEgKq', 'owner'),    -- owner123
(2, 'admin',    'Store Admin',    'admin@kopipiro.test',    '$2y$10$9WgAcpD0/tbNGYqwHsRvkuFdcjUZiXCH7y/moXQWHaDkfGsXyLHfu', '$2y$10$9WgAcpD0/tbNGYqwHsRvkuFdcjUZiXCH7y/moXQWHaDkfGsXyLHfu', 'admin'),    -- admin123
(3, 'cashier1', 'Cashier Ani',    'cashier1@kopipiro.test', '$2y$10$Z3Bxvmdvt8BQJhIFANx/Gucg9V1oDKLnq4pqStaP7964Fr0jlnF6a', '$2y$10$Z3Bxvmdvt8BQJhIFANx/Gucg9V1oDKLnq4pqStaP7964Fr0jlnF6a', 'cashier'),  -- cashier123
(4, 'cashier2', 'Cashier Budi',   'cashier2@kopipiro.test', '$2y$10$Z3Bxvmdvt8BQJhIFANx/Gucg9V1oDKLnq4pqStaP7964Fr0jlnF6a', '$2y$10$Z3Bxvmdvt8BQJhIFANx/Gucg9V1oDKLnq4pqStaP7964Fr0jlnF6a', 'cashier'),  -- cashier123
(5, 'customer', 'Demo Customer',  'customer@kopipiro.test', '$2y$10$Z6bUYuw9HGyVZdlOpHCi6uqXYmkCryS.7gbYs51YdNIZfMSl2oSyS', '$2y$10$Z6bUYuw9HGyVZdlOpHCi6uqXYmkCryS.7gbYs51YdNIZfMSl2oSyS', 'customer'), -- customer123
(6, 'Amba',     'Amba',           'amba@kopipiro.test',     '$2y$10$L8Zm8zGgtWErfCXrRbwu3uxkFBk7LqETQl7iMr3/fCmiu69yP5kgi', '$2y$10$L8Zm8zGgtWErfCXrRbwu3uxkFBk7LqETQl7iMr3/fCmiu69yP5kgi', 'customer'), -- tukam
(7, 'Aku',      'Aku',            'aku@kopipiro.test',      '$2y$10$BVi6N0bXYqIIl3T.nIGCkO6dS7nOL0BpKn4t7VcbKTD6yqyuHyu5K', '$2y$10$BVi6N0bXYqIIl3T.nIGCkO6dS7nOL0BpKn4t7VcbKTD6yqyuHyu5K', 'customer'), -- kucing
(8, 'Kamu',     'Kamu',           'kamu@kopipiro.test',     '$2y$10$yfNqVJsALyP8RggbXoXK8.CmIiN13pdfm87LBTRqIFAn7Rll3XiWm', '$2y$10$yfNqVJsALyP8RggbXoXK8.CmIiN13pdfm87LBTRqIFAn7Rll3XiWm', 'customer'), -- anjing
(9, 'TheyThem', 'TheyThem',       'theythem@kopipiro.test', '$2y$10$oQbcfXhz9t7ZGCrr1V8gz.v06jRCDPX1WvOpXFomc2HfXflkMWrcS', '$2y$10$oQbcfXhz9t7ZGCrr1V8gz.v06jRCDPX1WvOpXFomc2HfXflkMWrcS', 'customer'); -- wowo

-- 3. Members table (Registered buyers who receive the 10% discount)
CREATE TABLE `members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL UNIQUE,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `discount_rate` DECIMAL(4, 2) NOT NULL DEFAULT 0.10,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `members` (`id`, `user_id`, `username`, `password`, `discount_rate`) VALUES
(1, 6, 'Amba',     '$2y$10$L8Zm8zGgtWErfCXrRbwu3uxkFBk7LqETQl7iMr3/fCmiu69yP5kgi', 0.10),
(2, 7, 'Aku',      '$2y$10$BVi6N0bXYqIIl3T.nIGCkO6dS7nOL0BpKn4t7VcbKTD6yqyuHyu5K', 0.10),
(3, 8, 'Kamu',     '$2y$10$yfNqVJsALyP8RggbXoXK8.CmIiN13pdfm87LBTRqIFAn7Rll3XiWm', 0.10),
(4, 9, 'TheyThem', '$2y$10$oQbcfXhz9t7ZGCrr1V8gz.v06jRCDPX1WvOpXFomc2HfXflkMWrcS', 0.10),
(5, 5, 'customer', '$2y$10$Z6bUYuw9HGyVZdlOpHCi6uqXYmkCryS.7gbYs51YdNIZfMSl2oSyS', 0.10);

-- 4. Product menu table
CREATE TABLE `product` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `product` (`id`, `category_id`, `name`, `category`, `temp_type`, `price`, `price_cold`, `price_hot`, `description`, `rating`, `url_image`, `image`, `is_active`) VALUES
(1, 1, 'Americano', 'Coffee', 'both', 14999, 14999, 11999, 'Clasic coffee so good and refreshing.', '10/10', 'assets/Americno.jpg', 'assets/Americno.jpg', 1),
(2, 1, 'Latte', 'Coffee', 'both', 19999, 19999, 17999, 'Coffee + Milk, creamy and smooth.', '10/10', 'assets/Latte.webp', 'assets/Latte.webp', 1),
(3, 1, 'Mocha', 'Coffee', 'both', 19999, 19999, 17999, 'Coffee + Milk + Chocolate, so good and classy.', '10/10', 'assets/Mocha.webp', 'assets/Mocha.webp', 1),
(4, 1, 'Mont Blanc', 'Coffee', 'cold_only', 30000, 30000, NULL, 'A bold iced black coffee or cold brew base at the bottom, topped with a thick, flowing cream foam (known as a "snow top").', 'Owner''s fav 100/10', 'assets/mont-blanc.webp', 'assets/mont-blanc.webp', 1),
(5, 1, 'Kopikap', 'Coffee', 'cold_only', 3000, 3000, NULL, 'Ready-to-drink cappuccino-flavored coffee beverage, packaged in a small plastic cup.', '???', 'assets/kopikap.jpg', 'assets/kopikap.jpg', 1),
(6, 2, 'Matcha latte', 'Non-Coffee', 'both', 21999, 21999, 19999, 'Pure Japanese Matcha tea leaves. Premium and authentic.', '10/10', 'assets/matcha-latte.jpg', 'assets/matcha-latte.jpg', 1),
(7, 2, 'Frappe (Matcha/Vanilla/Chocolate/Caramel)', 'Non-Coffee', 'cold_only', 24999, 24999, NULL, 'Blended beverages :).', '10/10', 'assets/frappe.jpg', 'assets/frappe.jpg', 1),
(8, 2, 'Tea', 'Non-Coffee', 'both', 9999, 9999, 7999, 'Classic Javanese tea, nostalgic and calming.', '10/0', 'assets/tea.jpg', 'assets/tea.jpg', 1),
(9, 2, 'Milk Tea', 'Non-Coffee', 'both', 16999, 16999, 14999, 'Creamy, calming, and smooth. So good.', 'My favvvvvv', 'assets/milk-tea.webp', 'assets/milk-tea.webp', 1),
(10, 2, 'Mineral Water', 'Non-Coffee', 'both', 100000, 100000, 99999, 'Mineral water from Mississipi River.', '???', 'assets/water.jpg', 'assets/water.jpg', 1),
(11, 3, 'Honey Toast', 'Toast', 'none', 15999, NULL, NULL, 'Honey, lots of honey melt in your mouth, real honey.', '10/10', 'assets/honey-toast.webp', 'assets/honey-toast.webp', 1),
(12, 3, 'Kaya Toast Pandan', 'Toast', 'none', 15999, NULL, NULL, 'Kaya jam is infused with pandan and a slab of cold butter.', '10/10', 'assets/pandan-toast.jpeg', 'assets/pandan-toast.jpeg', 1),
(13, 3, 'Tuna Mayo', 'Toast', 'none', 24999, NULL, NULL, 'Tuna + lots of creamy mayo on warm toasted bread, savory and super filling.', '10/10', 'assets/tunamayo.jpg', 'assets/tunamayo.jpg', 1),
(14, 3, 'Chhese Toast', 'Toast', 'none', 21999, NULL, NULL, 'cheeeessssyyyyyyyyyyyy.', '10/10', 'assets/chhese.jpg', 'assets/chhese.jpg', 1),
(15, 3, 'Bread toast', 'Toast', 'none', 10000, NULL, NULL, 'Bread. Toasted.', '10/10', 'assets/bread-toast.jpg', 'assets/bread-toast.jpg', 1),
(16, 5, 'Soy milk', 'Add-ons: Milk', 'none', 7999, NULL, NULL, '', '', 'assets/soya.jpeg', 'assets/soya.jpeg', 1),
(17, 5, 'Oat milk', 'Add-ons: Milk', 'none', 100000, NULL, NULL, '', '', 'assets/oatmilk.jpeg', 'assets/oatmilk.jpeg', 1),
(18, 5, 'Almond milk', 'Add-ons: Milk', 'none', 7999, NULL, NULL, '', '', 'assets/almond-milk.jpeg', 'assets/almond-milk.jpeg', 1),
(19, 5, 'Non-fat/Low-fat', 'Add-ons: Milk', 'none', 0, NULL, NULL, '', '', 'assets/low-non-fat.jpeg', 'assets/low-non-fat.jpeg', 1),
(20, 6, 'Simple Syrup', 'Add-ons: Syrup', 'none', 3000, NULL, NULL, '', '', 'assets/simple.jpg', 'assets/simple.jpg', 1),
(21, 6, 'Caramel Syrup', 'Add-ons: Syrup', 'none', 5000, NULL, NULL, '', '', 'assets/CaramelSyurp.jpg', 'assets/CaramelSyurp.jpg', 1),
(22, 6, 'Hazelnut Syrup', 'Add-ons: Syrup', 'none', 5000, NULL, NULL, '', '', 'assets/hazelnut-syrupo.jpeg', 'assets/hazelnut-syrupo.jpeg', 1),
(23, 6, 'Earl-Grey Syrup', 'Add-ons: Syrup', 'none', 5000, NULL, NULL, '', '', 'assets/Earl-Grey-Syrup.jpg', 'assets/Earl-Grey-Syrup.jpg', 1),
(24, 7, 'Extra Espresso Shot (for coffee)', 'Add-ons: Shots', 'none', 3000, NULL, NULL, '', '', 'assets/shot.jpg', 'assets/shot.jpg', 1),
(25, 4, 'Ice cream (Matcha/Chocolate/Vanilla/Strawberry/Ube/Durian)', 'Dessert', 'cold_only', 5000, 5000, NULL, 'Cold, sweet, and super creamy scoop of ice cream, melts in your mouth.', '10/10', 'assets/ice-cream.jpg', 'assets/ice-cream.jpg', 1),
(26, 4, 'Strawberry Cream Cake', 'Dessert', 'none', 12000, NULL, NULL, 'This one is super creamyyyyyy I recommend', '10/10', 'assets/strawberry-cream-cake.jpg', 'assets/strawberry-cream-cake.jpg', 1);

-- 5. Reviews table
CREATE TABLE `reviews` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `rating` TINYINT NOT NULL,
    `comment` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `product`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `reviews` (`product_id`, `user_id`, `rating`, `comment`) VALUES
(1, 5, 5, 'Classic refreshing Americano.'),
(4, 6, 5, 'Mont Blanc cream top is amazing.');

-- 6. Orders table
CREATE TABLE `orders` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Order Items table
CREATE TABLE `order_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `orders` (`id`, `order_code`, `user_id`, `member_id`, `customer_name`, `is_member`, `cashier_id`, `status`, `subtotal`, `discount`, `total`) VALUES
(1, 'KP-261112-0001', 6, 1, 'Amba', 1, 3, 'paid', 45999.00, 4599.90, 41399.10),
(2, 'KP-261112-0002', NULL, NULL, 'Guest Buyer', 0, 4, 'paid', 26999.00, 0.00, 26999.00);

INSERT INTO `order_items` (`order_id`, `product_id`, `product_name`, `category`, `variant`, `qty`, `unit_price`, `discount_amount`, `final_unit_price`, `line_total`) VALUES
(1, 4, 'Mont Blanc', 'Coffee', 'Cold (C)', 1, 30000.00, 3000.00, 27000.00, 27000.00),
(1, 11, 'Honey Toast', 'Toast', 'Standard', 1, 15999.00, 1599.90, 14399.10, 14399.10),
(2, 1, 'Americano', 'Coffee', 'Cold (C)', 1, 14999.00, 0.00, 14999.00, 14999.00),
(2, 26, 'Strawberry Cream Cake', 'Dessert', 'Standard', 1, 12000.00, 0.00, 12000.00, 12000.00);

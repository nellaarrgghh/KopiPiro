# Kopipiro Coffee & Toast

A PHP and MySQL coffee shop ordering and store management web application for **Kopipiro**.

## Student Info
- **Name:** Naila Sa'ada Cahyani
- **NRP:** 5025251258

---

## How to Open & Run the Website

### 1. Start MySQL (XAMPP)
Open **XAMPP** and start **MySQL Database** (and **Apache Web Server** if using XAMPP's browser URL).
- On the first page load, `config.php` automatically creates the `coffee_shop` database, tables, accounts, and 26 menu items.
- Alternatively, you can import `member.sql` manually in phpMyAdmin (`http://localhost/phpmyadmin`).

### 2. Open in Browser
Choose either option:
- **Option A (PHP Built-in Server):**
  Run this command inside the project folder:
  ```bash
  php -S localhost:8000
  ```
  Then open: **http://localhost:8000/index.php**
- **Option B (XAMPP `htdocs`):**
  Place or link the folder inside `htdocs/study_case` and open: **http://localhost/study_case/index.php**

---

## Application Flow

1. **Browse Menu (`index.php`):**
   - View Coffee, Non-Coffee, Toast, Dessert, and Add-ons with local product photos (`assets/`), descriptions, ratings, Cold/Hot prices, and a live Jakarta weather recommendation banner (Open-Meteo API via PHP `cURL`).
   - Select temperatures, flavors, and quantities across multiple products at once and add them to your checkout list.
2. **Member Discount vs Guest Pricing (`login.php`, `register.php`, `order.php`):**
   - **Guest (Not Logged In):** Pays normal menu prices (0% discount).
   - **Registered Customer (Logged In):** Automatically gets **10% off** every item.
3. **Checkout & Receipt (`order.php`, `sandbox_pay.php`):**
   - Review the itemized checkout table showing normal price, 10% discount, and final total.
   - Confirm the order to save it to the MySQL database (`orders` and `order_items`), auto-assign a cashier, view the official receipt, or simulate online payment via Midtrans Sandbox.
4. **Role-Based Dashboards (`orders.php`, `create_product.php`, `export_excel.php`):**
   - **Customer:** Places orders with a 10% discount and views order history (`my_orders.php`).
   - **Cashier:** Views and updates **only** the orders assigned to their cashier account (`orders.php`).
   - **Owner / Admin:** Adds or edits menu products with MIME-verified image uploads (`create_product.php`, `edit_product.php`), views all store orders, inspects **Chart.js** sales charts (`orders.php`), and exports orders to Excel (`export_excel.php`).
5. **REST API (`api/*.php`):**
   - JSON endpoints for authentication (`api/auth.php`), products (`api/products.php`), orders (`api/orders.php`), sales analytics (`api/sales.php`), Excel export (`api/export.php`), reviews (`api/reviews.php`), and Midtrans payment (`api/pay.php`, `api/midtrans_notify.php`).

---

## Demo Accounts

| Role | Username / Email | Password | What to Test |
| :--- | :--- | :--- | :--- |
| **Owner** | `owner` (`owner@kopipiro.test`) | `owner123` (or `password123`) | Add/edit products, view sales charts, download Excel |
| **Cashier 1** | `cashier1` (`cashier1@kopipiro.test`) | `cashier123` (or `password123`) | View only orders assigned to Cashier 1 |
| **Cashier 2** | `cashier2` (`cashier2@kopipiro.test`) | `cashier123` (or `password123`) | View only orders assigned to Cashier 2 |
| **Customer** | `Amba` or `customer` (`customer@kopipiro.test`) | `tukam` or `customer123` (or `password123`) | Order with automatic 10% member discount |

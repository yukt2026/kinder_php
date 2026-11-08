<?php
if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    // Prevent caching on shared hosts (like Hostinger/LiteSpeed) to prevent data leaks between users
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

    require_once 'rates_helper.php';

    // Dynamic Environment DB Configuration
    $isLocal = ($_SERVER['HTTP_HOST'] ?? 'localhost') === 'localhost' || ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') === '127.0.0.1';
    if ($isLocal) {
        $host = 'localhost';
        $db   = 'kinder_db';
        $user = 'root';
        $pass = '';
    } else {
        $host = 'localhost';
        $db   = 'u798623491_dasgolddb';
        $user = 'u798623491_dasgold';
        $pass = 'Dasgold2026@';
    }


    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (\PDOException $e) {
        // If connection fails because DB does not exist, try to connect without db name and create it
        try {
            $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db`");
            // Import schema
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            $pdo->exec($schema);
        } catch (\PDOException $ex) {
            die("Database connection failed: " . $ex->getMessage());
        }
    }

    // Run schema migration to support Google OAuth & Password Resets & Admin Privileges
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `oauth_provider` VARCHAR(50) DEFAULT 'local'");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `oauth_id` VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `reset_expires` DATETIME DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `password_hash` VARCHAR(255) NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) DEFAULT 1");
    } catch (Exception $e) {}
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ledger_settlements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `bapari_id` INT NOT NULL,
                `settlement_date` DATE NOT NULL,
                `closing_gold` DECIMAL(12, 3) NOT NULL,
                `closing_cash` DECIMAL(12, 2) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                FOREIGN KEY (`bapari_id`) REFERENCES `baparis` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}

    // Add Net split & extra pure gold columns to kaj_items table
    try {
        $pdo->exec("ALTER TABLE `kaj_items` ADD COLUMN `net_part1` DECIMAL(12, 3) DEFAULT 0.000");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `kaj_items` ADD COLUMN `net_part2` DECIMAL(12, 3) DEFAULT 0.000");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `kaj_items` ADD COLUMN `wastage1` DECIMAL(5, 2) DEFAULT 0.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `kaj_items` ADD COLUMN `wastage2` DECIMAL(5, 2) DEFAULT 0.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `kaj_items` ADD COLUMN `extra_pure` DECIMAL(12, 3) DEFAULT 0.000");
    } catch (Exception $e) {}

    // Karigor Module Tables Migration
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `karigors` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `mobile` VARCHAR(50) DEFAULT NULL,
                `address` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `karigor_material_issues` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `date` DATE NOT NULL,
                `karigor_id` INT NOT NULL,
                `fine_weight` DECIMAL(12, 3) NOT NULL,
                `purity` DECIMAL(5, 2) NOT NULL DEFAULT 100.00,
                `issue_fine` DECIMAL(12, 3) NOT NULL,
                `cash_paid` DECIMAL(12, 2) DEFAULT 0.00,
                `remark` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                FOREIGN KEY (`karigor_id`) REFERENCES `karigors` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `karigor_kaj_receives` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `date` DATE NOT NULL,
                `karigor_id` INT NOT NULL,
                `total_receive_fine` DECIMAL(12, 3) NOT NULL,
                `total_profit_fine` DECIMAL(12, 3) DEFAULT 0.000,
                `cash_paid` DECIMAL(12, 2) DEFAULT 0.00,
                `remark` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                FOREIGN KEY (`karigor_id`) REFERENCES `karigors` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `karigor_kaj_receive_items` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `karigor_receive_id` INT NOT NULL,
                `item` VARCHAR(100) NOT NULL,
                `gross` DECIMAL(12, 3) NOT NULL,
                `less` DECIMAL(12, 3) DEFAULT 0.000,
                `net` DECIMAL(12, 3) NOT NULL,
                `milting` DECIMAL(5, 2) NOT NULL,
                `wastage` DECIMAL(5, 2) DEFAULT 0.00,
                `hisab` DECIMAL(5, 2) NOT NULL,
                `receive_fine` DECIMAL(12, 3) NOT NULL,
                `profit_fine` DECIMAL(12, 3) DEFAULT 0.000,
                `net_part1` DECIMAL(12, 3) DEFAULT 0.000,
                `net_part2` DECIMAL(12, 3) DEFAULT 0.000,
                `wastage1` DECIMAL(5, 2) DEFAULT 0.00,
                `wastage2` DECIMAL(5, 2) DEFAULT 0.00,
                `extra_pure` DECIMAL(12, 3) DEFAULT 0.000,
                FOREIGN KEY (`karigor_receive_id`) REFERENCES `karigor_kaj_receives` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE `karigor_kaj_receives` ADD COLUMN `total_profit_less` DECIMAL(12, 3) DEFAULT 0.000");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE `karigor_kaj_receive_items` ADD COLUMN `profit_less` DECIMAL(12, 3) DEFAULT 0.000");
    } catch (Exception $e) {}

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `karigor_ledger_settlements` (

                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `karigor_id` INT NOT NULL,
                `settlement_date` DATE NOT NULL,
                `closing_gold` DECIMAL(12, 3) NOT NULL,
                `closing_cash` DECIMAL(12, 2) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                FOREIGN KEY (`karigor_id`) REFERENCES `karigors` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB;
        ");
    } catch (Exception $e) {}


    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `company_name` VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `company_mobile` VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `company_address` TEXT DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `company_gst` VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        // Ensure company_logo is LONGTEXT to support Base64 storage
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `company_logo` LONGTEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `company_logo` LONGTEXT DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `gold_api_key` VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `rate_24k` DECIMAL(12, 2) DEFAULT 12565.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `rate_22k` DECIMAL(12, 2) DEFAULT 11510.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `rate_ag` DECIMAL(12, 2) DEFAULT 179.00");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `rates_last_updated` INT DEFAULT 0");
    } catch (Exception $e) {}

    // Auto-seed admin access and reset password to password123
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute(['admin@admin.com']);
        $adminExists = $stmt->fetch();
        
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        if (!$adminExists) {
            $insertStmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, is_admin, is_active) VALUES ('admin@admin.com', ?, 'Suman Kanti Das', 1, 1)");
            $insertStmt->execute([$hash]);
        } else {
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, is_admin = 1, is_active = 1 WHERE email = 'admin@admin.com'");
            $updateStmt->execute([$hash]);
        }
        
        // Also promote the screenshot admin account if it exists
        $pdo->exec("UPDATE `users` SET password_hash = '$hash', is_admin = 1, is_active = 1 WHERE email = 'sumankantidas100@gmail.com'");
    } catch (Exception $e) {}

    // Enforce Authentication
    $current_page = basename($_SERVER['PHP_SELF']);
    $public_pages = ['login.php', 'register.php', 'google-callback.php', 'forgot-password.php', 'reset-password.php'];

    if (isset($_SESSION['user_id']) && !in_array($current_page, $public_pages)) {
        // Check if user is still active in database
        $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $uStatus = $stmt->fetch();
        if (!$uStatus || intval($uStatus['is_active']) !== 1) {
            session_unset();
            session_destroy();
            header("Location: login.php?error=deactivated");
            exit();
        }
    }

    if (!isset($_SESSION['user_id']) && !in_array($current_page, $public_pages)) {
        header("Location: login.php");
        exit();
    }

    $userId = $_SESSION['user_id'] ?? null;
    $currentUser = null;

    $rate24k = 12565.0;
    $rate22k = 11510.0;
    $rateAg = 179.0;

    // Expose global Admin check flag
    $isAdmin = false;
    $isReadOnly = false;
    if ($userId) {
        // Fetch active user details (including company profile parameters)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();

        $ratesConfig = refreshRatesIfNeeded($pdo, $userId);
        $rate24k = $ratesConfig['rate_24k'];
        $rate22k = $ratesConfig['rate_22k'];
        $rateAg = $ratesConfig['rate_ag'];

        $checkId = $_SESSION['impersonator_id'] ?? $userId;
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$checkId]);
        $uCheck = $stmt->fetch();
        $isAdmin = ($uCheck && intval($uCheck['is_admin']) === 1);
        $isReadOnly = isset($_SESSION['impersonator_id']);
    }

    // Global helper functions
    function isSettled($pdo, $bapariId, $userId, $date) {
        $stmt = $pdo->prepare("SELECT MAX(settlement_date) as last_settle FROM ledger_settlements WHERE bapari_id = ? AND user_id = ?");
        $stmt->execute([$bapariId, $userId]);
        $row = $stmt->fetch();
        $lastSettle = $row ? $row['last_settle'] : null;
        return ($lastSettle && $date < $lastSettle);
    }
    
    function isKarigorSettled($pdo, $karigorId, $userId, $date) {
        $stmt = $pdo->prepare("SELECT MAX(settlement_date) as last_settle FROM karigor_ledger_settlements WHERE karigor_id = ? AND user_id = ?");
        $stmt->execute([$karigorId, $userId]);
        $row = $stmt->fetch();
        $lastSettle = $row ? $row['last_settle'] : null;
        return ($lastSettle && $date < $lastSettle);
    }
    
    // Duplicate Entry Detection Helpers
    function findDuplicateDeposit($pdo, $userId, $bapariId, $date, $fineWeight, $jamaFine, $cashReceived, $excludeId = 0) {
        $sql = "SELECT id, date, fine_weight, jama_fine, cash_received, created_at 
                FROM fine_deposits 
                WHERE user_id = ? AND bapari_id = ? AND date = ? 
                  AND ABS(fine_weight - ?) < 0.001 
                  AND ABS(jama_fine - ?) < 0.001 
                  AND ABS(cash_received - ?) < 0.01";
        $params = [$userId, $bapariId, $date, $fineWeight, $jamaFine, $cashReceived];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    function findDuplicateKaj($pdo, $userId, $bapariId, $date, $totalKajFine, $cashBill, $excludeId = 0) {
        $sql = "SELECT id, date, total_kaj_fine, cash_bill, created_at 
                FROM kaj_entries 
                WHERE user_id = ? AND bapari_id = ? AND date = ? 
                  AND ABS(total_kaj_fine - ?) < 0.001 
                  AND ABS(cash_bill - ?) < 0.01";
        $params = [$userId, $bapariId, $date, $totalKajFine, $cashBill];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    function findDuplicateKarigorIssue($pdo, $userId, $karigorId, $date, $fineWeight, $issueFine, $cashPaid, $excludeId = 0) {
        $sql = "SELECT id, date, fine_weight, issue_fine, cash_paid, created_at 
                FROM karigor_material_issues 
                WHERE user_id = ? AND karigor_id = ? AND date = ? 
                  AND ABS(fine_weight - ?) < 0.001 
                  AND ABS(issue_fine - ?) < 0.001 
                  AND ABS(cash_paid - ?) < 0.01";
        $params = [$userId, $karigorId, $date, $fineWeight, $issueFine, $cashPaid];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    function findDuplicateKarigorReceive($pdo, $userId, $karigorId, $date, $totalReceiveFine, $cashPaid, $excludeId = 0) {
        $sql = "SELECT id, date, total_receive_fine, cash_paid, created_at 
                FROM karigor_kaj_receives 
                WHERE user_id = ? AND karigor_id = ? AND date = ? 
                  AND ABS(total_receive_fine - ?) < 0.001 
                  AND ABS(cash_paid - ?) < 0.01";
        $params = [$userId, $karigorId, $date, $totalReceiveFine, $cashPaid];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
?>

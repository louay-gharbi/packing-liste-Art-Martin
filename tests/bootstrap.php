<?php
require_once __DIR__.'/../src/Controllers/BaseController.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        }
        $pdo->exec("CREATE TABLE sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            name TEXT NOT NULL
        );");
        $pdo->exec("CREATE TABLE packing_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL,
            site_id INTEGER,
            created_at TEXT,
            updated_at TEXT
        );");
        $pdo->exec("INSERT INTO sites (id, code, name) VALUES (1, 'TEST', 'Test site');");
        $pdo->exec("INSERT INTO packing_lists (id, reference, site_id, created_at, updated_at) VALUES (1, 'PL1', 1, datetime('now'), datetime('now')); ");
        $pdo->exec("CREATE TABLE palettes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            packing_list_id INTEGER NOT NULL,
            code TEXT NOT NULL,
            qr_code_path TEXT NULL,
            status TEXT NOT NULL,
            max_colis INTEGER NULL,
            open_date_time TEXT NULL,
            close_date_time TEXT NULL,
            created_by_user_id INTEGER NULL,
            opened_by_user_id INTEGER NULL,
            closed_by_user_id INTEGER NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );");
        $pdo->exec("CREATE TABLE colis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            palette_id INTEGER NULL,
            package_number TEXT UNIQUE,
            payload_json TEXT,
            scan_date_time TEXT,
            scanned_by_user_id INTEGER,
            type TEXT,
            parent_colis_id INTEGER,
            client_name TEXT,
            of_number TEXT,
            order_number TEXT,
            sku TEXT,
            label TEXT,
            quantity INTEGER,
            internal_reference TEXT,
            article_reference TEXT,
            unit TEXT,
            postal_code TEXT,
            line_number TEXT,
            comment TEXT,
            created_at TEXT,
            updated_at TEXT,
            FOREIGN KEY(parent_colis_id) REFERENCES colis(id) ON DELETE CASCADE
        );");
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, password_hash TEXT, email TEXT, first_name TEXT, last_name TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT, site_id INTEGER);");
        $pdo->exec("INSERT INTO users (id, username, password_hash, email, first_name, last_name, is_active, created_at, updated_at, site_id) VALUES (1, 'testuser', '', 'test@example.com', 'Test', 'User', 1, '2025-01-01', '2025-01-01', NULL);");
    }
    return $pdo;
}
?>

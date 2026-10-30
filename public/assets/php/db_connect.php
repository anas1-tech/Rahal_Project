<?php
// db_connect.php

// 1. إعدادات الاتصال
// On Render: set DATABASE_URL (Neon connection string). Locally: falls back to the old XAMPP/MySQL-style defaults below.
require_once __DIR__ . '/pg_compat.php';

$database_url = getenv('DATABASE_URL');
if (!$database_url) {
    $database_url = 'postgresql://postgres:@localhost:5432/r7al';
}

// 2. إنشاء الاتصال
try {
    $conn = new PgCompatConnection($database_url);
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die("فشل الاتصال بقاعدة البيانات");
}
$conn->set_charset("utf8mb4");

// التحقق من عدم تعريف الدوال مسبقاً
if (!function_exists('get_regions')) {
    function get_regions($conn) {
        $sql = "SELECT id, name FROM regions ORDER BY name";
        $result = $conn->query($sql);
        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
}

if (!function_exists('get_cities')) {
    function get_cities($conn, $region_id = null) {
        $sql = "SELECT id, name FROM cities";
        
        if ($region_id !== null && is_numeric($region_id)) {
            $safe_region_id = intval($region_id);
            $sql .= " WHERE region_id = {$safe_region_id}";
        }
        
        $sql .= " ORDER BY name";
        $result = $conn->query($sql);
        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
}

if (!function_exists('get_categories')) {
    function get_categories() {
        return ['تراثية', 'دينية', 'سياحية','ترفيهية','ثقافية','طبيعية']; 
    }
}

if (!function_exists('add_comment')) {
    // دالة جديدة لإضافة تعليق
    function add_comment($conn, $name, $text) {
        $stmt = $conn->prepare("INSERT INTO comments (name, text) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $text);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

if (!function_exists('get_comments')) {
    // دالة جديدة لجلب التعليقات
    function get_comments($conn) {
        $sql = "SELECT * FROM comments ORDER BY date DESC";
        $result = $conn->query($sql);
        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        return [];
    }
}

if (!function_exists('get_attraction_location')) {
    function get_attraction_location($conn, $attraction_id) {
        $stmt = $conn->prepare("SELECT location FROM tourist_attractions WHERE id = ?");
        $stmt->bind_param("i", $attraction_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $location = $result->fetch_assoc();
        $stmt->close();
        return $location;
    }
}
?>

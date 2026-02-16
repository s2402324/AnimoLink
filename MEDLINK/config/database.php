<?php
declare(strict_types=1);

/**
 * Simple MySQLi connection helper for XAMPP.
 * 
 * Supports environment variables (optional):
 * - DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT
 */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $name = getenv('DB_NAME') ?: 'medlink';
    $port = (int)(getenv('DB_PORT') ?: 3306);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $user, $pass, $name, $port);
    $conn->set_charset('utf8mb4');

    return $conn;
}


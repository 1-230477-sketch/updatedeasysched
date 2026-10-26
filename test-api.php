<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Simple API test - no session needed
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    // Test 1: Simple response
    echo json_encode(['ok' => true, 'test' => 'simple_response']) . "\n";
    
    // Test 2: Try to load database
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
    echo "Database loaded\n";
    
    // Test 3: Try to connect
    $pdo = db();
    echo "Database connected\n";
    
    // Test 4: Run a simple query
    $result = $pdo->query('SELECT 1 as test')->fetch();
    echo json_encode(['ok' => true, 'database_query' => $result]) . "\n";
    
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>

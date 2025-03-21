<?php
// Add this to top of IndexController.php temporarily for debugging
file_put_contents(
    __DIR__ . '/edit-debug.log',
    date('Y-m-d H:i:s') . ': ' . print_r([
        'action' => 'editAction called',
        'request' => $_SERVER['REQUEST_URI'],
        'id' => $_GET['id'] ?? 'not set',
    ], true),
    FILE_APPEND
);
?>
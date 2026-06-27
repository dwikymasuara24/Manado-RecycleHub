<?php
// Set a quick connection timeout
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT            => 2, // 2 seconds timeout
];

try {
    echo "Connecting to MySQL...\n";
    $pdo = new PDO("mysql:host=localhost;port=3306;dbname=hub;charset=utf8mb4", "root", "", $options);
    echo "Connected successfully!\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM blog_posts");
    $count = $stmt->fetchColumn();
    echo "Total posts in blog_posts table: " . $count . "\n";
    
    if ($count > 0) {
        $posts = $pdo->query("SELECT id, judul, status, gambar_url FROM blog_posts")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posts as $p) {
            echo "ID: {$p['id']} | Title: {$p['judul']} | Status: {$p['status']} | Image: {$p['gambar_url']}\n";
        }
    }
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}

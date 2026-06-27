<?php
require_once __DIR__ . '/../include/config.php';
try {
    $stmt = getDB()->query("SELECT * FROM blog_posts");
    $posts = $stmt->fetchAll();
    echo "Total posts in DB: " . count($posts) . "\n";
    foreach ($posts as $p) {
        echo "ID: " . $p['id'] . " | Title: " . $p['judul'] . " | Status: " . $p['status'] . " | Image URL: " . $p['gambar_url'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

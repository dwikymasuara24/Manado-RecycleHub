<?php
require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$db = getDB();

try {
    $db->exec("ALTER TABLE survey_responses ADD COLUMN response_code VARCHAR(20) NULL AFTER id");
    echo "Added response_code.\n";
} catch (Exception $e) {
    echo "response_code error or already exists: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE survey_responses ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    echo "Added updated_at.\n";
} catch (Exception $e) {
    echo "updated_at error or already exists: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE survey_responses ADD COLUMN q6_kesulitan TEXT NULL");
    echo "Added q6_kesulitan.\n";
} catch (Exception $e) {
    echo "q6_kesulitan error or already exists: " . $e->getMessage() . "\n";
}

echo "Done.\n";

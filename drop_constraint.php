<?php
require_once __DIR__ . '/include/config.php';
$pdo = getDB();

try {
    $pdo->exec("ALTER TABLE survey_responses DROP CHECK survey_responses_chk_1");
    echo "Dropped constraint survey_responses_chk_1\n";
} catch (Exception $e) {
    echo "Error dropping constraint: " . $e->getMessage() . "\n";
}

try {
    // Some MySQL versions use different name for the check constraint automatically created for JSON columns
    $pdo->exec("ALTER TABLE survey_responses MODIFY q5_jenis_sampah_didaur_ulang TEXT NULL");
    echo "Modified column to TEXT\n";
} catch (Exception $e) {
    echo "Error modifying column: " . $e->getMessage() . "\n";
}

echo "Done.";

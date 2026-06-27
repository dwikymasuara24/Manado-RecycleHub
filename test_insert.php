<?php
require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = getDB();

try {
    $pdo->exec("ALTER TABLE survey_responses DROP CHECK survey_responses_chk_1");
    echo "Dropped constraint\n";
} catch (Exception $e) {
    echo "Error dropping constraint: " . $e->getMessage() . "\n";
}

try {
    $stmt = $pdo->prepare("INSERT INTO survey_responses
        (response_code,
         q1_sampah_mendesak,
         q2_paham_3r,
         q3_daur_ulang_rumah,
         q4_pilah_organik_anorganik,
         q5_jenis_sampah_didaur_ulang,
         q6_kesulitan,
         q7_bersedia_pilah,
         nama, email, nomor_wa, alamat,
         created_at, updated_at)
        VALUES
        (:response_code,
         :q1, :q2, :q3, :q4, :q5, :q6, :q7,
         :nama, :email, :wa, :alamat,
         NOW(), NOW())");

    $stmt->execute([
        ':response_code' => 'TEST-' . time(),
        ':q1'  => 'Ya',
        ':q2'  => 'Ya',
        ':q3'  => 'Ya',
        ':q4'  => 'Ya',
        ':q5'  => 'Plastik',
        ':q6'  => 'Susah',
        ':q7'  => 'Ya',
        ':nama'  => 'Test User',
        ':email' => 'test@example.com',
        ':wa'    => '123',
        ':alamat'=> 'Test Alamat',
    ]);
    echo "SUCCESS\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

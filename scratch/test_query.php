<?php
require_once __DIR__ . '/../include/config.php';
$pdo = getDB();
try {
    $track_query = 'MRH-R-003';
    $st = $pdo->prepare("
        SELECT pr.*,
               o.nama AS officer_nama,
               o.last_lat AS officer_lat,
               o.last_lng AS officer_lng,
               o.last_seen_at AS officer_last_seen,
               GROUP_CONCAT(wc.name ORDER BY wc.id SEPARATOR ', ') AS sampah_list
        FROM   pickup_requests pr
        LEFT   JOIN officers o ON o.id = pr.officer_id
        LEFT   JOIN pickup_request_items pri ON pri.pickup_id = pr.id
        LEFT   JOIN waste_categories wc ON wc.id = pri.category_id
        WHERE  pr.request_code = :code
            OR pr.nomor_wa     = :wa1
            OR pr.nomor_wa LIKE :wa2
        GROUP  BY pr.id
        ORDER  BY pr.created_at DESC
        LIMIT  20
    ");
    $st->execute([':code' => $track_query, ':wa1' => $track_query, ':wa2' => '%' . $track_query . '%']);
    $res = $st->fetchAll();
    file_put_contents(__DIR__ . '/query_result.txt', "SUCCESS: " . count($res) . " rows\n");
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/query_result.txt', "ERROR: " . $e->getMessage() . "\n");
}
echo "Done\n";

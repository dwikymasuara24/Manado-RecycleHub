<?php
require_once __DIR__ . '/../include/config.php';
$db = getDB();

$track_query = 'MRH-S-002';
$norm_q = preg_replace('/\D/', '', $track_query);
if (strpos($norm_q, '62') === 0) {
    $norm_q = substr($norm_q, 2);
} elseif (strpos($norm_q, '0') === 0) {
    $norm_q = substr($norm_q, 1);
}

try {
    $st = $db->prepare("
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
            OR (
                :norm1 <> '' AND (
                    pr.nomor_wa = :norm2
                    OR pr.nomor_wa LIKE :norm_like
                )
            )
        GROUP  BY pr.id
        ORDER  BY pr.created_at DESC
        LIMIT  20
    ");
    $st->execute([
        ':code' => $track_query, 
        ':wa1' => $track_query, 
        ':wa2' => '%' . $track_query . '%',
        ':norm1' => $norm_q,
        ':norm2' => $norm_q,
        ':norm_like' => '%' . $norm_q . '%'
    ]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "Results count: " . count($results) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

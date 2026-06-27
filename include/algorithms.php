<?php
// ============================================================
//  include/algorithms.php — Algoritma Penjadwalan MRH
//  Manado Recycle Hub
//
//  Berisi:
//    1. haversineDistance()   — jarak antara 2 koordinat (km)
//    2. nearestNeighbor()     — optimasi rute dalam satu kecamatan
//    3. priorityRule()        — urutan kecamatan berdasarkan volume request
//    4. generateSchedule()    — gabungkan kedua algoritma & simpan ke DB
//
//  Cara pakai:
//    require_once __DIR__ . '/algorithms.php';
//    $schedule = generateSchedule($db, $tanggal);
// ============================================================

// ── Koordinat Depot (Base / Gudang) ─────────────────────────
if (!defined('DEPOT_LAT'))  define('DEPOT_LAT',  1.476362);
if (!defined('DEPOT_LNG'))  define('DEPOT_LNG',  124.832498);
if (!defined('DEPOT_NAME')) define('DEPOT_NAME', 'Depot MRH — Manado Recycle Hub');

// ─────────────────────────────────────────────────────────────
//  FUNGSI 1: haversineDistance
//  Menghitung jarak (km) antara dua titik koordinat
//  menggunakan formula Haversine
// ─────────────────────────────────────────────────────────────
function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusKm * $c;
}

// ─────────────────────────────────────────────────────────────
//  FUNGSI 2: nearestNeighbor
//  Mengoptimasi urutan kunjungan dalam satu kecamatan
//
//  @param array  $points     — array titik request, tiap elemen:
//                              ['id'=>..., 'lat'=>..., 'lng'=>...]
//  @param array  $startPoint — ['lat'=>..., 'lng'=>...] koordinat depot
//
//  @return array — urutan kunjungan optimal:
//    [['id'=>..., 'lat'=>..., 'lng'=>..., 'distance_from_prev'=>float], ...]
// ─────────────────────────────────────────────────────────────
function nearestNeighbor(array $points, array $startPoint): array
{
    if (empty($points)) {
        return [];
    }

    $unvisited = $points;
    $visited   = [];

    // Titik saat ini dimulai dari depot
    $current = $startPoint;

    while (!empty($unvisited)) {
        $nearestIdx      = null;
        $nearestDist     = PHP_FLOAT_MAX;

        // Hitung jarak dari titik saat ini ke semua yang belum dikunjungi
        foreach ($unvisited as $idx => $point) {
            $lat = (float)($point['lat'] ?? $point['latitude'] ?? 0);
            $lng = (float)($point['lng'] ?? $point['longitude'] ?? 0);

            if ($lat == 0 && $lng == 0) {
                // Skip titik tanpa koordinat — taruh di akhir dengan jarak 0
                if ($nearestIdx === null) {
                    $nearestIdx  = $idx;
                    $nearestDist = 0;
                }
                continue;
            }

            $dist = haversineDistance(
                (float)$current['lat'],
                (float)$current['lng'],
                $lat,
                $lng
            );

            if ($dist < $nearestDist) {
                $nearestDist = $dist;
                $nearestIdx  = $idx;
            }
        }

        // Tandai titik terdekat sebagai dikunjungi
        $nearestPoint = $unvisited[$nearestIdx];
        $nearestPoint['distance_from_prev'] = round($nearestDist, 4);

        $visited[]  = $nearestPoint;
        $current    = [
            'lat' => (float)($nearestPoint['lat'] ?? $nearestPoint['latitude'] ?? 0),
            'lng' => (float)($nearestPoint['lng'] ?? $nearestPoint['longitude'] ?? 0),
        ];

        unset($unvisited[$nearestIdx]);
        $unvisited = array_values($unvisited); // reindex
    }

    return $visited;
}

// ─────────────────────────────────────────────────────────────
//  FUNGSI 3: priorityRule (Iteratif per Putaran)
//  Menentukan urutan kecamatan berdasarkan jumlah request terbanyak
//  dengan pendekatan ITERATIF — bukan sort sekali di awal.
//
//  Cara kerja:
//    Putaran 1: hitung semua kecamatan → pilih terbanyak
//    Putaran 2: coret kecamatan tadi, hitung ulang sisa → pilih terbanyak
//    Putaran 3: ulangi sampai semua kecamatan habis
//
//  Keunggulan: jika ada request baru masuk saat satu kecamatan
//  sedang diproses, request baru ikut dihitung di putaran berikutnya.
//  Fleksibel — berapapun jumlah kecamatan, cara kerjanya sama.
//
//  Tie-breaking: jika dua kecamatan punya jumlah request sama,
//  kecamatan dengan nama lebih awal secara alfabet dipilih duluan
//  — agar urutan selalu deterministik & konsisten.
//
//  @param array $requests — array request dikonfirmasi:
//    ['id'=>..., 'kecamatan'=>'Sario', 'lat'=>..., 'lng'=>...]
//
//  @return array — generator (yield per putaran):
//    tiap item: ['kecamatan'=>'Sario', 'count'=>5, 'requests'=>[...]]
// ─────────────────────────────────────────────────────────────
function priorityRule(array $requests): array
{
    if (empty($requests)) {
        return [];
    }

    $remaining = $requests; // sisa request yang belum dijadwalkan
    $result    = [];

    // Terus berputar selama masih ada request tersisa
    while (!empty($remaining)) {

        // ── Putaran baru: kelompokkan sisa request per kecamatan ──
        $grouped = [];
        foreach ($remaining as $r) {
            $kec = trim($r['kecamatan'] ?? '');
            if ($kec === '') continue;
            if (!isset($grouped[$kec])) {
                $grouped[$kec] = ['kecamatan' => $kec, 'count' => 0, 'requests' => []];
            }
            $grouped[$kec]['count']++;
            $grouped[$kec]['requests'][] = $r;
        }

        if (empty($grouped)) break; // tidak ada request berkecamatan valid

        // ── Pilih kecamatan dengan request TERBANYAK saat ini ──
        //    Tie-breaking: jika jumlah sama, pilih kecamatan yang pusatnya
        //    paling dekat ke Depot. Jika jaraknya pun sama, gunakan urutan
        //    alfabetis sebagai fallback agar selalu deterministik.
        $best = null;
        foreach ($grouped as $kec => $data) {
            if ($best === null) {
                $best = $data;
                continue;
            }

            $lebihBanyak  = $data['count'] > $best['count'];
            $samaBanyak   = $data['count'] === $best['count'];

            if ($lebihBanyak) {
                $best = $data;
            } elseif ($samaBanyak) {
                $centerData = getKecCenter($data['kecamatan']);
                $centerBest = getKecCenter($best['kecamatan']);

                $distData = haversineDistance((float)DEPOT_LAT, (float)DEPOT_LNG, (float)$centerData['lat'], (float)$centerData['lng']);
                $distBest = haversineDistance((float)DEPOT_LAT, (float)DEPOT_LNG, (float)$centerBest['lat'], (float)$centerBest['lng']);

                if ($distData < $distBest) {
                    $best = $data;
                } elseif (abs($distData - $distBest) < 0.0001) {
                    if (strcmp($data['kecamatan'], $best['kecamatan']) < 0) {
                        $best = $data;
                    }
                }
            }
        }

        // ── Masukkan ke hasil & hapus request kecamatan ini dari sisa ──
        $result[] = $best;
        $processed = array_column($best['requests'], 'id');
        $remaining = array_values(array_filter(
            $remaining,
            fn($r) => !in_array($r['id'], $processed)
        ));
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────
//  FUNGSI 4: generateSchedule
//  Gabungkan Priority Rule (iteratif) + Nearest Neighbor,
//  lalu simpan hasilnya ke tabel schedules, schedule_requests, routes
//
//  @param PDO    $db      — koneksi database
//  @param string $tanggal — format 'Y-m-d' (hari penjemputan)
//
//  @return array — ringkasan jadwal yang dibuat:
//    ['schedules_created'=>int, 'total_requests'=>int, 'detail'=>[...]]
// ─────────────────────────────────────────────────────────────
// ── Pusat tiap kecamatan — koordinat nyata Kota Manado ───────
function getKecCenter(string $name): array {
    $kecCenters = [
        'wenang'            => ['lat'=>1.4748,  'lng'=>124.8421],
        'malalayang'        => ['lat'=>1.4522,  'lng'=>124.8015],
        'tikala'            => ['lat'=>1.4930,  'lng'=>124.8610],
        'paal dua'          => ['lat'=>1.5012,  'lng'=>124.8700],
        'bunaken'           => ['lat'=>1.6100,  'lng'=>124.7500],
        'bunaken kepulauan' => ['lat'=>1.6800,  'lng'=>124.7200],
        'singkil'           => ['lat'=>1.4600,  'lng'=>124.8100],
        'mapanget'          => ['lat'=>1.5500,  'lng'=>124.8900],
        'wanea'             => ['lat'=>1.4800,  'lng'=>124.8500],
        'sario'             => ['lat'=>1.4650,  'lng'=>124.8300],
        'tuminting'         => ['lat'=>1.5100,  'lng'=>124.8200],
        'paal empat'        => ['lat'=>1.5150,  'lng'=>124.8750],
    ];
    $key = strtolower(trim($name));
    return $kecCenters[$key] ?? ['lat' => (float)DEPOT_LAT, 'lng' => (float)DEPOT_LNG];
}

// Helper to format date in Indonesian style
function getIndonesianDateString(string $dateStr): string {
    $timestamp = strtotime($dateStr);
    $days = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    $months = [
        1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni',
        7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
    ];
    $dayName = $days[date('l', $timestamp)] ?? date('l', $timestamp);
    $dayNum = date('j', $timestamp);
    $monthNum = (int)date('n', $timestamp);
    $monthName = $months[$monthNum] ?? date('F', $timestamp);
    $year = date('Y', $timestamp);
    return "$dayName, $dayNum $monthName $year";
}

// ─────────────────────────────────────────────────────────────
//  FUNGSI 4: generateSchedule
//  Gabungkan Priority Rule (iteratif) + Nearest Neighbor,
//  lalu simpan hasilnya ke tabel schedules, schedule_requests, routes.
//
//  @param PDO    $db      — koneksi database
//  @param string $tanggal — format 'Y-m-d' (hari penjemputan)
//
//  @return array — ringkasan jadwal yang dibuat:
//    ['schedules_created'=>int, 'total_requests'=>int, 'detail'=>[...]]
// ─────────────────────────────────────────────────────────────
function generateSchedule(PDO $db, string $tanggal, string $tipe_layanan = 'pickup'): array
{
    // ── Pre-generation Clean Up of existing Drafts ──
    // Revert status of all requests in draft schedules on or after the starting date
    if ($tipe_layanan === 'cleanup') {
        $db->prepare("
            UPDATE cleanup_requests 
            SET status = 'dikonfirmasi', officer_id = NULL, tanggal_tugas = NULL 
            WHERE id IN (
                SELECT cleanup_request_id FROM schedule_requests WHERE schedule_id IN (
                    SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'cleanup'
                )
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM schedule_requests 
            WHERE schedule_id IN (
                SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'cleanup'
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM routes 
            WHERE schedule_id IN (
                SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'cleanup'
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM schedules 
            WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'cleanup'
        ")->execute([$tanggal]);
    } else {
        $db->prepare("
            UPDATE pickup_requests 
            SET status = 'dikonfirmasi', officer_id = NULL, tanggal_jemput = NULL 
            WHERE id IN (
                SELECT request_id FROM schedule_requests WHERE schedule_id IN (
                    SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'pickup'
                )
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM schedule_requests 
            WHERE schedule_id IN (
                SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'pickup'
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM routes 
            WHERE schedule_id IN (
                SELECT id FROM schedules WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'pickup'
            )
        ")->execute([$tanggal]);
        
        $db->prepare("
            DELETE FROM schedules 
            WHERE tanggal >= ? AND status = 'draft' AND tipe_layanan = 'pickup'
        ")->execute([$tanggal]);
    }

    // ── 1. Ambil semua request dikonfirmasi ─────────────────
    if ($tipe_layanan === 'cleanup') {
        $stmt = $db->prepare("
            SELECT cr.id, cr.request_code, cr.nama_pemohon,
                   cr.kecamatan, cr.latitude, cr.longitude
            FROM   cleanup_requests cr
            WHERE  cr.status = 'dikonfirmasi'
            ORDER  BY cr.created_at ASC
        ");
    } else {
        $stmt = $db->prepare("
            SELECT pr.id, pr.request_code, pr.nama_pemohon,
                   pr.kecamatan, pr.latitude, pr.longitude
            FROM   pickup_requests pr
            WHERE  pr.status = 'dikonfirmasi'
            ORDER  BY pr.created_at ASC
        ");
    }
    $stmt->execute();
    $confirmedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($confirmedRequests)) {
        return ['schedules_created' => 0, 'total_requests' => 0, 'detail' => []];
    }

    // ── 2. Normalisasi koordinat ────────────────────────────
    foreach ($confirmedRequests as &$r) {
        $r['lat'] = (float)($r['latitude']  ?? 0);
        $r['lng'] = (float)($r['longitude'] ?? 0);
    }
    unset($r);

    // Fetch active officers for assignment
    $officers = $db->query("SELECT id FROM officers WHERE status='aktif' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $officerCount = count($officers);
    $officerIndex = 0;

    if ($officerCount === 0) {
        throw new Exception("Tidak ada petugas aktif untuk memproses jadwal.");
    }

    $schedulesCreated = 0;
    $totalRequests    = 0;
    $detail           = [];

    $remainingRequests = $confirmedRequests;

    $db->beginTransaction();

    try {
        while (!empty($remainingRequests)) {
            // Priority Rule on remaining requests
            $prioritized = priorityRule($remainingRequests);
            if (empty($prioritized)) {
                break;
            }

            // Primary/Main district from priority list
            $mainGroup = $prioritized[0];
            $mainKec = $mainGroup['kecamatan'];
            $scheduleRequests = $mainGroup['requests'];

            $maxPoints = 30; // Batas Maksimal 30 Titik (Revisi 2)

            // Logika Gabungan Antar Kecamatan (Revisi 1)
            // Jika request kecamatan utama < 30, ambil request dari kecamatan terdekat
            // (Disabled to ensure each kecamatan is scheduled on its own day sequentially)
            if (false && count($scheduleRequests) < $maxPoints && count($prioritized) > 1) {
                $mainCenter = getKecCenter($mainKec);
                
                // Hitung jarak kecamatan lain dari kecamatan utama
                $otherDistricts = [];
                for ($i = 1; $i < count($prioritized); $i++) {
                    $otherKecName = $prioritized[$i]['kecamatan'];
                    $otherCenter = getKecCenter($otherKecName);
                    $dist = haversineDistance(
                        (float)$mainCenter['lat'],
                        (float)$mainCenter['lng'],
                        (float)$otherCenter['lat'],
                        (float)$otherCenter['lng']
                    );
                    $otherDistricts[] = [
                        'kecamatan' => $otherKecName,
                        'requests' => $prioritized[$i]['requests'],
                        'distance' => $dist
                    ];
                }

                // Urutkan berdasarkan jarak terdekat (asc)
                usort($otherDistricts, fn($a, $b) => $a['distance'] <=> $b['distance']);

                // Masukkan request dari kecamatan terdekat sampai kuota 30 atau request habis
                foreach ($otherDistricts as $od) {
                    $needed = $maxPoints - count($scheduleRequests);
                    if ($needed <= 0) break;

                    $availableReqs = $od['requests'];
                    if (count($availableReqs) <= $needed) {
                        $scheduleRequests = array_merge($scheduleRequests, $availableReqs);
                    } else {
                        $scheduleRequests = array_merge($scheduleRequests, array_slice($availableReqs, 0, $needed));
                    }
                }
            }

            // Batasi scheduleRequests maksimal 30 titik
            if (count($scheduleRequests) > $maxPoints) {
                $scheduleRequests = array_slice($scheduleRequests, 0, $maxPoints);
            }

            // Kumpulkan semua kecamatan unik yang tergabung dalam jadwal ini
            $kecsInSchedule = array_unique(array_map(fn($r) => $r['kecamatan'], $scheduleRequests));
            $kecNamesString = implode(', ', $kecsInSchedule);
            if (strlen($kecNamesString) > 100) {
                $kecNamesString = substr($kecNamesString, 0, 97) . '...';
            }

            // Calculate date for this schedule: start date + $schedulesCreated days
            $scheduleDate = date('Y-m-d', strtotime($tanggal . " + $schedulesCreated days"));

            // ── Proteksi jadwal bentrok untuk tanggal ini ──
            foreach ($kecsInSchedule as $kecToCheck) {
                $stmtCheck = $db->prepare("
                    SELECT COUNT(*) FROM schedules 
                    WHERE tanggal = ? 
                      AND (kecamatan = ? OR kecamatan LIKE ?) 
                      AND status != 'cancelled'
                      AND tipe_layanan = ?
                ");
                $stmtCheck->execute([$scheduleDate, $kecToCheck, '%' . $kecToCheck . '%', $tipe_layanan]);
                if ((int)$stmtCheck->fetchColumn() > 0) {
                    $formattedDate = getIndonesianDateString($scheduleDate);
                    throw new Exception("Jadwal hari $formattedDate untuk Kecamatan $kecToCheck sudah ada. Tidak dapat menambahkan jadwal pada tanggal dan kecamatan yang sama.");
                }
            }

            // Cari atau insert kecamatan_id untuk kecamatan utama
            $kecId = null;
            try {
                $kecStmt = $db->prepare("SELECT id FROM kecamatan WHERE nama_kecamatan = ? AND aktif = 1 LIMIT 1");
                $kecStmt->execute([$mainKec]);
                $kecId = $kecStmt->fetchColumn() ?: null;

                if (!$kecId) {
                    $db->prepare("INSERT IGNORE INTO kecamatan (nama_kecamatan, aktif) VALUES (?, 1)")->execute([$mainKec]);
                    $kecId = $db->lastInsertId() ?: null;
                    if (!$kecId) {
                        $kecStmt->execute([$mainKec]);
                        $kecId = $kecStmt->fetchColumn() ?: null;
                    }
                }
            } catch (PDOException $e) {
                error_log('[MRH Algorithm] kecamatan table: ' . $e->getMessage());
            }

            $officerId = (int)$officers[$officerIndex % $officerCount]['id'];

            // ── Buat entri jadwal ──
            $schedStmt = $db->prepare("
                INSERT INTO schedules (tanggal, kecamatan_id, kecamatan, officer_id, status, tipe_layanan, created_at)
                VALUES (?, ?, ?, ?, 'draft', ?, NOW())
            ");
            $schedStmt->execute([$scheduleDate, $kecId, $kecNamesString, $officerId, $tipe_layanan]);
            $scheduleId = (int)$db->lastInsertId();

            // ── Nearest Neighbor untuk seluruh titik jadwal ini (Revisi 4) ──
            // Dimulai dari Depot
            $depot = ['lat' => (float)DEPOT_LAT, 'lng' => (float)DEPOT_LNG];
            $route = nearestNeighbor($scheduleRequests, $depot);

            // ── Simpan routes ──
            if ($tipe_layanan === 'cleanup') {
                $routeStmt = $db->prepare("
                    INSERT INTO routes
                        (schedule_id, urutan, cleanup_request_id, dist_from_prev_km)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        urutan = VALUES(urutan),
                        dist_from_prev_km = VALUES(dist_from_prev_km)
                ");
            } else {
                $routeStmt = $db->prepare("
                    INSERT INTO routes
                        (schedule_id, urutan, pickup_request_id, dist_from_prev_km)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        urutan = VALUES(urutan),
                        dist_from_prev_km = VALUES(dist_from_prev_km)
                ");
            }

            // Titik 0 = Depot (Start)
            $routeStmt->execute([$scheduleId, 0, null, 0]);

            foreach ($route as $i => $point) {
                $urutan  = $i + 1;
                $reqId   = (int)$point['id'];
                $jarak   = (float)($point['distance_from_prev'] ?? 0);

                $routeStmt->execute([$scheduleId, $urutan, $reqId, $jarak]);

                // Simpan ke schedule_requests untuk sinkronisasi
                if ($tipe_layanan === 'cleanup') {
                    $db->prepare("INSERT INTO schedule_requests (schedule_id, cleanup_request_id) VALUES (?, ?)")
                       ->execute([$scheduleId, $reqId]);

                    // Update status request → dijadwalkan, assign petugas
                    $db->prepare("
                        UPDATE cleanup_requests
                        SET status = 'dijadwalkan', 
                            officer_id = ?, 
                            tanggal_tugas = ?, 
                            jam_mulai = NULL, 
                            updated_at = NOW()
                        WHERE id = ? AND status = 'dikonfirmasi'
                    ")->execute([$officerId, $scheduleDate, $reqId]);
                    
                    triggerWhatsAppOnStatusChange($db, $reqId, 'dijadwalkan', 'cleanup');
                } else {
                    $db->prepare("INSERT INTO schedule_requests (schedule_id, request_id) VALUES (?, ?)")
                       ->execute([$scheduleId, $reqId]);

                    // Update status request → dijadwalkan, assign petugas
                    $db->prepare("
                        UPDATE pickup_requests
                        SET status = 'dijadwalkan', 
                            officer_id = ?, 
                            tanggal_jemput = ?, 
                            jam_jemput = NULL, 
                            updated_at = NOW()
                        WHERE id = ? AND status = 'dikonfirmasi'
                    ")->execute([$officerId, $scheduleDate, $reqId]);
                    
                    triggerWhatsAppOnStatusChange($db, $reqId, 'dijadwalkan', 'daur_ulang');
                }
            }

            // ── Kembali ke Depot di akhir rute (Revisi 4) ──
            $lastPoint = end($route);
            $lastLat = (float)($lastPoint['lat'] ?? $lastPoint['latitude'] ?? 0);
            $lastLng = (float)($lastPoint['lng'] ?? $lastPoint['longitude'] ?? 0);
            $distBackToDepot = 0.0;
            if ($lastLat != 0 || $lastLng != 0) {
                $distBackToDepot = haversineDistance(
                    $lastLat,
                    $lastLng,
                    (float)DEPOT_LAT,
                    (float)DEPOT_LNG
                );
            }
            $urutanAkhir = count($route) + 1;
            $routeStmt->execute([$scheduleId, $urutanAkhir, null, round($distBackToDepot, 4)]);

            // Hapus request yang sudah dijadwalkan dari sisa request
            $scheduledIds = array_column($scheduleRequests, 'id');
            $remainingRequests = array_values(array_filter(
                $remainingRequests,
                fn($r) => !in_array($r['id'], $scheduledIds)
            ));

            $schedulesCreated++;
            $totalRequests += count($route);

            $detail[] = [
                'schedule_id' => $scheduleId,
                'kecamatan'   => $kecNamesString,
                'jumlah_req'  => count($route),
                'rute'        => array_map(fn($p) => [
                    'id'       => $p['id'],
                    'code'     => $p['request_code'] ?? '',
                    'nama'     => $p['nama_pemohon'] ?? '',
                    'jarak_km' => $p['distance_from_prev'],
                ], $route),
            ];

            $officerIndex++;
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        error_log('[MRH generateSchedule] Error: ' . $e->getMessage());
        throw $e;
    }

    return [
        'schedules_created' => $schedulesCreated,
        'total_requests'    => $totalRequests,
        'tanggal'           => $tanggal,
        'detail'            => $detail,
    ];
}

// ─────────────────────────────────────────────────────────────
//  CONTOH PEMANGGILAN (data dummy — uncomment untuk testing)
// ─────────────────────────────────────────────────────────────
/*
// ── Test haversineDistance ──
$jarak = haversineDistance(1.4748, 124.8421, 1.4800, 124.8500);
echo "Jarak Wenang ke Wanea: " . round($jarak, 3) . " km\n";

// ── Test nearestNeighbor ──
$titikDummy = [
    ['id' => 1, 'request_code' => 'MRH0001', 'nama_pemohon' => 'Budi',   'lat' => 1.4651, 'lng' => 124.8310],
    ['id' => 2, 'request_code' => 'MRH0002', 'nama_pemohon' => 'Sari',   'lat' => 1.4700, 'lng' => 124.8350],
    ['id' => 3, 'request_code' => 'MRH0003', 'nama_pemohon' => 'Andi',   'lat' => 1.4620, 'lng' => 124.8280],
    ['id' => 4, 'request_code' => 'MRH0004', 'nama_pemohon' => 'Maria',  'lat' => 1.4730, 'lng' => 124.8390],
];
$depot = ['lat' => DEPOT_LAT, 'lng' => DEPOT_LNG];
$rute  = nearestNeighbor($titikDummy, $depot);
echo "\nRute Optimal (Nearest Neighbor):\n";
foreach ($rute as $i => $titik) {
    printf("  %d. %s — %s (%.3f km dari sebelumnya)\n",
        $i + 1,
        $titik['request_code'],
        $titik['nama_pemohon'],
        $titik['distance_from_prev']
    );
}

// ── Test priorityRule ──
$requestsDummy = [
    ['id'=>1,'kecamatan'=>'Sario',  'lat'=>1.465,'lng'=>124.830],
    ['id'=>2,'kecamatan'=>'Wenang', 'lat'=>1.474,'lng'=>124.842],
    ['id'=>3,'kecamatan'=>'Sario',  'lat'=>1.464,'lng'=>124.828],
    ['id'=>4,'kecamatan'=>'Wanea',  'lat'=>1.480,'lng'=>124.850],
    ['id'=>5,'kecamatan'=>'Wenang', 'lat'=>1.476,'lng'=>124.845],
    ['id'=>6,'kecamatan'=>'Sario',  'lat'=>1.466,'lng'=>124.831],
];
$prioritas = priorityRule($requestsDummy);
echo "\nUrutan Prioritas Kecamatan:\n";
foreach ($prioritas as $i => $p) {
    echo "  " . ($i+1) . ". " . $p['kecamatan'] . " — " . $p['count'] . " request\n";
}

// ── Test generateSchedule (butuh koneksi DB aktif) ──
// require_once __DIR__ . '/config.php';
// $result = generateSchedule(getDB(), date('Y-m-d', strtotime('next saturday')));
// print_r($result);
*/
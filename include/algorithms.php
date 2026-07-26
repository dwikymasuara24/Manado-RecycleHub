<?php

if (!defined('DEPOT_LAT'))  define('DEPOT_LAT',  1.476362);
if (!defined('DEPOT_LNG'))  define('DEPOT_LNG',  124.832498);
if (!defined('DEPOT_NAME')) define('DEPOT_NAME', 'Depot MRH — Manado Recycle Hub');

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

function nearestNeighbor(array $points, array $startPoint): array
{
    if (empty($points)) {
        return [];
    }

    $unvisited = $points;
    $visited   = [];

    
    $current = $startPoint;

    while (!empty($unvisited)) {
        $nearestIdx      = null;
        $nearestDist     = PHP_FLOAT_MAX;

        
        foreach ($unvisited as $idx => $point) {
            $lat = (float)($point['lat'] ?? $point['latitude'] ?? 0);
            $lng = (float)($point['lng'] ?? $point['longitude'] ?? 0);

            if ($lat == 0 && $lng == 0) {
                
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

        
        $nearestPoint = $unvisited[$nearestIdx];
        $nearestPoint['distance_from_prev'] = round($nearestDist, 4);

        $visited[]  = $nearestPoint;
        $current    = [
            'lat' => (float)($nearestPoint['lat'] ?? $nearestPoint['latitude'] ?? 0),
            'lng' => (float)($nearestPoint['lng'] ?? $nearestPoint['longitude'] ?? 0),
        ];

        unset($unvisited[$nearestIdx]);
        $unvisited = array_values($unvisited); 
    }

    return $visited;
}

function priorityRule(array $requests): array
{
    if (empty($requests)) {
        return [];
    }

    $remaining = $requests; 
    $result    = [];

    
    while (!empty($remaining)) {

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

        if (empty($grouped)) break; 

        
        
        
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

        $result[] = $best;
        $processed = array_column($best['requests'], 'id');
        $remaining = array_values(array_filter(
            $remaining,
            fn($r) => !in_array($r['id'], $processed)
        ));
    }

    return $result;
}

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

function generateSchedule(PDO $db, string $tanggal, string $tipe_layanan = 'pickup'): array
{
    
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

    foreach ($confirmedRequests as &$r) {
        $r['lat'] = (float)($r['latitude']  ?? 0);
        $r['lng'] = (float)($r['longitude'] ?? 0);
    }
    unset($r);

    
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
            
            $prioritized = priorityRule($remainingRequests);
            if (empty($prioritized)) {
                break;
            }

            
            $mainGroup = $prioritized[0];
            $mainKec = $mainGroup['kecamatan'];
            $scheduleRequests = $mainGroup['requests'];

            $maxPoints = 30; 

            
            
            
            if (false && count($scheduleRequests) < $maxPoints && count($prioritized) > 1) {
                $mainCenter = getKecCenter($mainKec);
                
                
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

                
                usort($otherDistricts, fn($a, $b) => $a['distance'] <=> $b['distance']);

                
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

            
            if (count($scheduleRequests) > $maxPoints) {
                $scheduleRequests = array_slice($scheduleRequests, 0, $maxPoints);
            }

            
            $kecsInSchedule = array_unique(array_map(fn($r) => $r['kecamatan'], $scheduleRequests));
            $kecNamesString = implode(', ', $kecsInSchedule);
            if (strlen($kecNamesString) > 100) {
                $kecNamesString = substr($kecNamesString, 0, 97) . '...';
            }

            
            $scheduleDate = date('Y-m-d', strtotime($tanggal . " + $schedulesCreated days"));

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

            $schedStmt = $db->prepare("
                INSERT INTO schedules (tanggal, kecamatan_id, kecamatan, officer_id, status, tipe_layanan, created_at)
                VALUES (?, ?, ?, ?, 'draft', ?, NOW())
            ");
            $schedStmt->execute([$scheduleDate, $kecId, $kecNamesString, $officerId, $tipe_layanan]);
            $scheduleId = (int)$db->lastInsertId();

            
            $depot = ['lat' => (float)DEPOT_LAT, 'lng' => (float)DEPOT_LNG];
            $route = nearestNeighbor($scheduleRequests, $depot);

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

            
            $routeStmt->execute([$scheduleId, 0, null, 0]);

            foreach ($route as $i => $point) {
                $urutan  = $i + 1;
                $reqId   = (int)$point['id'];
                $jarak   = (float)($point['distance_from_prev'] ?? 0);

                $routeStmt->execute([$scheduleId, $urutan, $reqId, $jarak]);

                
                if ($tipe_layanan === 'cleanup') {
                    $db->prepare("INSERT INTO schedule_requests (schedule_id, cleanup_request_id) VALUES (?, ?)")
                       ->execute([$scheduleId, $reqId]);

                    
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


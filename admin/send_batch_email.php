<?php
/**
 * send_batch_email.php
 * Kirim notifikasi email format [ORDER BARU] untuk 400+ data.
 * Date header diset dari created_at record → waktu di Gmail = waktu asli permintaan.
 */

require_once __DIR__ . '/../include/auth.php';
requireRole('admin');
require_once __DIR__ . '/../include/config.php';

$page_id    = 'send_batch_email';
$page_title = 'Kirim Email Batch';

$db     = getDB();
$action = $_GET['action'] ?? 'preview';

// ── Helper: SMTP batch-safe dengan Date header dari created_at ────
// Menggunakan @fwrite() untuk suppress SSL shutdown warnings saat batch 400+ email.
// Jika SMTP gagal di tahap apapun, langsung fallback ke mail() dengan Date header.
function sendEmailWithOriginalDate(string $to, string $subject, string $body, string $createdAt): bool {
    $fromEmail  = defined('SMTP_FROM') ? SMTP_FROM : 'mdorecyclehub@gmail.com';
    if (strpos($fromEmail, 'manadurecyclehub.id') !== false) $fromEmail = 'mdorecyclehub@gmail.com';

    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $smtpUser = defined('SMTP_USER') ? SMTP_USER : $fromEmail;
    $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : '';

    // Format RFC 2822 dari created_at — ini yang dibaca Gmail sebagai waktu di inbox
    $dateHeader = date('D, d M Y H:i:s +0800', strtotime($createdAt));

    // Fallback headers untuk mail()
    $mailHeaders  = "From: $fromEmail\r\n";
    $mailHeaders .= "Reply-To: $fromEmail\r\n";
    $mailHeaders .= "Date: $dateHeader\r\n";
    $mailHeaders .= "MIME-Version: 1.0\r\n";
    $mailHeaders .= "Content-Type: text/plain; charset=utf-8\r\n";
    $mailHeaders .= "X-Mailer: MRH-Batch/1.0";

    // Jika password kosong/default, langsung pakai mail()
    if (empty($smtpPass) || $smtpPass === 'xxxx xxxx xxxx xxxx') {
        return @mail($to, $subject, $body, $mailHeaders);
    }

    // Coba SMTP dengan semua fwrite() di-suppress (@) agar tidak flood SSL warnings
    try {
        $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
        if (!$socket) return @mail($to, $subject, $body, $mailHeaders);

        $read = function($s) { $r=''; while(($l=@fgets($s,515))!==false){$r.=$l;if(substr($l,3,1)===' ')break;} return $r; };

        $read($socket); // greeting

        @fwrite($socket, "EHLO localhost\r\n"); $read($socket);
        @fwrite($socket, "STARTTLS\r\n");
        $resp = $read($socket);
        if (strpos($resp, '220') === false) { @fclose($socket); return @mail($to, $subject, $body, $mailHeaders); }

        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($socket); return @mail($to, $subject, $body, $mailHeaders);
        }

        @fwrite($socket, "EHLO localhost\r\n"); $read($socket);
        @fwrite($socket, "AUTH LOGIN\r\n");     $read($socket);
        @fwrite($socket, base64_encode($smtpUser) . "\r\n"); $read($socket);
        @fwrite($socket, base64_encode($smtpPass) . "\r\n");
        $auth = $read($socket);
        if (strpos($auth, '235') === false) { @fclose($socket); return @mail($to, $subject, $body, $mailHeaders); }

        @fwrite($socket, "MAIL FROM: <$fromEmail>\r\n"); $read($socket);
        @fwrite($socket, "RCPT TO: <$to>\r\n");          $read($socket);
        @fwrite($socket, "DATA\r\n");                     $read($socket);

        $data  = "To: $to\r\nSubject: $subject\r\n";
        $data .= "Date: $dateHeader\r\n";
        $data .= "From: $fromEmail\r\nReply-To: $fromEmail\r\n";
        $data .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";
        $data .= "X-Mailer: MRH-Batch/1.0\r\n\r\n";
        $data .= $body . "\r\n.\r\n";

        @fwrite($socket, $data);
        $resp = $read($socket);
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return strpos($resp, '250') !== false;

    } catch (Exception $e) {
        return @mail($to, $subject, $body, $mailHeaders);
    }
}


// ── Helper build subject & body pickup (sama persis dengan triggerNewOrderEmail) ──
function buildPickupEmail(PDO $db, int $id): ?array {
    $stmt = $db->prepare("
        SELECT pr.*,
               (SELECT COUNT(*) FROM pickup_request_items pri WHERE pri.pickup_id = pr.id) AS jumlah_titik
        FROM   pickup_requests pr WHERE pr.id = ?
    ");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $kec_opts = [
        'bunaken'=>'Bunaken','bunaken_kepulauan'=>'Bunaken Kepulauan',
        'malalayang'=>'Malalayang','mapanget'=>'Mapanget',
        'paal_dua'=>'Paal Dua','paal_empat'=>'Paal Empat',
        'sario'=>'Sario','singkil'=>'Singkil','tikala'=>'Tikala',
        'tuminting'=>'Tuminting','wanea'=>'Wanea','wenang'=>'Wenang',
    ];
    $kecamatan = $kec_opts[strtolower($order['kecamatan'] ?? '')] ?? ($order['kecamatan'] ?? '-');
    $waktu     = date('d M Y H:i:s', strtotime($order['created_at']));

    $monthsIndo = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
                   7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $ts        = strtotime($order['created_at']);
    $subjectDate = date('j', $ts) . ' ' . ($monthsIndo[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);

    $jumlah_titik = max(1, (int)$order['jumlah_titik']);

    $subject = "[ORDER BARU] Pickup - Kecamatan $kecamatan - $subjectDate";

    $body  = "Halo Admin,\n\nTerdapat order penjemputan baru yang masuk ke sistem:\n";
    $body .= "--------------------------------------------------\n";
    $body .= "ID / Kode Order  : {$order['request_code']}\n";
    $body .= "Nama Pemesan     : {$order['nama_pemohon']}\n";
    $body .= "PIC              : " . ($order['partner_name'] ?? '-') . "\n";
    $body .= "Kecamatan Pickup : $kecamatan\n";
    $body .= "Kelurahan        : " . ($order['kelurahan'] ?? '-') . "\n";
    $body .= "Alamat Jemput    : " . ($order['alamat_jemput'] ?? '-') . "\n";
    $body .= "Tanggal & Jam    : $waktu WITA\n";
    $body .= "Estimasi Berat   : " . ($order['berat_kg'] ?? '-') . " kg\n";
    $body .= "Jumlah Titik     : $jumlah_titik titik\n";
    $body .= "--------------------------------------------------\n\n";
    $body .= "Silakan login ke Admin Console Manado Recycle Hub untuk memproses order ini.\n\nSalam,\nSystem Manado Recycle Hub";

    return ['subject' => $subject, 'body' => $body, 'created_at' => $order['created_at']];
}

// ── Helper build subject & body cleanup (sama persis dengan triggerCleanupOrderEmail) ──
function buildCleanupEmail(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM cleanup_requests WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $kec_opts = [
        'bunaken'=>'Bunaken','bunaken_kepulauan'=>'Bunaken Kepulauan',
        'malalayang'=>'Malalayang','mapanget'=>'Mapanget',
        'paal_dua'=>'Paal Dua','paal_empat'=>'Paal Empat',
        'sario'=>'Sario','singkil'=>'Singkil','tikala'=>'Tikala',
        'tuminting'=>'Tuminting','wanea'=>'Wanea','wenang'=>'Wenang',
    ];
    $cleanup_types = [
        'acara'=>'Bersih-bersih Acara','rumah'=>'Pembersihan Rumah',
        'kantor'=>'Pembersihan Kantor','publik'=>'Area Publik','pemilahan'=>'Pemilahan Plastik',
    ];
    $kecamatan = $kec_opts[strtolower($order['kecamatan'] ?? '')] ?? ($order['kecamatan'] ?? '-');
    $service   = $cleanup_types[strtolower($order['service_type'] ?? '')] ?? ($order['service_type'] ?? '-');
    $waktu     = date('d M Y H:i:s', strtotime($order['created_at']));

    $monthsIndo = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
                   7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $ts        = strtotime($order['created_at']);
    $subjectDate = date('j', $ts) . ' ' . ($monthsIndo[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);

    $subject = "[ORDER BARU] Clean Up - Kecamatan $kecamatan - $subjectDate";

    $body  = "Halo Admin,\n\nTerdapat order Clean Up baru yang masuk ke sistem:\n";
    $body .= "--------------------------------------------------\n";
    $body .= "ID / Kode Order  : {$order['request_code']}\n";
    $body .= "Nama Pemesan     : {$order['nama_pemohon']}\n";
    $body .= "Layanan Clean Up : $service\n";
    $body .= "Kecamatan        : $kecamatan\n";
    $body .= "Kelurahan        : " . ($order['kelurahan'] ?? '-') . "\n";
    $body .= "Alamat Lokasi    : " . ($order['alamat_jemput'] ?? ($order['alamat'] ?? '-')) . "\n";
    $body .= "Tanggal & Jam    : $waktu WITA\n";
    $body .= "Dominan Sampah   : " . ($order['dominant_waste'] ?? '-') . "\n";
    $body .= "--------------------------------------------------\n\n";
    $body .= "Silakan login ke Admin Console Manado Recycle Hub untuk memproses order ini.\n\nSalam,\nSystem Manado Recycle Hub";

    return ['subject' => $subject, 'body' => $body, 'created_at' => $order['created_at']];
}

// ── Ambil jumlah data untuk preview ──────────────────────────────
$totalPickup  = (int)$db->query("SELECT COUNT(*) FROM pickup_requests")->fetchColumn();
$totalCleanup = 0;
try { $totalCleanup = (int)$db->query("SELECT COUNT(*) FROM cleanup_requests")->fetchColumn(); } catch(Exception $e){}
$totalAll = $totalPickup + $totalCleanup;

// ── PREVIEW ───────────────────────────────────────────────────────
if ($action === 'preview') { ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Kirim Email Notifikasi — MRH</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Segoe UI',sans-serif;background:#f0fdf4;color:#1e293b;padding:32px 16px}
        .c{max-width:680px;margin:0 auto}
        .card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 24px rgba(0,0,0,.08);margin-bottom:20px}
        h1{font-size:22px;color:#15803d;margin-bottom:6px}
        .sub{color:#64748b;font-size:13px;margin-bottom:24px}
        .stats{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px}
        .stat{flex:1;min-width:130px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center}
        .stat .n{font-size:34px;font-weight:800;color:#15803d}
        .stat .l{font-size:12px;color:#64748b;margin-top:4px}
        .highlight{background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:14px 18px;font-size:13px;color:#166534;margin-bottom:18px}
        .prev{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;font-size:12px;font-family:monospace;color:#374151;margin-bottom:20px;line-height:1.7}
        .prev .subj{font-weight:700;color:#1e293b;font-size:13px;font-family:sans-serif;margin-bottom:10px}
        .info{background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:14px 18px;font-size:13px;color:#92400e;margin-bottom:20px;line-height:1.6}
        .warn{background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:14px 18px;font-size:13px;color:#991b1b;margin-bottom:20px;line-height:1.6}
        .btn{display:inline-block;padding:13px 30px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;border:none}
        .go{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 4px 12px rgba(22,163,74,.3);margin-right:10px}
        .back{background:#f1f5f9;color:#475569}
    </style>
</head>
<body>
<div class="c">
  <div class="card">
    <h1>📧 Kirim Notifikasi Email ke Gmail Admin</h1>
    <p class="sub">Format email identik dengan notifikasi order baru normal. Waktu di Gmail = waktu asli permintaan di database.</p>
    <div class="stats">
      <div class="stat"><div class="n"><?= $totalPickup ?></div><div class="l">Daur Ulang</div></div>
      <div class="stat"><div class="n"><?= $totalCleanup ?></div><div class="l">Clean Up</div></div>
      <div class="stat"><div class="n"><?= $totalAll ?></div><div class="l">Total Email</div></div>
    </div>
    <div class="highlight">
      ✅ <strong>Perbaikan waktu aktif:</strong> Setiap email akan memiliki header <code>Date:</code> sesuai <code>created_at</code> masing-masing data — Gmail akan menampilkan waktu asli permintaan, bukan waktu batch dikirim.
    </div>
    <p style="font-size:13px;font-weight:700;margin-bottom:10px;color:#374151;">📋 Contoh Format Email:</p>
    <div class="prev">
      <div class="subj">Subject: [ORDER BARU] Pickup - Kecamatan Mapanget - 4 Juli 2026</div>
      <div style="font-size:11px;color:#64748b;margin-bottom:8px;">📅 Ditampilkan di Gmail: <strong>4 Jul, 12.28</strong> (dari created_at data)</div>
      Halo Admin,<br><br>
      Terdapat order penjemputan baru yang masuk ke sistem:<br>
      --------------------------------------------------<br>
      ID / Kode Order &nbsp;&nbsp;: MRH34322926<br>
      Nama Pemesan &nbsp;&nbsp;&nbsp;&nbsp;: Budi Santoso<br>
      Kecamatan Pickup : Mapanget<br>
      Kelurahan &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: Paniki Bawah<br>
      Tanggal &amp; Jam &nbsp;&nbsp;&nbsp;&nbsp;: 30 Apr 2026 11:00:00 WITA<br>
      ...
    </div>
    <div class="info">⏱️ Estimasi waktu: ±<?= ceil($totalAll * 0.3 / 60) ?> menit (jeda 300ms per email)</div>
    <div class="warn">⚠️ Jangan tutup browser selama proses. Cek folder <strong>Promotions/Spam</strong> Gmail jika tidak muncul di Inbox.</div>
    <a href="?action=send" class="btn go">🚀 Kirim <?= $totalAll ?> Email Sekarang</a>
    <a href="req_management.php" class="btn back">← Batal</a>
  </div>
</div>
</body>
</html>
<?php exit; }

// ── SEND ──────────────────────────────────────────────────────────
if ($action === 'send') {
    set_time_limit(0);
    $logFile = PROJECT_ROOT . '/uploads/email_batch_log.txt';
    if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0777, true);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] === MULAI BATCH ===\n", FILE_APPEND);

    // Ambil email admin
    $adminTo = 'mdorecyclehub@gmail.com';
    try {
        $ae = $db->query("SELECT u.email FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin' AND u.is_active=1 LIMIT 1")->fetchColumn();
        if ($ae && strpos($ae, 'manadurecyclehub.id') === false) $adminTo = $ae;
    } catch(Exception $e){}

    $pickupIds  = $db->query("SELECT id FROM pickup_requests ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $cleanupIds = [];
    try { $cleanupIds = $db->query("SELECT id FROM cleanup_requests ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}
    $totalAll = count($pickupIds) + count($cleanupIds);

    if (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);

    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Mengirim Email — MRH</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:"Segoe UI",sans-serif;background:#f0fdf4;padding:24px}
        .c{max-width:800px;margin:0 auto}
        .card{background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 24px rgba(0,0,0,.08);margin-bottom:20px}
        h1{font-size:20px;color:#15803d;margin-bottom:6px}
        .pw{background:#f1f5f9;border-radius:999px;height:14px;overflow:hidden;margin:16px 0 8px}
        #pb{height:100%;background:linear-gradient(90deg,#22c55e,#16a34a);width:0%;transition:width .3s;border-radius:999px}
        #pt{font-size:13px;color:#64748b}
        #log{background:#0f172a;color:#a3e635;font-size:12px;font-family:monospace;padding:16px;border-radius:10px;height:320px;overflow-y:auto;margin-top:16px}
        .ok{color:#4ade80}.err{color:#f87171}.info{color:#60a5fa}
        .done{background:#f0fdf4;border:2px solid #86efac;border-radius:12px;padding:20px;margin-top:20px}
        .big{font-size:28px;font-weight:800;color:#15803d}
        .btn{display:inline-block;margin-top:16px;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;background:#16a34a;color:#fff}
    </style>
    <script>function sl(){var l=document.getElementById("log");if(l)l.scrollTop=l.scrollHeight;}</script>
    </head><body><div class="c"><div class="card">
    <h1>📧 Mengirim Email Notifikasi...</h1>
    <p style="font-size:13px;color:#64748b;margin-bottom:4px">Waktu email = waktu asli permintaan di database. Jangan tutup halaman ini.</p>
    <div class="pw"><div id="pb"></div></div>
    <div id="pt">Memulai...</div>
    <div id="log">Memulai...<br></div>
    </div></div>';
    echo str_pad('', 4096);

    $sent = 0; $failed = 0; $no = 0;

    foreach ($pickupIds as $id) {
        $no++;
        $data = buildPickupEmail($db, (int)$id);
        if ($data) {
            sendEmailWithOriginalDate($adminTo, $data['subject'], $data['body'], $data['created_at']);
            $sent++;
            $dateShow = date('d M Y H:i', strtotime($data['created_at']));
            $log = "<span class='ok'>✅ [{$no}/{$totalAll}] PICKUP ID:{$id} — {$dateShow} — OK</span><br>";
            file_put_contents($logFile, "[OK] PICKUP ID:$id created:{$data['created_at']}\n", FILE_APPEND);
        } else {
            $failed++;
            $log = "<span class='err'>❌ [{$no}/{$totalAll}] PICKUP ID:{$id} — data tidak ditemukan</span><br>";
        }
        $pct = round($no / $totalAll * 100);
        echo "<script>document.getElementById('pb').style.width='{$pct}%';document.getElementById('pt').textContent='Terkirim: {$sent} | Gagal: {$failed} | {$pct}%';document.getElementById('log').innerHTML+='" . addslashes($log) . "';sl();</script>";
        echo str_pad('', 256);
        usleep(300000);
    }

    foreach ($cleanupIds as $id) {
        $no++;
        $data = buildCleanupEmail($db, (int)$id);
        if ($data) {
            sendEmailWithOriginalDate($adminTo, $data['subject'], $data['body'], $data['created_at']);
            $sent++;
            $dateShow = date('d M Y H:i', strtotime($data['created_at']));
            $log = "<span class='ok'>✅ [{$no}/{$totalAll}] CLEANUP ID:{$id} — {$dateShow} — OK</span><br>";
            file_put_contents($logFile, "[OK] CLEANUP ID:$id created:{$data['created_at']}\n", FILE_APPEND);
        } else {
            $failed++;
            $log = "<span class='err'>❌ [{$no}/{$totalAll}] CLEANUP ID:{$id} — data tidak ditemukan</span><br>";
        }
        $pct = round($no / $totalAll * 100);
        echo "<script>document.getElementById('pb').style.width='{$pct}%';document.getElementById('pt').textContent='Terkirim: {$sent} | Gagal: {$failed} | {$pct}%';document.getElementById('log').innerHTML+='" . addslashes($log) . "';sl();</script>";
        echo str_pad('', 256);
        usleep(300000);
    }

    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] === SELESAI: Sent={$sent} Failed={$failed} ===\n\n", FILE_APPEND);

    echo "<script>document.getElementById('pb').style.width='100%';document.getElementById('pt').textContent='✅ Selesai! Terkirim: {$sent} | Gagal: {$failed}';document.getElementById('log').innerHTML+=\"<br><span class='info'>══ SELESAI — {$sent}/{$totalAll} email terkirim ══</span>\";sl();</script>";
    echo '<div class="c"><div class="card"><div class="done">
        <div style="font-size:14px;font-weight:700;color:#166534;margin-bottom:6px;">🎉 Selesai!</div>
        <div>Total terkirim: <span class="big">' . $sent . '</span></div>
        <div style="margin-top:6px;font-size:13px;color:#64748b;">Waktu di Gmail = waktu asli tiap permintaan. Log: <code>uploads/email_batch_log.txt</code></div>
        </div>
        <a href="req_management.php" class="btn">← Kembali ke Admin Panel</a>
    </div></div></body></html>';
    exit;
}
?>

<?php
// ============================================================
//  forgot_password.php — Halaman Permintaan Reset Password
// ============================================================
require_once __DIR__ . '/include/config.php';

$error = '';
$success_msg = '';
$dev_reset_link = ''; // Tautan bantuan untuk localhost

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Silakan masukkan alamat email Anda.';
    } else {
        $db = getDB();
        
        try {
            // 1. Migrasi otomatis kolom token jika belum ada
            $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetch();
            if (!$checkCol) {
                $db->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
                $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL");
            }
            
            // 2. Cari user berdasarkan email
            $stmt = $db->prepare("SELECT id, nama FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                // 3. Generate token unik & waktu kedaluwarsa (1 jam)
                $token = bin2hex(random_bytes(16));
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // 4. Update data token di database
                $update = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?");
                $update->execute([$token, $expires_at, $user['id']]);
                
                // 5. Buat tautan reset password
                // Mendapatkan base URL dinamis
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $uri = $_SERVER['REQUEST_URI'];
                $dir = dirname($uri);
                $reset_link = $protocol . $host . rtrim($dir, '/\\') . '/reset_password.php?token=' . $token;
                
                // 6. Siapkan isi email
                $subject = "[MRH] Permintaan Reset Password";
                $body = "Halo " . $user['nama'] . ",\n\n";
                $body .= "Kami menerima permintaan untuk mereset password akun Anda di Manado Recycle Hub.\n";
                $body .= "Silakan klik tautan di bawah ini untuk mengatur ulang password Anda:\n";
                $body .= "$reset_link\n\n";
                $body .= "Tautan ini hanya berlaku selama 1 jam dari sekarang.\n";
                $body .= "Jika Anda tidak meminta pengaturan ulang ini, silakan abaikan email ini.\n\n";
                $body .= "Salam,\nTim Manado Recycle Hub";
                
                $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : "mdorecyclehub@gmail.com";
                if (strpos($fromEmail, 'manadurecyclehub.id') !== false) {
                    $fromEmail = "mdorecyclehub@gmail.com";
                }
                $headers = "From: $fromEmail\r\n";
                $headers .= "Reply-To: $fromEmail\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();
                
                // Kirim email
                sendRealEmailViaSMTP($email, $subject, $body, $headers);
                
                // Catat ke file log email tanpa menuliskan link reset
                $logFile = PROJECT_ROOT . '/uploads/email_logs.txt';
                $logEntry = "[" . date('Y-m-d H:i:s') . "] PASSWORD RESET EMAIL SENT TO: $email\n\n";
                file_put_contents($logFile, $logEntry, FILE_APPEND);
                
                $success_msg = 'Jika email terdaftar, tautan reset password akan dikirim.';
                
                // Deteksi localhost untuk membatasi tampilan pintasan link reset
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $is_localhost = in_array($host, ['localhost', '127.0.0.1', '[::1]']) 
                    || (strpos($host, 'localhost:') === 0) 
                    || (strpos($host, '127.0.0.1:') === 0);
                
                if ($is_localhost) {
                    $dev_reset_link = $reset_link; // Untuk kemudahan testing di localhost
                } else {
                    $dev_reset_link = ''; // Sembunyikan sepenuhnya di server produksi / hosting
                }
            } else {
                $success_msg = 'Jika email terdaftar, tautan reset password akan dikirim.';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password — <?= SITE_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-primary: #1c6434;
            --green-light: #22c55e;
            --bg-color: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 460px;
            padding: 20px;
        }

        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            padding: 40px;
            border: 1px solid #e2e8f0;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo {
            width: 48px;
            height: 48px;
            background: var(--green-primary);
            color: white;
            font-family: 'Comfortaa', sans-serif;
            font-size: 24px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .desc {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #1e293b;
            transition: all 0.2s;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--green-light);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
        }

        .btn-submit {
            width: 100%;
            padding: 12px 20px;
            background: var(--green-primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-submit:hover {
            background: #144f29;
            transform: translateY(-1px);
        }

        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 24px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: var(--green-primary);
        }

        /* Centered Overlay Styles */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }

        .modal-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            padding: 30px;
            width: 90%;
            max-width: 440px;
            text-align: center;
        }

        .modal-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
        }

        .modal-desc {
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .dev-box {
            background: #f1f5f9;
            border: 1.5px dashed #cbd5e1;
            padding: 12px;
            border-radius: 10px;
            font-size: 11px;
            text-align: left;
            margin-bottom: 20px;
            word-break: break-all;
        }

        .btn-close {
            padding: 10px 24px;
            background: var(--green-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div class="logo">M</div>
            <h1 class="title">Lupa Password</h1>
            <p class="desc">Masukkan email Anda untuk menerima tautan pengaturan ulang password.</p>
        </div>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="email">Alamat Email Terdaftar</label>
                <input class="form-input" type="email" id="email" name="email" placeholder="contoh@email.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <button type="submit" class="btn-submit">Kirim Tautan Reset</button>

            <a href="login.php" class="back-link">
                <span>←</span> Kembali ke Halaman Login
            </a>
        </form>
    </div>
</div>

<!-- Modal Dialog Error/Success Centered -->
<?php if ($error || $success_msg): ?>
    <div class="overlay" id="infoOverlay">
        <div class="modal-card">
            <?php if ($error): ?>
                <div class="modal-icon">⚠️</div>
                <h3 class="modal-title" style="color: #dc2626;">Kesalahan</h3>
                <p class="modal-desc"><?= htmlspecialchars($error) ?></p>
                <button type="button" class="btn-close" style="background:#ef4444;" onclick="document.getElementById('infoOverlay').style.display='none'">Tutup</button>
            <?php else: ?>
                <div class="modal-icon">✉️</div>
                <h3 class="modal-title" style="color: #16a34a;">Email Terkirim!</h3>
                <p class="modal-desc"><?= htmlspecialchars($success_msg) ?></p>
                
                <?php if ($dev_reset_link): ?>
                    <div class="dev-box">
                        <strong style="color: #475569; display:block; margin-bottom:4px;">💻 PINTASAN LOCALHOST (DEVELOPMENT):</strong>
                        Karena berada di server lokal, Anda dapat langsung mengklik tautan di bawah ini untuk mereset password:<br><br>
                        <a href="<?= $dev_reset_link ?>" style="color:var(--green-primary); font-weight:700; text-decoration:underline;"><?= $dev_reset_link ?></a>
                    </div>
                <?php endif; ?>
                
                <a href="login.php" class="btn-close" style="text-decoration:none; display:inline-block;">Ke Halaman Login</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</body>
</html>

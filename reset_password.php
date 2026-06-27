<?php
// ============================================================
//  reset_password.php — Halaman Pengaturan Ulang Password Baru
// ============================================================
require_once __DIR__ . '/include/config.php';

$error = '';
$success = false;
$token = trim($_GET['token'] ?? '');
$user_id = 0;

if (empty($token)) {
    $error = 'Token reset password tidak valid atau tidak disertakan.';
} else {
    $db = getDB();
    
    try {
        // Cari user yang memiliki token tersebut dan belum expired
        $stmt = $db->prepare("
            SELECT id, nama, reset_token_expires_at 
            FROM   users 
            WHERE  reset_token = ? AND is_active = 1 
            LIMIT  1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $error = 'Tautan reset password tidak valid atau telah kedaluwarsa.';
        } else {
            // Periksa kedaluwarsa token
            $expires = strtotime($user['reset_token_expires_at']);
            if (time() > $expires) {
                $error = 'Tautan reset password tidak valid atau telah kedaluwarsa.';
            } else {
                $user_id = $user['id'];
            }
        }
    } catch (Exception $e) {
        $error = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
    }
}

// Penanganan Form Submit Password Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id > 0) {
    $pass = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    if (empty($pass) || empty($confirm_pass)) {
        $error = 'Silakan isi kedua kolom password.';
    } elseif (strlen($pass) < 6) {
        $error = 'Password minimal harus terdiri dari 6 karakter.';
    } elseif ($pass !== $confirm_pass) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        try {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            
            // Simpan password baru dan bersihkan token reset
            $update = $db->prepare("
                UPDATE users 
                SET    password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL 
                WHERE  id = ?
            ");
            $update->execute([$hash, $user_id]);
            
            // Set flash message sukses
            if (session_status() === PHP_SESSION_NONE) session_start();
            flash('success', 'Password Anda berhasil diperbarui! Silakan login menggunakan password baru Anda.');
            $success = true;
        } catch (Exception $e) {
            $error = 'Gagal menyimpan password baru. Silakan coba lagi nanti.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Password — <?= SITE_NAME ?></title>
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
            max-width: 420px;
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

        .btn-close {
            padding: 10px 24px;
            background: var(--green-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <div class="header">
            <div class="logo">M</div>
            <h1 class="title">Password Baru</h1>
            <p class="desc">Silakan buat password baru yang aman untuk akun Anda.</p>
        </div>

        <?php if ($user_id > 0 && !$success): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="password">Password Baru (min. 6 karakter)</label>
                    <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Konfirmasi Password Baru</label>
                    <input class="form-input" type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn-submit">Ubah Password</button>
            </form>
        <?php else: ?>
            <div style="text-align:center;">
                <p style="color:#64748b; font-size:14px; margin-bottom:20px;">Tautan reset tidak dapat digunakan.</p>
                <a href="login.php" class="btn-submit" style="text-decoration:none; display:block;">Ke Halaman Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Dialog Error/Success Centered -->
<?php if ($error || $success): ?>
    <div class="overlay" id="infoOverlay">
        <div class="modal-card">
            <?php if ($error): ?>
                <div class="modal-icon">⚠️</div>
                <h3 class="modal-title" style="color: #dc2626;">Kesalahan</h3>
                <p class="modal-desc"><?= htmlspecialchars($error) ?></p>
                <?php if ($user_id > 0): ?>
                    <button type="button" class="btn-close" style="background:#ef4444;" onclick="document.getElementById('infoOverlay').style.display='none'">Tutup</button>
                <?php else: ?>
                    <a href="forgot_password.php" class="btn-close" style="background:#475569;">Kembali</a>
                <?php endif; ?>
            <?php else: ?>
                <div class="modal-icon">🎉</div>
                <h3 class="modal-title" style="color: #16a34a;">Password Diperbarui!</h3>
                <p class="modal-desc">Selamat, password Anda berhasil diubah. Silakan masuk menggunakan password baru Anda.</p>
                <a href="login.php" class="btn-close">Masuk Sekarang</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

</body>
</html>

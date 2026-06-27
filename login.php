<?php
require_once __DIR__ . '/include/auth.php';

// Jika sudah login, langsung arahkan ke dashboard masing-masing
if (isLoggedIn()) {
    $role = currentUserRole();
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($role === 'officer') {
        header('Location: officer/officer_console.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

$error = '';
$_flash = getFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (empty($email) || empty($pass)) {
        $error = 'Email dan password wajib diisi.';
    } else {
        $db = getDB();
        if (attemptLogin($db, $email, $pass)) {
            flash('success', 'Selamat datang kembali, ' . $_SESSION['user_nama'] . '!');
            
            // Redirect sesuai role
            $role = currentUserRole();
            if ($role === 'admin') {
                header('Location: admin/dashboard.php');
            } elseif ($role === 'officer') {
                header('Location: officer/officer_console.php');
            } else {
                header('Location: index.php');
            }
            exit;
        } else {
            $error = 'Email atau password salah, atau akun tidak aktif.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= SITE_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green-primary: #1c6434;
            --green-light: #22c55e;
            --bg-color: #f8fafc;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-color);
            color: #1e293b;
            display: flex;
            min-height: 100vh;
        }
        
        /* Layout Split */
        .split-layout {
            display: flex;
            width: 100%;
        }
        
        .left-side {
            flex: 1;
            background: linear-gradient(135deg, rgba(28,100,52,0.9), rgba(34,197,94,0.8)), url('https://images.unsplash.com/photo-1532996122724-e3c354a0b15b?ixlib=rb-4.0.3&auto=format&fit=crop&w=1600&q=80') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            color: white;
            position: relative;
        }
        
        .left-side::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.2);
            z-index: 1;
        }
        
        .brand-content {
            z-index: 2;
            text-align: center;
            max-width: 480px;
        }
        
        .brand-logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 800;
            color: var(--green-primary);
            margin: 0 auto 24px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        
        .brand-title {
            font-family: 'Comfortaa', cursive;
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 16px;
            line-height: 1.2;
        }
        
        .brand-desc {
            font-size: 16px;
            opacity: 0.9;
            line-height: 1.6;
        }

        .right-side {
            width: 100%;
            max-width: 500px;
            background: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px;
            box-shadow: -10px 0 30px rgba(0,0,0,0.05);
            z-index: 10;
        }

        .login-header {
            margin-bottom: 32px;
        }

        .login-header h2 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #64748b;
            font-size: 14px;
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
            padding: 14px 16px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            outline: none;
        }

        .form-input:focus {
            border-color: var(--green-light);
            background: white;
            box-shadow: 0 0 0 4px rgba(34,197,94,0.1);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--green-primary);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 10px;
            font-family: 'Inter', sans-serif;
        }

        .btn-login:hover {
            background: #144f29;
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(28,100,52,0.2);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }

        /* ── Centered Flash Notification Overlay Style ── */
        .flash-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: alert-fade-in 0.25s ease-out;
        }
        .flash {
            background: #ffffff !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
            padding: 24px 32px !important;
            max-width: 400px;
            width: 90%;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 16px;
            border: none !important;
            animation: alert-scale-in 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            font-family: inherit;
        }
        .flash-icon {
            font-size: 48px;
            line-height: 1;
        }
        .flash-msg {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.5;
        }
        .flash-close-btn {
            background: #1c6434;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 24px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: background 0.15s;
        }
        .flash-close-btn:hover {
            background: #166534;
        }
        
        @keyframes alert-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes alert-scale-in {
            from { transform: scale(0.9) translateY(10px); }
            to { transform: scale(1) translateY(0); }
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            margin-top: 32px;
            transition: color 0.2s;
        }
        
        .back-link:hover {
            color: var(--green-primary);
        }

        .role-hint {
            margin-top: 24px;
            padding: 16px;
            background: #f1f5f9;
            border-radius: 12px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }

        @media (max-width: 860px) {
            .left-side { display: none; }
            .right-side { max-width: 100%; align-items: center; }
            form { width: 100%; max-width: 400px; }
            .login-header { text-align: center; }
            .role-hint { max-width: 400px; margin: 24px auto 0; }
            .back-link { display: flex; justify-content: center; }
        }

        /* Password Show/Hide Styles */
        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            outline: none;
            transition: color 0.2s;
        }
        .password-toggle-btn:hover {
            color: var(--green-primary);
        }
        .eye-icon {
            width: 20px;
            height: 20px;
            pointer-events: none;
        }
        .password-toggle-btn .eye-slash {
            display: none;
        }
        .password-toggle-btn.show-password .eye-open {
            display: none;
        }
        .password-toggle-btn.show-password .eye-slash {
            display: block;
        }
    </style>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1c6434">
</head>
<body>

<div class="split-layout">
    <div class="left-side">
        <div class="brand-content">
            <div class="brand-logo">M</div>
            <h1 class="brand-title"><?= SITE_NAME ?></h1>
            <p class="brand-desc">Platform digital terintegrasi untuk pengelolaan sampah daur ulang di kota Manado. Bersama wujudkan lingkungan yang lebih bersih dan hijau.</p>
        </div>
    </div>
    
    <div class="right-side">
        <form method="POST" action="">
            <div class="login-header">
                <h2>Selamat Datang</h2>
                <p>Silakan masuk ke akun Anda</p>
            </div>

            <?php if ($error || $_flash): 
                $alert_type = $error ? 'danger' : $_flash['type'];
                $alert_msg = $error ? $error : $_flash['msg'];
                $alert_icon = ($alert_type === 'success') ? '✅' : (($alert_type === 'danger') ? '⚠️' : 'ℹ️');
            ?>
                <div class="flash-overlay" id="flashOverlay">
                    <div class="flash flash-<?= htmlspecialchars($alert_type) ?>">
                        <div class="flash-icon"><?= $alert_icon ?></div>
                        <div class="flash-msg"><?= htmlspecialchars($alert_msg) ?></div>
                        <button type="button" class="flash-close-btn" onclick="document.getElementById('flashOverlay').style.display='none'">Tutup</button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label" for="email">Alamat Email</label>
                <input class="form-input" type="email" id="email" name="email" placeholder="contoh@email.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label class="form-label" for="password" style="margin-bottom:0;">Password</label>
                    <a href="forgot_password.php" style="font-size:12px; color:var(--green-primary); text-decoration:none; font-weight:700;">Lupa Password?</a>
                </div>
                <div style="position: relative;">
                    <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" required style="padding-right: 46px;">
                    <button type="button" id="togglePassword" class="password-toggle-btn" onclick="togglePasswordVisibility('password', 'togglePassword')">
                        <!-- Eye Open SVG -->
                        <svg class="eye-icon eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <!-- Eye Slash SVG -->
                        <svg class="eye-icon eye-slash" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-login">Masuk</button>
            
            <div class="role-hint">
                <strong>💡 Info Sistem:</strong><br>
                Sistem akan secara otomatis mendeteksi apakah Anda <strong>Admin</strong> atau <strong>Petugas Lapangan</strong> berdasarkan akun email yang Anda gunakan.
            </div>

            <div style="margin-top:16px;text-align:center">
                <span style="font-size:13px;color:#94a3b8">Belum punya akun Admin / Petugas?</span><br>
                <a href="register.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:10px 20px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;color:#475569;text-decoration:none;transition:all .2s"
                   onmouseover="this.style.borderColor='#86efac';this.style.color='#1c6434'"
                   onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
                    ✍️ Register
                </a>
            </div>
        </form>
    </div>
</div>

<button id="pwaInstallBtn" style="display:none; position:fixed; bottom:20px; left:20px; z-index:9999; background:#1c6434; color:white; border:none; padding:12px 20px; border-radius:30px; font-weight:bold; box-shadow:0 4px 15px rgba(0,0,0,0.2); cursor:pointer; font-size:13px; align-items:center; gap:8px; font-family:'Inter', sans-serif;">
  📲 Download dan Install Aplikasi
</button>

<script>
function togglePasswordVisibility(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.classList.add('show-password');
    } else {
        input.type = 'password';
        btn.classList.remove('show-password');
    }
}

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./service-worker.js')
      .then(reg => console.log('Service Worker registered successfully:', reg.scope))
      .catch(err => console.error('Service Worker registration failed:', err));
  });
}

let deferredPrompt;
const installBtn = document.getElementById('pwaInstallBtn');

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  installBtn.style.display = 'inline-flex';
});

// Jika tidak diprompt otomatis karena masalah protokol HTTP (bukan HTTPS / localhost)
setTimeout(() => {
  if (!deferredPrompt && installBtn) {
    const isLocal = ['localhost', '127.0.0.1'].includes(window.location.hostname);
    const isHttps = window.location.protocol === 'https:';
    if (!isHttps && !isLocal) {
      installBtn.style.display = 'inline-flex';
      installBtn.style.opacity = '0.6';
      installBtn.style.background = '#64748b'; // Gray out
      installBtn.innerText = '📲 Info Instalasi PWA';
      installBtn.addEventListener('click', (e) => {
        e.preventDefault();
        alert('Info Instalasi Aplikasi:\n\nAplikasi dapat diunduh seperti aplikasi HP jika diakses melalui koneksi aman (HTTPS) atau di localhost.\n\nSaat ini Anda mengakses menggunakan HTTP biasa tanpa SSL.');
      });
    }
  }
}, 3000);

installBtn.addEventListener('click', () => {
  if (!deferredPrompt) return;
  installBtn.style.display = 'none';
  deferredPrompt.prompt();
  deferredPrompt.userChoice.then((choiceResult) => {
    if (choiceResult.outcome === 'accepted') {
      console.log('User accepted the install prompt');
    }
    deferredPrompt = null;
  });
});
</script>

</body>
</html>

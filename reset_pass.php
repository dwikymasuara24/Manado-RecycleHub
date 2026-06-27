<?php
// ============================================================
//  reset_pass.php — Alat Bantu Reset Password Admin / User
//  ⚠️ PENTING: Segera hapus atau kosongkan file ini setelah digunakan!
// ============================================================
require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

// Silakan sesuaikan email dan password baru di bawah ini:
$email = 'admin@admin.com'; // Ganti dengan email akun yang ingin direset
$password_baru = 'admin123'; // Password baru yang diinginkan

$hash = password_hash($password_baru, PASSWORD_BCRYPT);

try {
    $db = getDB();
    
    // Periksa apakah user ada
    $check = $db->prepare("SELECT id, nama FROM users WHERE email = ?");
    $check->execute([$email]);
    $user = $check->fetch();
    
    if ($user) {
        // Lakukan update password_hash
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hash, $email]);
        
        echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; border: 1.5px solid #bbf7d0; background: #f0fdf4; border-radius: 8px;'>";
        echo "<h3 style='color: #16a34a; margin-top: 0;'>✅ Sukses Mereset Password!</h3>";
        echo "<p>Akun <b>" . htmlspecialchars($user['nama']) . "</b> ($email) telah diperbarui.</p>";
        echo "<p>Password baru Anda sekarang: <code style='background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold;'>$password_baru</code></p>";
        echo "<hr style='border: 0; border-top: 1px solid #cbd5e1; margin: 20px 0;'>";
        echo "<p style='color: #ef4444; font-size: 13px; font-weight: bold;'>⚠️ PENTING: Demi alasan keamanan, segera hapus atau kosongkan kembali isi file <u>reset_pass.php</u> dari folder proyek Anda agar tidak disalahgunakan orang lain!</p>";
        echo "</div>";
    } else {
        echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; border: 1.5px solid #fecaca; background: #fef2f2; border-radius: 8px;'>";
        echo "<h3 style='color: #dc2626; margin-top: 0;'>❌ Akun Tidak Ditemukan!</h3>";
        echo "<p>Tidak ada pengguna dengan email <b>$email</b> di database.</p>";
        echo "<p>Silakan edit file <code>reset_pass.php</code> dan ganti variabel <code>\$email</code> dengan email yang terdaftar di database Anda.</p>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; border: 1.5px solid #cbd5e1; background: #f8fafc; border-radius: 8px;'>";
    echo "<h3 style='color: #64748b; margin-top: 0;'>❌ Terjadi Kesalahan Database</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

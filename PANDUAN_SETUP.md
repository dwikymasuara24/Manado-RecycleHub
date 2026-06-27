# 🚀 Panduan Setup — Manado Recycle Hub (Update)

## 1. Jalankan Migration SQL
Jalankan file `migration_geo.sql` di phpMyAdmin atau MySQL CLI:
```sql
SOURCE migration_geo.sql;
```
Ini menambah kolom `latitude`, `longitude`, `place_id`, `formatted_address` ke tabel `pickup_requests`.

---

## 2. Konfigurasi Google Maps API Key

### Cara Mendapatkan API Key:
1. Buka [Google Cloud Console](https://console.cloud.google.com/)
2. Buat project baru atau pilih project existing
3. Enable APIs berikut:
   - **Maps JavaScript API**
   - **Places API**
   - **Geocoding API**
   - **Directions API**
4. Buat API Key di **Credentials**
5. Restrict API key ke domain Anda (untuk keamanan)

### Cara Memasukkan ke Sistem:
1. Buka `settings.php` di admin panel
2. Cari field **Google Maps API Key** (sudah ditambah otomatis)
3. Paste API Key
4. Klik Simpan

---

## 3. File yang Diperbarui

| File | Perubahan |
|------|-----------|
| `dashboard.php` | Chart.js interactive, live stats polling 60 detik, 5 chart visualisasi |
| `req_management.php` | Google Maps Places Autocomplete di form, simpan lat/lng otomatis |
| `rute_jadwal.php` | Google Maps real (Directions API), geocoding batch, assign officer AJAX |
| `officer_console.php` | **BARU** — Konsol mobile-first untuk petugas lapangan |
| `migration_geo.sql` | Migration kolom geografi ke pickup_requests |

---

## 4. Officer Console

File baru: `officer_console.php`

**Fitur:**
- 📋 Tugas hari ini dengan detail lengkap
- 🗺️ Peta dengan Google Maps (jika API key dikonfigurasi)
- 📞 Tombol telepon & WhatsApp langsung
- 🧭 Navigasi Google Maps ke lokasi pemohon
- ✏️ Update status pickup (modal bottom sheet)
- ⚖️ Input berat aktual sampah saat selesai
- 📍 GPS tracking & kirim lokasi ke server
- 📊 Statistik personal petugas

**Akses:** `officer/officer_console.php` (Akan secara otomatis diarahkan setelah login aman sebagai akun Petugas)

**Status Integrasi:** Telah terintegrasi sepenuhnya dengan sistem login aman — data dan ID petugas diambil secara aman dari `$_SESSION['officer_id']` setelah login, sehingga tidak bisa dimanipulasi melalui parameter URL.

---

## 5. Settings.php — Tambahan

Tambahkan field ini ke tabel `site_settings` (sudah di migration_geo.sql):
```sql
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('google_maps_api_key', '');
```

Lalu di `settings.php`, tambahkan form field untuk API key di section **Info Situs**.

---

## 6. Fitur Mendatang (Prioritas Berikutnya)

- 🔔 Notifikasi real-time (WebSocket atau SSE)
- 📱 PWA untuk officer console (installable di HP)
- 📊 Export laporan ke PDF/Excel
- 🔐 Login terpisah untuk officer (bukan admin)
- 🗺️ Heatmap permintaan per kelurahan
- 📈 Prediksi demand berbasis tren historis


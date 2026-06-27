# 📋 Manado Recycle Hub — Penjelasan Sistem
> Dokumen ini menjelaskan cara kerja sistem, revisi yang sudah dilakukan, alur status pesanan, dan cara kerja algoritma secara sederhana.

---

## 1. Apa yang Sudah Direvisi?

| # | Poin Revisi | Status |
|---|---|---|
| 1 | Form request dijadikan satu halaman (tidak lagi step-by-step) | ✅ Sudah |
| 2 | Tabel pesanan dipisah: **Aktif** dan **Selesai** | ✅ Sudah |
| 3 | Alur status pesanan diperjelas | ✅ Sudah |
| 4 | Tampilan responsif (mobile & desktop) | ✅ Sudah |
| 5 | Algoritma Priority Rule dan Nearest Neighbor diimplementasikan | ✅ Sudah |

---

## 2. Cara Kerja Sistem (Gambaran Besar)

```
WARGA               ADMIN                 SISTEM/ALGORITMA      PETUGAS
  │                   │                         │                  │
  │── Buat request ──►│                         │                  │
  │   (1 halaman,     │                         │                  │
  │    isi semua)     │── Verifikasi ──────────►│                  │
  │                   │   (cek data)            │── Jalankan ─────►│
  │                   │                         │   Priority Rule  │
  │                   │                         │   + Nearest      │
  │                   │                         │   Neighbor       │
  │                   │                         │                  │
  │◄── Notif status ──│◄────────────────────────│◄─ Update status ─│
```

**Intinya:** Warga minta → Admin cek → Algoritma atur jadwal & rute → Petugas jalan.

---

## 3. Alur Status Pesanan

```
[Menunggu] ──► [Dikonfirmasi] ──► [Dijadwalkan] ──► [Sedang Diproses] ──► [Selesai]
               ▲                  ▲
               │                  │
           ADMIN                ALGORITMA
           (verifikasi          (atur jadwal
            data warga)          otomatis)
```

### Penjelasan tiap status:

| Status | Siapa yang mengubah | Artinya |
|---|---|---|
| **Menunggu** | Sistem (otomatis) | Request baru masuk, belum diproses |
| **Dikonfirmasi** | Admin | Admin sudah cek data, request valid |
| **Dijadwalkan** | Algoritma (otomatis) | Sistem sudah tentukan kapan dan siapa yang jemput |
| **Sedang Diproses** | Petugas | Petugas sedang dalam perjalanan/menjemput |
| **Selesai** | Petugas | Sampah sudah dijemput, tugas selesai |

> **Catatan penting:** Admin **tidak menentukan jadwal**. Admin hanya memverifikasi bahwa data request valid. Jadwal ditentukan sepenuhnya oleh algoritma.

---

## 4. Form Request — Satu Halaman

**Dulu (step-by-step):**
```
Langkah 1: Isi data pribadi → NEXT
Langkah 2: Pilih jenis sampah → NEXT
Langkah 3: Pilih lokasi → NEXT
Langkah 4: Konfirmasi → KIRIM
```

**Sekarang (satu halaman):**
```
┌─────────────────────────────────────────┐
│ Data Pribadi    │ Jenis Sampah           │
│ ─────────────── │ ────────────────────── │
│ Nama:  [    ]   │ ☐ Plastik              │
│ WA:    [    ]   │ ☐ Kertas               │
│                 │ ☐ Kaca                 │
│ Lokasi Penjemputan                       │
│ [Klik peta atau isi alamat]              │
│                                          │
│              [KIRIM REQUEST]             │
└─────────────────────────────────────────┘
```

Semua informasi langsung terlihat → lebih cepat, tidak perlu bolak-balik.

---

## 5. Tabel Pesanan Dipisah

**Dulu:** 1 tabel berisi semua pesanan (aktif + selesai bercampur).

**Sekarang:**
- **Tabel Aktif** → hanya pesanan berstatus: Menunggu, Dikonfirmasi, Dijadwalkan, Sedang Diproses
- **Tabel Selesai** → hanya pesanan berstatus: Selesai atau Dibatalkan

Saat petugas mengubah status menjadi "Selesai", pesanan **otomatis hilang dari tabel aktif** dan muncul di tabel selesai.

---

## 6. Tampilan Responsif

| Dibuka di | Tampilan |
|---|---|
| **Laptop/Desktop** | Sidebar di kiri, konten melebar ke kanan (landscape) |
| **HP/Tablet** | Sidebar disembunyikan (hamburger menu), konten penuh layar |

Sistem menggunakan CSS `@media` query — otomatis menyesuaikan tanpa pengaturan manual.

---

## 7. Cara Kerja Algoritma

### 7A. Priority Rule — "Siapa yang Dilayani Duluan?"

**Analogi:** Bayangkan ada 3 kecamatan yang minta dijemput:
- Kecamatan Wenang: **8 request**
- Kecamatan Tikala: **3 request**
- Kecamatan Sario: **1 request**

**Priority Rule** bilang: layani kecamatan dengan request **terbanyak** dulu.

```
Urutan prioritas:
1. Wenang  (8 request) ← dilayani PERTAMA
2. Tikala  (3 request) ← dilayani KEDUA
3. Sario   (1 request) ← dilayani KETIGA
```

**Logikanya:** Kecamatan yang banyak request → banyak warga menunggu → harus cepat dilayani.

**Fleksibel:** Kalau besok ada kecamatan baru (misal Mapanget), sistem langsung hitung otomatis. Tidak perlu ubah kode.

---

### 7B. Nearest Neighbor — "Rute Paling Hemat"

**Analogi:** Petugas mulai dari **Depot** (gudang) dan harus menjemput di 4 titik lokasi.

```
Depot → [A, B, C, D] → kembali ke Depot
```

**Cara kerja langkah demi langkah:**

```
Step 1: Petugas di Depot. Hitung jarak ke semua titik.
        Depot → A = 2 km  ← TERDEKAT, pilih A
        Depot → B = 5 km
        Depot → C = 8 km
        Depot → D = 6 km

Step 2: Petugas di A. Hitung jarak ke titik yang belum dikunjungi.
        A → B = 3 km  ← TERDEKAT, pilih B
        A → C = 7 km
        A → D = 5 km

Step 3: Petugas di B. Hitung jarak ke titik yang belum dikunjungi.
        B → C = 4 km
        B → D = 6 km  → pilih C (terdekat)

Step 4: Petugas di C. Sisa hanya D.
        C → D = 3 km → pilih D

Step 5: Kembali ke Depot.
        D → Depot = 4 km

Rute final: Depot → A → B → C → D → Depot
Total: 2 + 3 + 4 + 3 + 4 = 16 km
```

**Intinya:** Selalu pilih tetangga terdekat yang belum dikunjungi. Hasilnya rute yang efisien, hemat bensin dan waktu.

**Fleksibel:** Titik bertambah? Algoritma tetap bekerja sama — prinsipnya tidak berubah.

---

### Kombinasi Kedua Algoritma

```
Priority Rule dulu:
→ Tentukan KECAMATAN mana yang dilayani pertama
   (berdasarkan jumlah request terbanyak)

Nearest Neighbor kemudian:
→ Di dalam kecamatan itu, tentukan URUTAN TITIK
   yang paling efisien untuk dikunjungi
```

**Contoh nyata:**
```
Priority Rule → Wenang duluan (8 request)
Nearest Neighbor → Titik di Wenang dikunjungi urutan: W3 → W1 → W7 → ...
                   (berdasarkan jarak terdekat)
```

---

## 8. Ringkasan Singkat

| Komponen | Fungsi |
|---|---|
| **Warga** | Buat request sampah via 1 halaman form |
| **Admin** | Verifikasi request, kelola petugas |
| **Algoritma Priority Rule** | Tentukan kecamatan mana yang dilayani lebih dulu |
| **Algoritma Nearest Neighbor** | Tentukan urutan rute terpendek antar titik |
| **Petugas** | Ikuti rute yang sudah dihitung, update status |
| **Sistem** | Otomatis atur jadwal dan rute tanpa campur tangan manual |

---

*Dokumen ini dibuat sebagai referensi sistem Manado Recycle Hub.*

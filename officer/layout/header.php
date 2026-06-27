<?php
// ============================================================
//  officer/layout/header.php — Sidebar Layout Officer
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../include/auth.php';
requireRole('officer');
$_flash = getFlash();

$db = getDB();

// ── Resolve officer_id ────────────────────────────────────────
$officerId = (int)($_SESSION['officer_id'] ?? 0);
if (!$officerId) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid) {
        $r = $db->prepare("SELECT id FROM officers WHERE user_id=? LIMIT 1");
        $r->execute([$uid]);
        $officerId = (int)$r->fetchColumn();
        if ($officerId) $_SESSION['officer_id'] = $officerId;
    }
    if (!$officerId) { session_destroy(); header('Location: '.baseUrl('login.php')); exit; }
    
    // Update last_seen_at immediately when officer opens any page
    try {
        $db->prepare("UPDATE officers SET last_seen_at = NOW() WHERE id = ?")->execute([$officerId]);
    } catch (Exception $e) {}
}

// ── Shared data (tersedia di semua halaman officer) ───────────
try {
    $s = $db->prepare("SELECT o.*, u.email, u.nomor_wa AS user_wa FROM officers o LEFT JOIN users u ON u.id=o.user_id WHERE o.id=?");
    $s->execute([$officerId]);
    $officer = $s->fetch();
} catch(Exception $e){ $officer = null; }
if (!$officer) $officer = ['id'=>$officerId,'nama'=>'Petugas','officer_code'=>'OFC-0001','kendaraan'=>'-','status'=>'aktif','email'=>'','user_wa'=>''];

$st = ['total_all'=>0,'total_selesai'=>0,'aktif'=>0,'selesai_hari_ini'=>0,'total_berat'=>0];
try {
    $sq = $db->prepare("
        SELECT 
            SUM(t.total_all) as total_all,
            SUM(t.total_selesai) as total_selesai,
            SUM(t.selesai_hari_ini) as selesai_hari_ini,
            SUM(t.aktif) as aktif,
            SUM(t.total_berat) as total_berat
        FROM (
            SELECT COUNT(*) AS total_all,
                SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) AS total_selesai,
                SUM(CASE WHEN status='selesai' AND DATE(updated_at)=CURDATE() THEN 1 ELSE 0 END) AS selesai_hari_ini,
                SUM(CASE WHEN status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) AS aktif,
                COALESCE(SUM(CASE WHEN status='selesai' THEN berat_total_kg ELSE 0 END),0) AS total_berat
            FROM pickup_requests WHERE officer_id=?
            UNION ALL
            SELECT COUNT(*) AS total_all,
                SUM(CASE WHEN cr.status='selesai' THEN 1 ELSE 0 END) AS total_selesai,
                SUM(CASE WHEN cr.status='selesai' AND DATE(cr.updated_at)=CURDATE() THEN 1 ELSE 0 END) AS selesai_hari_ini,
                SUM(CASE WHEN cr.status NOT IN ('selesai','dibatalkan') THEN 1 ELSE 0 END) AS aktif,
                COALESCE(SUM(CASE WHEN cr.status='selesai' THEN ci.berat_kg ELSE 0 END),0) AS total_berat
            FROM cleanup_requests cr
            LEFT JOIN cleanup_items ci ON ci.cleanup_id = cr.id
            WHERE cr.officer_id=?
        ) t
    ");
    $sq->execute([$officerId, $officerId]);
    $st = $sq->fetch(PDO::FETCH_ASSOC) ?: $st;
} catch(Exception $e){}

// Jumlah tugas hari ini (untuk badge sidebar)
$todayCount = 0;
$cleanupCount = 0;
try {
    $tc = $db->prepare("SELECT COUNT(*) FROM pickup_requests WHERE officer_id=? AND status NOT IN ('selesai','dibatalkan') AND (tanggal_jemput<=? OR tanggal_jemput IS NULL)");
    $tc->execute([$officerId, date('Y-m-d')]);
    $todayCount = (int)$tc->fetchColumn();

    $cc = $db->prepare("SELECT COUNT(*) FROM cleanup_requests WHERE officer_id=? AND status NOT IN ('selesai','dibatalkan') AND (tanggal_tugas<=? OR tanggal_tugas IS NULL)");
    $cc->execute([$officerId, date('Y-m-d')]);
    $cleanupCount = (int)$cc->fetchColumn();
} catch(Exception $e){}

$page_id = $page_id ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'Officer Console') ?> — <?= SITE_NAME ?></title>
<link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
<link rel="manifest" href="<?= baseUrl('manifest.json') ?>">
<meta name="theme-color" content="#1c6434">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Comfortaa:wght@400;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --green:#1c6434;--green-light:#dcfce7;--green-mid:#22c55e;
  --dark:#1c1c1c;--text:#f9f9f9;--shadow:0 2px 8px rgba(0,0,0,.08);
  --radius:12px;--sidebar-w:260px;
  --font:'Comfortaa',sans-serif;--ui:'Inter',system-ui,sans-serif;
  --amber:#f59e0b;--blue:#3b82f6;--orange:#f97316;--red:#ef4444;
  /* React Spring curves */
  --spring-transit: cubic-bezier(0.34, 1.56, 0.64, 1);
  --smooth-transit: cubic-bezier(0.16, 1, 0.3, 1);
}
body{font-family:var(--font);background:#f0f4f0;color:#1c1c1c;min-height:100vh}
a{text-decoration:none;color:inherit}

/* ── SIDEBAR ── */
.sidebar{
  position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;
  background:var(--dark);display:flex;flex-direction:column;
  overflow-y:auto;z-index:1200;
  transition:width .35s var(--spring-transit), transform .35s var(--spring-transit);
}
.sidebar-brand{
  padding:24px 20px 16px;border-bottom:1px solid rgba(255,255,255,.08);
}
.sidebar-brand .brand-name{
  font-size:15pt;font-weight:700;color:var(--text);display:block;
}
.sidebar-brand .brand-sub{font-size:9pt;color:rgba(255,255,255,.45);display:block;margin-top:2px}
.officer-pill{
  margin:14px 16px 0;background:rgba(255,255,255,.08);border-radius:10px;
  padding:12px 14px;display:flex;align-items:center;gap:10px;
}
.officer-pill .av{
  width:36px;height:36px;border-radius:50%;background:var(--green);
  color:#fff;display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:15px;flex-shrink:0;
}
.officer-pill .info{flex:1;min-width:0}
.officer-pill .name{font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.officer-pill .code{font-size:10px;color:rgba(255,255,255,.5);margin-top:1px}
.gps-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;animation:pulse 1.5s infinite;flex-shrink:0}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.3)}}

/* ── Collapsible Nav Group ── */
.nav-group { padding: 0 10px; }
.nav-group-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 12px 6px;
  cursor: pointer;
  user-select: none;
  border-radius: 6px;
  transition: background .2s var(--smooth-transit);
  margin-top: 4px;
}
.nav-group-header:hover { background: rgba(255,255,255,.07); }
.nav-group-label {
  font-size: 10px; font-weight: 700;
  color: rgba(255,255,255,.45);
  text-transform: uppercase; letter-spacing: .08em;
}
.nav-group-arrow {
  font-size: 10px;
  color: rgba(255,255,255,.4);
  transition: transform .28s var(--spring-transit);
  line-height: 1;
}
.nav-group.collapsed .nav-group-arrow { transform: rotate(-90deg); }
.nav-group-items {
  overflow: hidden;
  max-height: 500px;
  transition: max-height .32s var(--spring-transit), opacity .25s var(--smooth-transit);
  opacity: 1;
  padding-left: 0;
  margin-bottom: 8px;
}
.nav-group.collapsed .nav-group-items {
  max-height: 0;
  opacity: 0;
  margin-bottom: 0;
}

.nav-item {
  display:flex;align-items:center;gap:10px;padding:10px 12px;
  border-radius:8px;font-size:13px;font-weight:700;color:rgba(255,255,255,.7);
  cursor:pointer;
  transition:padding-left .28s var(--spring-transit), background-color .2s, color .2s, transform .2s var(--spring-transit);
  margin-bottom:2px;text-decoration:none;
}
.nav-item:hover{
  background:rgba(255,255,255,.08);
  color:#fff;
  padding-left:16px;
  transform:translateX(2px);
}
.nav-item.active{background:var(--green);color:#fff}
.nav-item .icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
.nav-badge{
  margin-left:auto;background:var(--amber);color:#fff;
  font-size:9px;font-weight:700;padding:2px 7px;border-radius:10px;font-family:var(--ui);
}
.sidebar-footer{
  margin-top:auto;padding:16px 12px;border-top:1px solid rgba(255,255,255,.08);
}

/* ── TOPBAR ── */
.topbar{
  position:fixed;top:0;left:var(--sidebar-w);right:0;height:58px;z-index:1000;
  background:#fff;border-bottom:1px solid #e5e7eb;
  display:flex;align-items:center;padding:0 24px;gap:16px;box-shadow:var(--shadow);
  transition:left .35s var(--spring-transit);
}
.hamburger{
  display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:4px;
  transition:background-color .2s;
}
.hamburger span{width:22px;height:2px;background:#333;border-radius:2px}
.topbar-title{font-size:16px;font-weight:700;color:#1c1c1c;flex:1}
.topbar-sub{font-size:11px;color:#888;font-family:var(--ui)}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100}
.sidebar-overlay.open{display:block}

/* ── MAIN ── */
.main-wrap{
  margin-left:var(--sidebar-w);margin-top:58px;padding:24px;min-height:calc(100vh - 58px);
  transition:margin-left .35s var(--spring-transit);
  /* React-style page transition */
  animation: pageFadeIn 0.5s var(--smooth-transit) both;
  min-width: 0;
}
.page-header{margin-bottom:20px}
.page-header h1{font-size:20px;font-weight:700;color:#1c1c1c}
.page-header p{font-size:13px;color:#888;margin-top:4px;font-family:var(--ui)}

@keyframes pageFadeIn {
  from { opacity:0; transform:translateY(12px) }
  to { opacity:1; transform:translateY(0) }
}

/* ── CARD ── */
.card{
  background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);padding:18px;margin-bottom:16px;
  max-width: 100%;
  overflow-x: auto;
  transition:transform .25s var(--spring-transit), box-shadow .25s ease;
}
.card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 16px rgba(0,0,0,.06);
}
.card-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:700;margin-bottom:14px;color:#1c1c1c;font-family:var(--ui)}
.ct-icon{font-size:16px}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px}
.stat-mini{
  background:#fff;border-radius:var(--radius);padding:14px;box-shadow:var(--shadow);text-align:center;border-top:3px solid var(--green);
  transition:transform .25s var(--spring-transit), box-shadow .25s ease;
}
.stat-mini:hover{
  transform: translateY(-3px);
  box-shadow: 0 10px 20px rgba(0,0,0,.08);
}
.stat-mini .val{font-size:26px;font-weight:800;color:var(--green);font-family:var(--ui)}
.stat-mini .lbl{font-size:11px;color:#888;margin-top:3px;font-family:var(--ui);font-weight:600;text-transform:uppercase;letter-spacing:.04em}

/* ── TASK CARD ── */
.task-card{
  background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:12px;overflow:hidden;border-left:4px solid var(--green);
  transition:transform .25s var(--spring-transit), box-shadow .25s ease;
}
.task-card:hover{
  transform:translateY(-2px);
  box-shadow: 0 8px 16px rgba(0,0,0,.08);
}
.task-card:active{transform:scale(.98)}
.task-card.status-sedang_diproses{border-left-color:var(--orange)}
.task-card.status-dijadwalkan{border-left-color:var(--blue)}
.task-card.status-dikonfirmasi{border-left-color:#8b5cf6}
.task-header{padding:14px 16px 10px;display:flex;gap:12px;align-items:flex-start}
.task-seq{width:32px;height:32px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;font-family:var(--ui)}
.task-info{flex:1}
.task-code{font-size:11px;font-weight:700;color:var(--green);font-family:var(--ui)}
.task-name{font-size:15px;font-weight:700;margin-top:2px}
.task-addr{font-size:12px;color:#666;margin-top:3px;line-height:1.5}
.task-meta{display:flex;gap:6px;margin-top:7px;flex-wrap:wrap}
.task-badge{font-size:10px;padding:3px 9px;border-radius:10px;font-weight:700;font-family:var(--ui)}
.task-actions{padding:0 16px 14px;display:flex;gap:8px;flex-wrap:wrap}

/* ── BUTTONS ── */
.btn{
  display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;
  transition: transform .25s var(--spring-transit), background-color .2s, box-shadow .25s ease;
  font-family:var(--font);
}
.btn:hover{
  transform: translateY(-1.5px) scale(1.02);
  box-shadow: 0 4px 8px rgba(0,0,0,.08);
}
.btn:active{transform:scale(.96)}
.btn-green{background:var(--green);color:#fff}.btn-green:hover{background:#155229}
.btn-blue{background:var(--blue);color:#fff}.btn-blue:hover{background:#2563eb}
.btn-outline{background:#fff;color:#333;border:1px solid #e0e0e0}.btn-outline:hover{background:#f5f5f5}
.btn-sm{padding:6px 11px;font-size:11px}
.btn-full{width:100%;justify-content:center}

/* ── FORM ── */
.form-group{margin-bottom:13px}
.form-label{font-size:11px;font-weight:700;color:#555;margin-bottom:5px;display:block;font-family:var(--ui);text-transform:uppercase;letter-spacing:.04em}
.form-input{
  width:100%;padding:10px 12px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:13px;outline:none;font-family:var(--font);
  transition: border-color .25s var(--smooth-transit), box-shadow .25s var(--smooth-transit), background-color .25s;
  background:#fafafa;
}
.form-input:focus{border-color:var(--green);background:#fff;box-shadow:0 0 0 3px rgba(28,100,52,.08)}
select.form-input{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M0 0l6 8 6-8z' fill='%23666'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
textarea.form-input{resize:vertical;min-height:72px}

/* ── BADGE ── */
.badge{display:inline-block;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;font-family:var(--ui);transition:transform .2s}
.badge:hover{transform:scale(1.05)}
.badge-green{background:#dcfce7;color:#166534}
.badge-amber{background:#fef3c7;color:#92400e}
.badge-blue{background:#dbeafe;color:#1e40af}

/* ── TABLE ── */
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:12px;font-family:var(--ui)}
thead th{padding:9px 10px;text-align:left;font-weight:700;color:#888;border-bottom:2px solid #f0f0f0;white-space:nowrap;text-transform:uppercase;font-size:10px;letter-spacing:.04em}
tbody td{padding:9px 10px;border-bottom:1px solid #f5f5f5;vertical-align:middle;transition:background-color .15s}
tbody tr{transition: transform .2s var(--spring-transit), background-color .15s}
tbody tr:hover{background:#fafafa;transform:scale(1.002)}

/* ── MODAL ── */
.modal-backdrop{
  position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:none;align-items:flex-end;
  backdrop-filter:blur(2px);transition:opacity .3s var(--smooth-transit);
}
.modal-backdrop.open{display:flex}
.modal-sheet{
  background:#fff;border-radius:20px 20px 0 0;width:100%;padding:20px 20px 32px;max-height:88vh;overflow-y:auto;
  animation:slideUp .35s var(--spring-transit) forwards;
}
.modal-handle{width:40px;height:4px;background:#ddd;border-radius:2px;margin:0 auto 16px}
.modal-title{font-size:16px;font-weight:700;margin-bottom:16px;font-family:var(--ui)}
@keyframes slideUp{from{transform:translateY(100%);opacity:0}to{transform:translateY(0);opacity:1}}

/* ── PROGRESS ── */
.progress-wrap{background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;margin-top:6px}
.progress-fill{height:100%;background:var(--green);border-radius:4px;transition:width .6s var(--spring-transit)}

/* ── INFO ROW ── */
.info-row{display:flex;justify-content:space-between;padding:11px 14px;background:#f9fafb;border-radius:8px;margin-bottom:8px;font-family:var(--ui)}
.info-row .lbl{font-size:13px;color:#666}
.info-row .val{font-size:13px;font-weight:700}

/* ── EMPTY ── */
.empty{text-align:center;padding:48px 20px;color:#aaa}
.empty-icon{font-size:44px;margin-bottom:10px}
.empty-text{font-size:14px;font-weight:700}

/* ── TOAST ── */
#toastContainer {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    pointer-events: none;
    width: min(90vw, 400px);
}
#toastContainer:empty {
    display: none !important;
}
.toast {
    pointer-events: auto;
    background: #ffffff !important;
    color: #1f2937 !important;
    border-radius: 16px !important;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.05) !important;
    padding: 24px 32px !important;
    max-width: 400px;
    width: 90%;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 16px;
    border: none !important;
    font-family: var(--font);
    animation: toast-scale-in 0.4s var(--spring-transit) forwards;
}
.toast-success { border-top: 5px solid var(--green) !important; }
.toast-danger  { border-top: 5px solid #ef4444 !important; }

@keyframes toast-scale-in {
    from { transform: scale(0.9) translateY(15px); opacity: 0; }
    to { transform: scale(1) translateY(0); opacity: 1; }
}
@keyframes toast-fade-out {
    from { transform: scale(1); opacity: 1; }
    to { transform: scale(0.95); opacity: 0; }
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
    animation: alert-fade-in 0.25s var(--smooth-transit);
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
    animation: alert-scale-in 0.4s var(--spring-transit) forwards;
    font-family: var(--font);
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
    background: var(--green);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 24px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    width: 100%;
    transition: background 0.15s, transform .2s var(--spring-transit);
    font-family: var(--font);
}
.flash-close-btn:hover {
    background: var(--green-mid);
    transform:scale(1.03);
}
.flash-close-btn:active {
    transform:scale(0.97);
}

@keyframes alert-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes alert-scale-in {
    from { transform: scale(0.9) translateY(20px); opacity: 0; }
    to { transform: scale(1) translateY(0); opacity: 1; }
}

/* ── MAP ── */
#officerMap{width:100%;height:400px;border-radius:var(--radius);overflow:hidden;border:1px solid #d1e8f5}

/* ── Collapsed Sidebar Styles ── */
.btn-sidebar-toggle {
  background: none;
  border: none;
  cursor: pointer;
  padding: 6px;
  color: #333;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}
.btn-sidebar-toggle:hover {
  background: #f0f0f0;
}
.sidebar-toggle-icon {
  transition: transform 0.25s var(--spring-transit);
}

body.sidebar-collapsed {
  --sidebar-w: 64px;
}
body.sidebar-collapsed .sidebar-brand div,
body.sidebar-collapsed .officer-pill .info,
body.sidebar-collapsed .officer-pill .gps-dot,
body.sidebar-collapsed .nav-group-header,
body.sidebar-collapsed .nav-text,
body.sidebar-collapsed .nav-badge {
  display: none !important;
}
body.sidebar-collapsed .sidebar-brand {
  padding: 20px 10px 16px;
  justify-content: center;
}
body.sidebar-collapsed .officer-pill {
  justify-content: center;
  margin: 14px 4px 0;
  padding: 8px;
}
body.sidebar-collapsed .nav-group {
  padding: 0 4px;
}
body.sidebar-collapsed .nav-group-items {
  max-height: 500px;
  opacity: 1;
}
body.sidebar-collapsed .nav-item {
  justify-content: center;
  padding: 12px 0;
}
body.sidebar-collapsed .nav-item:hover {
  padding-left: 0 !important;
  transform: none !important;
}
body.sidebar-collapsed .sidebar-footer {
  padding: 16px 4px;
}
body.sidebar-collapsed .sidebar-toggle-icon {
  transform: rotate(180deg);
}

/* ── RESPONSIVE ── */
@media(max-width:768px){
  body.sidebar-collapsed {
    --sidebar-w: 260px;
  }
  body.sidebar-collapsed .sidebar-brand div { display: block !important; }
  body.sidebar-collapsed .officer-pill .info { display: block !important; }
  body.sidebar-collapsed .officer-pill .gps-dot { display: block !important; }
  body.sidebar-collapsed .nav-group-header { display: flex !important; }
  body.sidebar-collapsed .nav-text { display: inline-block !important; }
  body.sidebar-collapsed .nav-badge { display: inline-block !important; }
  body.sidebar-collapsed .nav-group { padding: 0 10px !important; }
  body.sidebar-collapsed .nav-item { justify-content: flex-start !important; padding: 10px 12px !important; }
  body.sidebar-collapsed .nav-item:hover { padding-left: 16px !important; transform: translateX(2px) !important; }
  .btn-sidebar-toggle {
    display: none !important;
  }
  .sidebar{transform:translateX(-100%);transition:transform .35s var(--spring-transit)}
  .sidebar.open{transform:translateX(0)}
  .topbar{left:0}
  .main-wrap{margin-left:0}
  .hamburger{display:flex}
  .stats-row{grid-template-columns:1fr}

  /* Responsive Flash alerts on mobile */
  .flash {
    padding: 20px 24px !important;
    width: 92% !important;
    max-width: 320px !important;
    gap: 12px !important;
    border-radius: 12px !important;
  }
  .flash-icon {
    font-size: 38px !important;
  }
  .flash-msg {
    font-size: 13px !important;
    line-height: 1.4 !important;
  }
  .flash-close-btn {
    padding: 8px 16px !important;
    font-size: 12px !important;
  }
  
  /* Responsive Toasts on mobile */
  .toast {
    padding: 16px 20px !important;
    width: 92% !important;
    max-width: 320px !important;
    gap: 12px !important;
  }
}
</style>
<script>
// ── Sidebar Collapse & Nav State Restorer (Early Execution) ──
const SIDEBAR_COLLAPSED_KEY = 'mrh_officer_sidebar_collapsed';
const NAV_STATE_KEY = 'mrh_officer_nav_state';

(function() {
  try {
    if (localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1' && window.innerWidth > 768) {
      document.body.classList.add('sidebar-collapsed');
    }
  } catch(e) {}
})();

function toggleSidebarCollapse() {
  const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
  try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, isCollapsed ? '1' : '0'); } catch(e) {}
  
  // Recalculate map container size if map exists
  if (typeof mapInstance !== 'undefined' && mapInstance) {
    setTimeout(() => {
      if (typeof mapInstance.invalidateSize === 'function') {
        mapInstance.invalidateSize();
      } else if (typeof google !== 'undefined' && google.maps && typeof google.maps.event !== 'undefined') {
        google.maps.event.trigger(mapInstance, 'resize');
      }
    }, 400); // 400ms matches the transition duration
  }
}

function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('open'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('open'); }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeSidebar(); });

// ── Collapsible Nav Groups ──
function toggleNavGroup(groupId) {
  const group = document.getElementById(groupId);
  if (!group) return;
  group.classList.toggle('collapsed');
  saveNavState();
}
function saveNavState() {
  const state = {};
  document.querySelectorAll('.nav-group').forEach(g => { state[g.id] = g.classList.contains('collapsed'); });
  try { localStorage.setItem(NAV_STATE_KEY, JSON.stringify(state)); } catch(e) {}
}
function restoreNavState() {
  let state = {};
  try { state = JSON.parse(localStorage.getItem(NAV_STATE_KEY) || '{}'); } catch(e) {}
  const activeLink = document.querySelector('.nav-group-items .nav-item.active');
  let activeGroupId = null;
  if (activeLink) {
    const parentGroup = activeLink.closest('.nav-group');
    if (parentGroup) activeGroupId = parentGroup.id;
  }
  document.querySelectorAll('.nav-group').forEach(g => {
    if (g.id === activeGroupId) {
      g.classList.remove('collapsed');
    } else if (state[g.id] === true) {
      g.classList.add('collapsed');
    }
  });
}
document.addEventListener('DOMContentLoaded', restoreNavState);
</script>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand" style="display: flex; align-items: center; gap: 10px;">
    <?php
    $logo_src = file_exists('logo_square.png') ? 'logo_square.png' : '../logo_square.png';
    ?>
    <img src="<?= $logo_src ?>" alt="Logo" style="width: 36px; height: 36px; object-fit: cover; border-radius: 8px; flex-shrink: 0;">
    <div>
      <span class="brand-name" style="margin: 0; line-height: 1.2;"><?= SITE_NAME ?></span>
    </div>
  </div>

  <div class="officer-pill">
    <div class="gps-dot" id="gpsDot"></div>
    <div class="av"><?= strtoupper(substr($officer['nama'],0,1)) ?></div>
    <div class="info">
      <div class="name"><?= htmlspecialchars($officer['nama']) ?></div>
      <div class="code"><?= htmlspecialchars($officer['officer_code']) ?></div>
    </div>
  </div>

  <!-- Group 1: Ringkasan & Laporan -->
  <div class="nav-group" id="group-ringkasan">
    <div class="nav-group-header" onclick="toggleNavGroup('group-ringkasan')">
      <span class="nav-group-label">Ringkasan &amp; Laporan</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <div class="nav-group-items">
      <a href="dashboard.php" class="nav-item <?= $page_id==='dashboard'?'active':'' ?>" title="Dashboard Statistik">
        <span class="icon">📊</span> <span class="nav-text">Dashboard</span>
      </a>
      <a href="laporan.php" class="nav-item <?= $page_id==='laporan'?'active':'' ?>" title="Laporan Saya">
        <span class="icon">📄</span> <span class="nav-text">Laporan Saya</span>
      </a>
    </div>
  </div>

  <!-- Group 2: Manajemen Tugas -->
  <div class="nav-group" id="group-tugas">
    <div class="nav-group-header" onclick="toggleNavGroup('group-tugas')">
      <span class="nav-group-label">Manajemen Tugas</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <div class="nav-group-items">
      <a href="tugas_hari_ini.php" class="nav-item <?= $page_id==='tugas'?'active':'' ?>" title="Tugas Hari Ini">
        <span class="icon">📋</span> <span class="nav-text">Tugas Hari Ini</span>
        <?php if($todayCount>0): ?><span class="nav-badge"><?= $todayCount ?></span><?php endif; ?>
      </a>
      <a href="cleanup_tasks.php" class="nav-item <?= $page_id==='cleanup'?'active':'' ?>" title="Tugas Clean Up">
        <span class="icon">🧹</span> <span class="nav-text">Tugas Clean Up</span>
        <?php if($cleanupCount>0): ?><span class="nav-badge"><?= $cleanupCount ?></span><?php endif; ?>
      </a>
      <a href="semua_tugas.php" class="nav-item <?= $page_id==='semua_tugas'?'active':'' ?>" title="Semua Tugas">
        <span class="icon">🗂️</span> <span class="nav-text">Semua Tugas</span>
      </a>
      <a href="riwayat.php" class="nav-item <?= $page_id==='riwayat'?'active':'' ?>" title="Riwayat Tugas">
        <span class="icon">📜</span> <span class="nav-text">Riwayat Tugas</span>
      </a>
    </div>
  </div>

  <!-- Group 3: Navigasi & Akun -->
  <div class="nav-group" id="group-navigasi">
    <div class="nav-group-header" onclick="toggleNavGroup('group-navigasi')">
      <span class="nav-group-label">Navigasi &amp; Akun</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <div class="nav-group-items">
      <a href="peta.php" class="nav-item <?= $page_id==='peta'?'active':'' ?>" title="Peta &amp; Rute">
        <span class="icon">🗺️</span> <span class="nav-text">Peta &amp; Rute</span>
      </a>
      <a href="profil.php" class="nav-item <?= $page_id==='profil'?'active':'' ?>" title="Profil Saya">
        <span class="icon">👤</span> <span class="nav-text">Profil Saya</span>
      </a>
    </div>
  </div>

  <div class="sidebar-footer">
    <a href="#" id="btnInstallPWA" class="nav-item" style="color:#4ade80; display:none; font-weight:700;" title="Install Aplikasi">
      <span class="icon">📲</span> <span class="nav-text">Download dan Install Aplikasi</span>
    </a>
    <a href="logout.php" class="nav-item" style="color:rgba(255,110,110,.85)" title="Keluar">
      <span class="icon">🚪</span> <span class="nav-text">Keluar</span>
    </a>
  </div>
</aside>

<!-- ══ TOPBAR ══ -->
<div class="topbar">
  <button class="hamburger" onclick="toggleSidebar()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>
  <button class="btn-sidebar-toggle" onclick="toggleSidebarCollapse()" title="Toggle Sidebar">
    <svg class="sidebar-toggle-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
      <line x1="9" y1="3" x2="9" y2="21"/>
      <path class="arrow-path" d="M16 15 l-3 -3 l 3 -3"/>
    </svg>
  </button>
  <div>
    <div class="topbar-title"><?= htmlspecialchars($page_title ?? 'Officer Console') ?></div>
    <div class="topbar-sub"><?= SITE_NAME ?> · <?= date('l, d M Y') ?></div>
  </div>
</div>

<!-- ══ MAIN ══ -->
<div class="main-wrap">
<div id="toastContainer"></div>

<?php if ($_flash): ?>
<div class="flash-overlay" id="flashOverlay">
  <div class="flash flash-<?= htmlspecialchars($_flash['type']) ?>">
    <div class="flash-icon"><?= $_flash['type'] === 'success' ? '✅' : '❌' ?></div>
    <div class="flash-msg"><?= htmlspecialchars($_flash['msg']) ?></div>
    <button class="flash-close-btn" onclick="document.getElementById('flashOverlay').style.display='none'">Tutup</button>
  </div>
</div>
<?php endif; ?>

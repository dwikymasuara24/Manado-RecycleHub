<?php
// ============================================================
//  layout/header.php — Admin Console Header
//  Manado Recycle Hub
//  Selalu di-include setelah require_once '../include/config.php'
//  dari file admin mana pun (di root atau subfolder admin/)
// ============================================================

// Pastikan session aktif
if (session_status() === PHP_SESSION_NONE) session_start();

// Validasi otentikasi & hak akses Admin
require_once __DIR__ . '/../../include/auth.php';
requireRole('admin');

// Flash message
$_flash = getFlash();

$pfx = ''; // Semua file admin ada di root skripsi/

// Ambil info admin aktif untuk header
$current_admin_id = $_SESSION['user_id'] ?? 1;
$header_db = isset($db) ? $db : getDB();
$headerAdminName = 'Super Admin MRH';
$headerAdminAvatar = 'SA';

try {
    $headerAdminStmt = $header_db->prepare("SELECT nama FROM users WHERE id = ? LIMIT 1");
    $headerAdminStmt->execute([$current_admin_id]);
    $headerAdmin = $headerAdminStmt->fetch();
    if ($headerAdmin && !empty($headerAdmin['nama'])) {
        $headerAdminName = $headerAdmin['nama'];
        // Buat inisial avatar
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-zA-Z ]/', '', $headerAdminName)));
        $headerAdminAvatar = strtoupper(substr($words[0] ?? '', 0, 1));
        if (count($words) > 1) {
            $headerAdminAvatar .= strtoupper(substr(end($words), 0, 1));
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title ?? 'Admin') ?> — <?= SITE_NAME ?></title>
  <link rel="icon" type="image/png" href="<?= baseUrl('Title.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ── Reset & Base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --green-700:#1c6434; --green-600:#1e7a40; --green-500:#22c55e;
      --green-200:#bbf7d0; --green-100:#dcfce7; --green-50:#f0fdf4;
      --amber:#f59e0b; --blue:#3b82f6; --red:#ef4444; --purple:#8b5cf6;
      --orange:#f97316; --gray:#6b7280;
      --sidebar-w:220px; --nav-h:56px;
      --radius:10px; --shadow:0 2px 8px rgba(0,0,0,.08);
      --font:'Inter',system-ui,sans-serif;
      /* React spring curves */
      --spring-transit: cubic-bezier(0.34, 1.56, 0.64, 1);
      --smooth-transit: cubic-bezier(0.16, 1, 0.3, 1);
    }
    html { font-size:14px; }
    body { font-family:var(--font); background:#f5f7f5; color:#1a1a1a; display:flex; min-height:100vh; }
    a { text-decoration:none; color:inherit; }

    /* ── Sidebar ── */
    .sidebar {
      width: var(--sidebar-w); background:var(--green-700); color:#fff;
      position:fixed; top:0; left:0; height:100vh; z-index:50;
      display:flex; flex-direction:column; overflow-y:auto;
      transition:width .35s var(--spring-transit), transform .35s var(--spring-transit);
    }
    .sidebar-brand {
      padding:20px 20px 16px; display:flex; align-items:center; gap:10px;
      border-bottom:1px solid rgba(255,255,255,.15);
      flex-shrink:0;
    }
    .sidebar-brand .logo-circle {
      width:36px; height:36px; border-radius:8px; background:rgba(255,255,255,.2);
      display:flex; align-items:center; justify-content:center;
      font-size:16px; font-weight:800; color:#fff; flex-shrink:0;
    }
    .sidebar-brand .brand-text { font-size:12px; font-weight:700; line-height:1.3; }
    .sidebar-brand .brand-text span { display:block; opacity:.65; font-size:10px; font-weight:500; }

    /* ── Collapsible Nav Group ── */
    .nav-group { padding:0 10px; }

    .nav-group-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:10px 12px 5px;
      cursor:pointer;
      user-select:none;
      border-radius:6px;
      transition:background-color .2s var(--smooth-transit);
      margin-top:4px;
    }
    .nav-group-header:hover { background:rgba(255,255,255,.07); }

    .nav-group-label {
      font-size:10px; font-weight:700;
      color:rgba(255,255,255,.45);
      text-transform:uppercase; letter-spacing:.08em;
    }

    .nav-group-arrow {
      font-size:10px;
      color:rgba(255,255,255,.4);
      transition:transform .28s var(--spring-transit);
      line-height:1;
    }
    .nav-group.collapsed .nav-group-arrow { transform:rotate(-90deg); }

    .nav-group-items {
      list-style:none;
      overflow:hidden;
      max-height:500px;
      transition:max-height .32s var(--spring-transit), opacity .25s var(--smooth-transit);
      opacity:1;
    }
    .nav-group.collapsed .nav-group-items {
      max-height:0;
      opacity:0;
    }

    .sidebar-nav li a {
      display:flex; align-items:center; gap:10px;
      padding:9px 12px; border-radius:7px; font-size:13px; font-weight:600;
      color:rgba(255,255,255,.8); 
      transition:padding-left .28s var(--spring-transit), background-color .2s, color .2s, transform .2s var(--spring-transit); 
      margin-bottom:2px;
    }
    .sidebar-nav li a:hover { 
      background:rgba(255,255,255,.12); 
      color:#fff; 
      padding-left:16px;
      transform:translateX(2px);
    }
    .sidebar-nav li a.active { background:rgba(255,255,255,.2); color:#fff; }
    .sidebar-nav li a .nav-icon { font-size:16px; flex-shrink:0; }

    /* ── Sidebar Footer ── */
    .sidebar-footer {
      margin-top:auto;
      padding:10px 10px 12px;
      border-top:1px solid rgba(255,255,255,.15);
      flex-shrink:0;
    }
    .sidebar-footer .module-label {
      font-size:10px; font-weight:700; color:rgba(255,255,255,.4);
      text-transform:uppercase; letter-spacing:.07em; padding:4px 12px 6px;
    }
    .sidebar-footer a {
      display:flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius:7px; font-size:12px; font-weight:600;
      color:rgba(255,255,255,.7); 
      transition:padding-left .25s var(--spring-transit), background-color .2s, color .2s; 
      margin-bottom:2px;
    }
    .sidebar-footer a:hover { background:rgba(255,255,255,.1); color:#fff; padding-left:15px; }
    .sidebar-footer hr { border:none; border-top:1px solid rgba(255,255,255,.12); margin:6px 0; }

    /* ── Topbar ── */
    .topbar {
      position:fixed; top:0; left:var(--sidebar-w); right:0; height:var(--nav-h);
      background:#fff; border-bottom:1px solid #e5e7eb; z-index:40;
      display:flex; align-items:center; padding:0 24px; gap:16px;
      box-shadow:0 1px 4px rgba(0,0,0,.05);
      transition:left .35s var(--spring-transit);
    }
    .topbar-title { font-size:15px; font-weight:700; color:#1a1a1a; flex:1; }
    .topbar-badge {
      display:flex; align-items:center; gap:8px;
      background:#f0fdf4; border:1px solid var(--green-200);
      padding:6px 12px; border-radius:20px; font-size:12px; color:var(--green-700);
    }
    .topbar-badge .avatar {
      width:26px; height:26px; border-radius:50%;
      background:var(--green-700); color:#fff;
      display:flex; align-items:center; justify-content:center;
      font-size:11px; font-weight:700; flex-shrink:0;
    }
    .hamburger {
      display:none; background:none; border:none; cursor:pointer;
      padding:6px; color:#333; border-radius:6px;
      transition:background-color .2s;
    }
    .hamburger:hover { background:#f0f0f0; }

    /* ── Main Content ── */
    .main-wrap {
      margin-left:var(--sidebar-w);
      margin-top:var(--nav-h);
      flex:1; padding:24px; min-height:calc(100vh - var(--nav-h));
      transition:margin-left .35s var(--spring-transit);
      /* React-style entry fade transition */
      animation: pageFadeIn 0.5s var(--smooth-transit) both;
      min-width: 0;
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
        background: var(--green-700);
        color: white;
        border: none;
        border-radius: 8px;
        padding: 8px 24px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        width: 100%;
        transition: background .15s, transform .2s var(--spring-transit);
    }
    .flash-close-btn:hover {
        background: var(--green-600);
        transform: scale(1.03);
    }
    .flash-close-btn:active {
        transform: scale(0.97);
    }
    
    @keyframes alert-fade-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes alert-scale-in {
        from { transform: scale(0.9) translateY(20px); opacity: 0; }
        to { transform: scale(1) translateY(0); opacity: 1; }
    }

    /* ── Common Components ── */
    .page-header { margin-bottom:20px; }
    .page-header h1 { font-size:20px; font-weight:800; color:#1a1a1a; }
    .page-header p  { font-size:12px; color:#888; margin-top:3px; }

    .card { 
      background:#fff; border-radius:var(--radius); box-shadow:var(--shadow); padding:20px; margin-bottom:16px; 
      transition: transform .25s var(--spring-transit), box-shadow .25s ease;
      max-width: 100%;
      overflow-x: auto;
    }
    .card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 16px rgba(0,0,0,.06);
    }
    .card-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:700; margin-bottom:16px; color:#1a1a1a; }
    .ct-icon { font-size:16px; }

    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
    .mb-24  { margin-bottom:24px; }

    .stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-bottom:20px; }
    .stat-card { 
      background:#fff; border-radius:var(--radius); padding:16px; box-shadow:var(--shadow); border-top:3px solid #e0e0e0; 
      transition: transform .25s var(--spring-transit), box-shadow .25s ease;
    }
    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(0,0,0,.08);
    }
    .stat-card.green  { border-top-color:var(--green-600); }
    .stat-card.amber  { border-top-color:var(--amber); }
    .stat-card.blue   { border-top-color:var(--blue); }
    .stat-card.red    { border-top-color:var(--red); }
    .stat-label { font-size:11px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
    .stat-value { font-size:28px; font-weight:800; color:#1a1a1a; line-height:1; }
    .stat-sub   { font-size:11px; color:#aaa; margin-top:5px; }

    .btn { 
      display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; border:none; 
      transition: transform .25s var(--spring-transit), background-color .2s, box-shadow .25s ease; 
    }
    .btn:hover {
      transform: translateY(-1.5px) scale(1.02);
      box-shadow: 0 4px 8px rgba(0,0,0,.08);
    }
    .btn:active {
      transform: scale(0.96);
    }
    .btn-primary  { background:var(--green-700); color:#fff; }
    .btn-primary:hover { background:var(--green-600); }
    .btn-outline  { background:#fff; color:#333; border:1px solid #e0e0e0; }
    .btn-outline:hover { background:#f5f5f5; }
    .btn-danger   { background:#ef4444; color:#fff; }
    .btn-danger:hover { background:#dc2626; }
    .btn-sm       { padding:5px 10px; font-size:12px; }
    .btn-icon     { padding:6px 8px; border-radius:6px; border:1px solid #e0e0e0; background:#fff; cursor:pointer; font-size:13px; }

    .badge { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:700; transition: transform .2s; }
    .badge:hover { transform: scale(1.05); }
    .badge-green  { background:#dcfce7; color:#166534; }
    .badge-amber  { background:#fef3c7; color:#92400e; }
    .badge-blue   { background:#dbeafe; color:#1e40af; }
    .badge-red    { background:#fee2e2; color:#991b1b; }
    .badge-purple { background:#ede9fe; color:#5b21b6; }
    .badge-orange { background:#ffedd5; color:#9a3412; }
    .badge-gray   { background:#f3f4f6; color:#374151; }

    .table-wrap { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    thead th { padding:10px 12px; text-align:left; font-weight:700; color:#888; border-bottom:2px solid #f0f0f0; white-space:nowrap; text-transform:uppercase; font-size:10px; letter-spacing:.05em; }
    tbody td { padding:10px 12px; border-bottom:1px solid #f5f5f5; vertical-align:middle; transition: background-color .15s; }
    tbody tr { transition: transform .2s var(--spring-transit), background-color .15s; }
    tbody tr:hover { background:#fafafa; transform: scale(1.002); }

    .form-group  { margin-bottom:14px; }
    .form-label  { display:block; font-size:12px; font-weight:600; margin-bottom:5px; color:#444; text-transform:uppercase; letter-spacing:.04em; }
    .form-input { 
      width:100%; padding:9px 12px; border:1.5px solid #e0e0e0; border-radius:7px; font-size:13px; outline:none; 
      transition: border-color .25s var(--smooth-transit), box-shadow .25s var(--smooth-transit), background-color .25s; 
      font-family:var(--font); background:#fafafa; 
    }
    .form-input:focus { border-color:var(--green-600); background:#fff; box-shadow:0 0 0 3px rgba(28,100,52,.08); }
    .form-row    { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

    .modal-overlay { 
      display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:1000; align-items:center; justify-content:center; padding:16px; 
      backdrop-filter:blur(2px); transition: opacity .3s var(--smooth-transit); 
    }
    .modal-overlay.open, .modal-overlay[style*="display:flex"] { display:flex; }
    .modal { 
      background:#fff; border-radius:16px; width:100%; max-width:520px; box-shadow:0 8px 48px rgba(0,0,0,.2); max-height:90vh; display:flex; flex-direction:column; overflow:hidden;
      animation:modalIn .4s var(--spring-transit) forwards; 
    }
    @keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(15px)} to{opacity:1;transform:scale(1) translateY(0)} }
    .modal-header { padding:18px 24px 14px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; background:#fff; border-radius:16px 16px 0 0; }
    .modal-header h3 { font-size:15px; font-weight:800; color:#1e293b; }
    .modal-close { background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; padding:4px 6px; border-radius:6px; transition: all .2s; }
    .modal-close:hover { color:#ef4444; background:#fee2e2; transform: scale(1.08); }
    .modal-body   { padding:20px 24px; flex:1; overflow-y:auto; }
    .modal-footer { padding:14px 24px; border-top:1px solid #f1f5f9; display:flex; gap:8px; justify-content:flex-end; background:#fafafa; border-radius:0 0 16px 16px; }

    .toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .toolbar-left, .toolbar-right { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .search-input { padding:8px 12px; border:1.5px solid #e0e0e0; border-radius:7px; font-size:13px; outline:none; width:220px; font-family:var(--font); transition: border-color .2s; }
    .search-input:focus { border-color:var(--green-600); }
    .filter-select { padding:8px 10px; border:1.5px solid #e0e0e0; border-radius:7px; font-size:12px; outline:none; font-family:var(--font); background:#fff; transition: border-color .2s; }
    .filter-select:focus { border-color:var(--green-600); }

    .toggle-wrap { display:flex; align-items:center; gap:8px; }
    .toggle { position:relative; display:inline-block; width:38px; height:22px; }
    .toggle input { opacity:0; width:0; height:0; }
    .toggle-slider { position:absolute; inset:0; background:#ddd; border-radius:22px; cursor:pointer; transition:.3s var(--spring-transit); }
    .toggle-slider::before { content:''; position:absolute; width:16px; height:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s var(--spring-transit); }
    .toggle input:checked + .toggle-slider { background:var(--green-600); }
    .toggle input:checked + .toggle-slider::before { transform:translateX(16px); }
    .toggle-label { font-size:12px; font-weight:600; color:#555; }

    .bar-chart { display:flex; align-items:flex-end; gap:12px; height:140px; }
    .bar-item   { display:flex; flex-direction:column; align-items:center; gap:4px; flex:1; }
    .bar-item .bar-val { font-size:11px; font-weight:700; color:#333; }
    .bar-item .bar { width:100%; background:var(--green-600); border-radius:4px 4px 0 0; min-height:4px; transition:height .6s var(--spring-transit); }
    .bar-item span:last-child { font-size:10px; color:#888; text-align:center; }

    .timeline { display:flex; flex-direction:column; gap:0; }
    .tl-item { display:flex; gap:12px; }
    .tl-dot-wrap { display:flex; flex-direction:column; align-items:center; width:20px; flex-shrink:0; }
    .tl-dot  { width:10px; height:10px; border-radius:50%; background:var(--green-600); flex-shrink:0; margin-top:3px; transition: transform .3s var(--spring-transit); }
    .tl-item:hover .tl-dot { transform: scale(1.4); }
    .tl-line { flex:1; width:2px; background:#e5e7eb; margin:4px 0; min-height:20px; }
    .tl-body { flex:1; padding-bottom:14px; }
    .tl-title { font-size:13px; font-weight:700; color:#333; }
    .tl-sub   { font-size:11px; color:#888; margin-top:2px; }

    .progress-bar { height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; }
    .progress-fill { height:100%; background:var(--green-600); border-radius:3px; transition:width .6s var(--spring-transit); }

    .algo-box { background:#f0fdf4; border:1px solid var(--green-200); border-radius:8px; padding:10px 14px; font-size:12px; color:#1c6434; margin-bottom:12px; animation: pageFadeIn .3s var(--smooth-transit); }

    .rute-map { position:relative; height:340px; background:#e8f4f8; border-radius:var(--radius); overflow:hidden; border:1px solid #d1e8f5; }
    .rute-node { 
      position:absolute; transform:translate(-50%,-50%); width:28px; height:28px; border-radius:50%; background:#3b82f6; color:#fff; 
      display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; cursor:pointer; border:2px solid #fff; 
      box-shadow:0 2px 6px rgba(0,0,0,.2); transition: transform .25s var(--spring-transit), background-color .2s; 
    }
    .rute-node:hover { transform: translate(-50%,-50%) scale(1.15); box-shadow: 0 4px 10px rgba(0,0,0,.25); }
    .rute-node.depot { background:var(--green-700); }
    .rute-node.visited { background:#22c55e; }
    .rute-label { position:absolute; transform:translateX(-50%); font-size:10px; color:#333; background:rgba(255,255,255,.85); padding:1px 4px; border-radius:3px; white-space:nowrap; pointer-events:none; }

    .priority-rank { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; font-size:11px; font-weight:700; }
    .rank-1 { background:#fef3c7; color:#92400e; }
    .rank-2 { background:#e0e7ff; color:#3730a3; }
    .rank-3 { background:#dcfce7; color:#166534; }
    .rank-n { background:#f3f4f6; color:#374151; }
    .priority-row-1 { background:#fffbeb !important; }
    .priority-row-2 { background:#f0f9ff !important; }

    #toastArea {
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
    #toastArea:empty {
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
        animation: toast-scale-in 0.4s var(--spring-transit) forwards;
    }
    .toast-success { border-top: 5px solid #10b981 !important; }
    .toast-danger  { border-top: 5px solid #ef4444 !important; }
    .toast-info    { border-top: 5px solid #3b82f6 !important; }
    
    @keyframes toast-scale-in {
        from { transform: scale(0.9) translateY(15px); opacity: 0; }
        to { transform: scale(1) translateY(0); opacity: 1; }
    }
    @keyframes toast-fade-out {
        from { transform: scale(1); opacity: 1; }
        to { transform: scale(0.95); opacity: 0; }
    }

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
    body.sidebar-collapsed .brand-text,
    body.sidebar-collapsed .nav-text,
    body.sidebar-collapsed .nav-group-header,
    body.sidebar-collapsed .module-label,
    body.sidebar-collapsed .sidebar-footer hr {
      display: none !important;
    }
    body.sidebar-collapsed .sidebar-brand {
      padding: 20px 10px 16px;
      justify-content: center;
    }
    body.sidebar-collapsed .nav-group {
      padding: 0 4px;
    }
    body.sidebar-collapsed .nav-group-items {
      max-height: none !important;
      opacity: 1 !important;
      display: block !important;
    }
    body.sidebar-collapsed .sidebar-nav li a {
      justify-content: center;
      padding: 12px 0;
    }
    body.sidebar-collapsed .sidebar-footer {
      padding: 10px 4px;
    }
    body.sidebar-collapsed .sidebar-footer a {
      justify-content: center;
      padding: 10px 0;
    }
    body.sidebar-collapsed .sidebar-toggle-icon {
      transform: rotate(180deg);
    }

    @keyframes pageFadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @media (max-width:768px) {
      body.sidebar-collapsed {
        --sidebar-w: 220px;
      }
      .btn-sidebar-toggle {
        display: none !important;
      }
      .sidebar { transform:translateX(-100%); transition: transform .35s var(--spring-transit); }
      .sidebar.open { transform:translateX(0); }
      .topbar { left:0; }
      .main-wrap { margin-left:0; }
      .hamburger { display:block; }
      .grid-2, .grid-3, .form-row { grid-template-columns:1fr; }
      .stats-grid { grid-template-columns:1fr; }

      /* Aligning bell icon to the right on mobile, next to avatar */
      #notifBellContainer {
        position: relative !important;
        left: auto !important;
        transform: none !important;
        margin-right: 0 !important;
        z-index: 1001;
      }
      #notifBellContainer > span:first-child {
        font-size: 22px !important;
      }
      /* Centering and scaling bell dropdown on mobile */
      #notifDropdown {
        position: fixed !important;
        left: 50% !important;
        top: 60px !important;
        transform: translateX(-50%) !important;
        width: 90% !important;
        max-width: 340px !important;
        right: auto !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
        border-radius: 12px !important;
      }
      /* Prevent title overlap on mobile */
      .topbar-title {
        max-width: 40%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      /* Responsive admin badge on mobile */
      .topbar-badge span {
        display: none;
      }
      .topbar-badge {
        padding: 4px;
        background: none;
        border: none;
      }
    }
  </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="adminSidebar">
  <div class="sidebar-brand">
    <?php
    $logo_src = file_exists('logo_square.png') ? 'logo_square.png' : '../logo_square.png';
    ?>
    <img src="<?= $logo_src ?>" alt="Logo" style="width: 36px; height: 36px; object-fit: cover; border-radius: 8px; flex-shrink: 0;">
    <div class="brand-text">
      <?= SITE_NAME ?>
    </div>
  </div>

  <!-- GROUP: Utama -->
  <div class="nav-group" id="group-utama">
    <div class="nav-group-header" onclick="toggleNavGroup('group-utama')">
      <span class="nav-group-label">Utama</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <ul class="sidebar-nav nav-group-items">
      <li><a href="<?= $pfx ?>dashboard.php" class="<?= ($page_id??'')==='dashboard'?'active':'' ?>" title="Dashboard"><span class="nav-icon">🏠</span> <span class="nav-text">Dashboard</span></a></li>
    </ul>
  </div>

  <!-- GROUP: Operasional -->
  <div class="nav-group" id="group-operasional">
    <div class="nav-group-header" onclick="toggleNavGroup('group-operasional')">
      <span class="nav-group-label">Operasional</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <ul class="sidebar-nav nav-group-items">
      <li><a href="<?= $pfx ?>req_management.php"    class="<?= ($page_id??'')==='req_management'?'active':'' ?>" title="Manajemen Request"><span class="nav-icon">📋</span> <span class="nav-text">Manajemen Request</span></a></li>
      <li><a href="<?= $pfx ?>cleanup_management.php" class="<?= ($page_id??'')==='cleanup_management'?'active':'' ?>" title="Clean Up Service"><span class="nav-icon">🧹</span> <span class="nav-text">Clean Up Service</span></a></li>
      <li><a href="<?= $pfx ?>rute_jadwal.php"        class="<?= ($page_id??'')==='rute_jadwal'?'active':'' ?>" title="Rute & Jadwal"><span class="nav-icon">🗺️</span> <span class="nav-text">Rute & Jadwal</span></a></li>
      <li><a href="<?= $pfx ?>live_tracking.php"       class="<?= ($page_id??'')==='live_tracking'?'active':'' ?>" title="Pelacakan Live"><span class="nav-icon">🛵</span> <span class="nav-text">Pelacakan Live</span></a></li>
      <li><a href="<?= $pfx ?>officer_management.php" class="<?= ($page_id??'')==='officer_management'?'active':'' ?>" title="Manajemen Petugas"><span class="nav-icon">👷</span> <span class="nav-text">Manajemen Petugas</span></a></li>
      <li><a href="<?= $pfx ?>weighing_records.php"  class="<?= ($page_id??'')==='weighing_records'?'active':'' ?>" title="Rekaman Timbang"><span class="nav-icon">⚖️</span> <span class="nav-text">Rekaman Timbang</span></a></li>
    </ul>
  </div>

  <!-- GROUP: Konten & Ide -->
  <div class="nav-group" id="group-konten">
    <div class="nav-group-header" onclick="toggleNavGroup('group-konten')">
      <span class="nav-group-label">Konten & Ide</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <ul class="sidebar-nav nav-group-items">
      <li><a href="<?= $pfx ?>blog_management.php" class="<?= ($page_id??'')==='blog_management'?'active':'' ?>" title="Manajemen Blog"><span class="nav-icon">✍️</span> <span class="nav-text">Manajemen Blog</span></a></li>
      <li><a href="<?= $pfx ?>diy_management.php"  class="<?= ($page_id??'')==='diy_management'?'active':'' ?>" title="Manajemen DIY"><span class="nav-icon">💡</span> <span class="nav-text">Manajemen DIY</span></a></li>
      <li><a href="<?= $pfx ?>idea_management.php" class="<?= ($page_id??'')==='idea_management'?'active':'' ?>" title="Kotak Ide"><span class="nav-icon">📥</span> <span class="nav-text">Kotak Ide</span></a></li>
    </ul>
  </div>

  <!-- GROUP: Laporan -->
  <div class="nav-group" id="group-laporan">
    <div class="nav-group-header" onclick="toggleNavGroup('group-laporan')">
      <span class="nav-group-label">Laporan</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <ul class="sidebar-nav nav-group-items">
      <li><a href="<?= $pfx ?>laporan_harian.php"   class="<?= ($page_id??'')==='laporan_harian'?'active':'' ?>" title="Laporan Harian"><span class="nav-icon">📅</span> <span class="nav-text">Laporan Harian</span></a></li>
      <li><a href="<?= $pfx ?>laporan_mingguan.php" class="<?= ($page_id??'')==='laporan_mingguan'?'active':'' ?>" title="Laporan Mingguan"><span class="nav-icon">📆</span> <span class="nav-text">Laporan Mingguan</span></a></li>
      <li><a href="<?= $pfx ?>laporan_bulanan.php"  class="<?= ($page_id??'')==='laporan_bulanan'?'active':'' ?>" title="Laporan Bulanan"><span class="nav-icon">🗓️</span> <span class="nav-text">Laporan Bulanan</span></a></li>
      <li><a href="<?= $pfx ?>analisis_data.php"    class="<?= ($page_id??'')==='analisis_data'?'active':'' ?>" title="Analisis Data"><span class="nav-icon">📊</span> <span class="nav-text">Analisis Data</span></a></li>
    </ul>
  </div>

  <!-- GROUP: Pengaturan -->
  <div class="nav-group" id="group-pengaturan">
    <div class="nav-group-header" onclick="toggleNavGroup('group-pengaturan')">
      <span class="nav-group-label">Pengaturan</span>
      <span class="nav-group-arrow">▼</span>
    </div>
    <ul class="sidebar-nav nav-group-items">
      <li><a href="<?= $pfx ?>kategori_sampah.php" class="<?= ($page_id??'')==='kategori_sampah'?'active':'' ?>" title="Kategori Sampah"><span class="nav-icon">♻️</span> <span class="nav-text">Kategori Sampah</span></a></li>
      <li><a href="<?= $pfx ?>settings.php"        class="<?= ($page_id??'')==='settings'?'active':'' ?>" title="Pengaturan"><span class="nav-icon">⚙️</span> <span class="nav-text">Pengaturan</span></a></li>
    </ul>
  </div>

  <!-- Footer: Profil + Keluar -->
  <div class="sidebar-footer">
    <a href="<?= $pfx ?>profile.php" class="<?= ($page_id??'')==='profile'?'active':'' ?>" title="Profil Admin"><span class="nav-icon" style="font-size:16px">👤</span> <span class="nav-text">Profil Admin</span></a>
    <a href="<?= $pfx ?>logout.php" style="color:rgba(255,110,110,.85)" title="Logout"><span class="nav-icon" style="font-size:16px">🚪</span> <span class="nav-text">Keluar</span></a>
  </div>
</aside>

<!-- ── TOPBAR ── -->
<div class="topbar">
  <button class="hamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <button class="btn-sidebar-toggle" onclick="toggleSidebarCollapse()" title="Toggle Sidebar">
    <svg class="sidebar-toggle-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
      <line x1="9" y1="3" x2="9" y2="21"/>
      <path class="arrow-path" d="M16 15 l-3 -3 l 3 -3"/>
    </svg>
  </button>
  <div class="topbar-title"><?= htmlspecialchars($page_title ?? '') ?></div>
  
  <!-- Notifikasi Bell -->
  <div style="position: relative; margin-right: 15px; cursor: pointer; display: flex; align-items: center;" id="notifBellContainer">
    <span style="font-size: 18px;" onclick="toggleNotificationDropdown()">🔔</span>
    <span id="notifBadge" style="display: none; position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; font-size: 9px; width: 15px; height: 15px; align-items: center; justify-content: center; font-weight: bold; line-height: 1;">0</span>
    
    <!-- Dropdown -->
    <div id="notifDropdown" style="display: none; position: absolute; right: 0; top: 30px; width: 280px; background: white; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 9999; max-height: 350px; overflow-y: auto;">
      <div style="padding: 10px; border-bottom: 1px solid #f1f5f9; font-weight: bold; font-size: 11px; display: flex; justify-content: space-between; align-items: center; color: #0f172a;">
        <span>Notifikasi</span>
        <span id="markAllReadBtn" style="font-weight: 500; color: var(--green-700); font-size: 10px; cursor: pointer;">Tandai Semua Dibaca</span>
      </div>
      <div id="notifItemsList" style="font-size: 11px;">
        <div style="padding: 15px; text-align: center; color: #64748b;">Tidak ada notifikasi baru</div>
      </div>
    </div>
  </div>

  <div class="topbar-badge">
    <div class="avatar"><?= htmlspecialchars($headerAdminAvatar) ?></div>
    <span><?= htmlspecialchars($headerAdminName) ?></span>
  </div>
</div>

<!-- ── MAIN WRAPPER ── -->
<div class="main-wrap">

<?php if ($_flash): ?>
<div class="flash-overlay" id="flashOverlay">
  <div class="flash flash-<?= htmlspecialchars($_flash['type']) ?>">
    <div class="flash-icon"><?= $_flash['type'] === 'success' ? '✅' : '❌' ?></div>
    <div class="flash-msg"><?= htmlspecialchars($_flash['msg']) ?></div>
    <button class="flash-close-btn" onclick="document.getElementById('flashOverlay').style.display='none'">Tutup</button>
  </div>
</div>
<?php endif; ?>

<div id="toastArea"></div>

<script>
function toggleSidebar() {
  document.getElementById('adminSidebar').classList.toggle('open');
}
function openModal(id)  { document.getElementById(id).style.display='flex'; document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).style.display='none'; document.body.style.overflow=''; }
document.addEventListener('keydown', e => {
  if(e.key==='Escape') document.querySelectorAll('.modal-overlay[style*="display:flex"]').forEach(m=>closeModal(m.id));
});
function showToast(type, msg) {
  const a = document.getElementById('toastArea');
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  
  const icon = type === 'success' ? '✅' : (type === 'danger' ? '❌' : 'ℹ️');
  t.innerHTML = `
    <div class="toast-icon" style="font-size: 42px; line-height: 1;">${icon}</div>
    <div class="toast-msg" style="font-size: 13.5px; font-weight: 700; color: #1e293b; line-height: 1.5; margin-bottom: 8px;">${msg}</div>
    <button type="button" style="background: #1c6434; color: white; border: none; border-radius: 8px; padding: 6px 20px; font-size: 12px; font-weight: 700; cursor: pointer; width: 100%;" onclick="this.parentElement.remove()">Tutup</button>
  `;
  a.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'toast-fade-out 0.25s ease-in forwards';
    setTimeout(() => t.remove(), 250);
  }, 4500);
}

// ── Collapsible Nav Groups ──
const NAV_STATE_KEY = 'mrh_nav_state';
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
  const activeLink = document.querySelector('.sidebar-nav li a.active');
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

// ── Sidebar Collapse Toggle & State Restoration ──
const SIDEBAR_COLLAPSED_KEY = 'mrh_sidebar_collapsed';
function toggleSidebarCollapse() {
  const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
  try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, isCollapsed ? '1' : '0'); } catch(e) {}
}
(function() {
  try {
    if (localStorage.getItem('mrh_sidebar_collapsed') === '1' && window.innerWidth > 768) {
      document.body.classList.add('sidebar-collapsed');
    }
  } catch(e) {}
})();

// ── Polling Notifikasi Real-time ──
let lastNotifId = 0;
function playNotificationSound() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
    osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
    gain.gain.setValueAtTime(0.08, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.4);
  } catch(e) {}
}

function checkNotifications() {
  fetch('<?= baseUrl("include/notifications_api.php") ?>?last_id=' + lastNotifId)
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const badge = document.getElementById('notifBadge');
        if (data.unread_count > 0) {
          badge.textContent = data.unread_count;
          badge.style.display = 'inline-flex';
        } else {
          badge.style.display = 'none';
        }

        const list = document.getElementById('notifItemsList');
        if (data.notifications && data.notifications.length > 0) {
          if (lastNotifId === 0) {
            // First load: render list
            list.innerHTML = '';
            data.notifications.forEach(n => {
              const item = document.createElement('div');
              item.style.padding = '10px 12px';
              item.style.borderBottom = '1px solid #f1f5f9';
              item.style.background = n.is_read == 1 ? '#fff' : '#f0fdf4';
              item.style.cursor = 'pointer';
              item.innerHTML = `<strong style="color:#0f172a;">${n.judul}</strong><p style="margin:2px 0 0; color:#64748b; font-size:11px;">${n.pesan}</p>`;
              item.onclick = () => markAsRead(n.id, item);
              list.appendChild(item);
            });
          } else {
            // Subsequent polls: trigger sound, toasts and prepend new notifications
            data.notifications.forEach(n => {
              const item = document.createElement('div');
              item.style.padding = '10px 12px';
              item.style.borderBottom = '1px solid #f1f5f9';
              item.style.background = '#f0fdf4';
              item.style.cursor = 'pointer';
              item.innerHTML = `<strong style="color:#0f172a;">${n.judul}</strong><p style="margin:2px 0 0; color:#64748b; font-size:11px;">${n.pesan}</p>`;
              item.onclick = () => markAsRead(n.id, item);
              
              if (list.firstChild && list.firstChild.textContent !== 'Tidak ada notifikasi baru') {
                list.insertBefore(item, list.firstChild);
              } else {
                list.innerHTML = '';
                list.appendChild(item);
              }
              showToast('success', `${n.judul}: ${n.pesan}`);
              playNotificationSound();
            });
          }
          lastNotifId = data.max_id;
        }
      }
    })
    .catch(err => console.error('Error polling notifications:', err));
}

function markAsRead(id, element) {
  const fd = new FormData();
  fd.append('id', id);
  fetch('<?= baseUrl("include/notifications_api.php") ?>?action=mark_read', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      if (element) element.style.background = '#fff';
      checkNotifications();
    }
  });
}

function toggleNotificationDropdown() {
  const dropdown = document.getElementById('notifDropdown');
  dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', e => {
  const container = document.getElementById('notifBellContainer');
  if (container && !container.contains(e.target)) {
    document.getElementById('notifDropdown').style.display = 'none';
  }
});

document.addEventListener('DOMContentLoaded', () => {
  checkNotifications();
  setInterval(checkNotifications, 5000);
  
  const markBtn = document.getElementById('markAllReadBtn');
  if (markBtn) {
    markBtn.onclick = (e) => {
      e.stopPropagation();
      document.querySelectorAll('#notifItemsList > div').forEach(item => {
        item.click();
      });
    };
  }
});
</script>

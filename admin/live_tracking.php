<?php
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/algorithms.php'; // defines DEPOT_LAT/LNG
require_once __DIR__ . '/../include/auth.php';
requireRole('admin');

// ── AJAX Endpoint: Fetch Courier Locations & Tasks ───────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_locations') {
    header('Content-Type: application/json');
    $db = getDB();
    
    // Fetch all active officers
    $officers = $db->query("
        SELECT o.id, o.officer_code, o.nama, o.kendaraan, o.status, o.last_lat, o.last_lng, o.last_seen_at, u.nomor_wa
        FROM officers o
        JOIN users u ON o.user_id = u.id
        WHERE o.status = 'aktif'
        ORDER BY o.nama ASC
    ")->fetchAll();
    
    $data = [];
    foreach ($officers as $o) {
        $oid = (int)$o['id'];
        
        // Fetch active tasks (dijadwalkan, dalam_perjalanan, sedang_diproses / sedang_cleanup)
        $stmtTasks = $db->prepare("
            SELECT id, request_code, nama_pemohon, alamat_jemput, latitude, longitude, status, 'pickup' as tipe_layanan
            FROM pickup_requests
            WHERE officer_id = ? AND status IN ('dijadwalkan', 'dalam_perjalanan', 'sedang_diproses')
            UNION ALL
            SELECT id, request_code, nama_pemohon, alamat_jemput, latitude, longitude, status, 'cleanup' as tipe_layanan
            FROM cleanup_requests
            WHERE officer_id = ? AND status IN ('dijadwalkan', 'dalam_perjalanan', 'sedang_diproses', 'sedang_cleanup')
        ");
        $stmtTasks->execute([$oid, $oid]);
        $tasks = $stmtTasks->fetchAll();
        
        $formattedTasks = [];
        foreach ($tasks as $t) {
            $formattedTasks[] = [
                'id' => $t['id'],
                'code' => $t['request_code'],
                'nama' => $t['nama_pemohon'],
                'alamat' => $t['alamat_jemput'],
                'lat' => $t['latitude'] !== null ? (float)$t['latitude'] : null,
                'lng' => $t['longitude'] !== null ? (float)$t['longitude'] : null,
                'status' => $t['status'],
                'tipe' => $t['tipe_layanan']
            ];
        }
        
        // Calculate status online
        $lastSeenAgo = '';
        $isOnline = false;
        if ($o['last_seen_at']) {
            $diff = time() - strtotime($o['last_seen_at']);
            if ($diff < 120) { // less than 2 minutes
                $lastSeenAgo = 'Aktif sekarang';
                $isOnline = true;
            } elseif ($diff < 3600) { // less than 1 hour
                $mins = floor($diff / 60);
                $lastSeenAgo = "$mins menit lalu";
                if ($diff < 300) { // less than 5 minutes is considered online
                    $isOnline = true;
                }
            } else {
                $hours = floor($diff / 3600);
                $lastSeenAgo = "$hours jam lalu";
            }
        } else {
            $lastSeenAgo = 'Belum aktif';
        }
        
        $data[] = [
            'id' => $o['id'],
            'code' => $o['officer_code'],
            'nama' => $o['nama'],
            'kendaraan' => $o['kendaraan'],
            'nomor_wa' => $o['nomor_wa'],
            'last_lat' => $o['last_lat'] !== null ? (float)$o['last_lat'] : null,
            'last_lng' => $o['last_lng'] !== null ? (float)$o['last_lng'] : null,
            'last_seen_at' => $o['last_seen_at'],
            'last_seen_ago' => $lastSeenAgo,
            'is_online' => $isOnline,
            'tasks' => $formattedTasks
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

$page_id    = 'live_tracking';
$page_title = 'Pelacakan Kurir Real-Time';
require_once __DIR__ . '/layout/header.php';
?>

<style>
/* ── Pulse and Animations ── */
.pulse-indicator-map {
  box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
  animation: pulse-map 1.6s infinite;
  border-radius: 50%;
}
@keyframes pulse-map {
  0% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
  }
  70% {
    transform: scale(1);
    box-shadow: 0 0 0 8px rgba(34, 197, 94, 0);
  }
  100% {
    transform: scale(0.95);
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
  }
}

.pulse-indicator-refresh {
  animation: pulse-refresh 2s infinite;
}
@keyframes pulse-refresh {
  0%, 100% { opacity: 0.6; }
  50% { opacity: 1; }
}

/* ── Modern Layout CSS ── */
.tracking-container {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  height: calc(100vh - 160px);
  min-height: 550px;
  margin-top: 15px;
}

.courier-sidebar {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  padding: 18px;
}

.courier-list-scroll {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 8px;
  padding-right: 4px;
}

.courier-card {
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px;
  background: #fff;
  cursor: pointer;
  transition: all 0.25s var(--spring-transit);
  display: flex;
  flex-direction: column;
  gap: 6px;
  position: relative;
}
.courier-card:hover {
  border-color: #bbf7d0;
  background: #fafdfb;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.courier-card.selected {
  border-color: var(--green-600);
  background: #f0fdf4;
  box-shadow: 0 4px 12px rgba(34, 197, 94, 0.08);
}

.courier-name {
  font-size: 14px;
  font-weight: 800;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding-right: 60px;
}

.courier-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: #64748b;
}

.courier-badge-status {
  position: absolute;
  top: 14px;
  right: 14px;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 20px;
}

.status-online { background: #dcfce7; color: #166534; }
.status-offline { background: #f1f5f9; color: #475569; }

.task-chip-count {
  font-size: 11px;
  font-weight: 700;
  background: #eff6ff;
  color: #1d4ed8;
  padding: 3px 8px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #bfdbfe;
}
.task-chip-count.cleanup {
  background: #fffbeb;
  color: #d97706;
  border-color: #fde68a;
}
.task-chip-count.none {
  background: #f8fafc;
  color: #64748b;
  border-color: #e2e8f0;
}

.map-container {
  padding: 0 !important;
  overflow: hidden;
  position: relative;
  border: 1.5px solid #e2e8f0;
  height: 100%;
}

#liveMap {
  width: 100%;
  height: 100%;
  background: #f4f4f4;
}

.map-legend {
  position: absolute;
  bottom: 20px;
  left: 20px;
  z-index: 1000;
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(8px);
  padding: 12px 16px;
  border-radius: 12px;
  border: 1.5px solid #e2e8f0;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
  font-size: 11.5px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: opacity 0.2s;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 10px;
}

.legend-color-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid #fff;
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
  display: inline-block;
  flex-shrink: 0;
}

@media (max-width: 900px) {
  .tracking-container {
    grid-template-columns: 1fr;
    height: auto;
  }
  .courier-sidebar {
    height: 350px;
  }
  .map-container {
    height: 450px;
  }
}
</style>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

<div class="page-header">
  <h1>🛵 Pelacakan Kurir Real-Time</h1>
  <p>Memantau pergerakan titik koordinat motor/mobil petugas lapangan secara langsung pada jalan raya.</p>
</div>

<div class="tracking-container">
  <!-- Sidebar Petugas -->
  <div class="card courier-sidebar">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-shrink:0;">
      <div class="card-title" style="margin-bottom:0;"><div class="ct-icon">👷</div> Status Petugas</div>
      <span id="onlineCountBadge" class="badge badge-green" style="font-size: 11px; font-weight:700;">0 Aktif</span>
    </div>
    
    <!-- Cari Petugas -->
    <div style="margin-bottom:12px; flex-shrink:0;">
      <input type="text" id="courierSearch" class="form-input" placeholder="🔍 Cari nama petugas..." style="padding:8px 12px; font-size:13px; margin-bottom:0;" oninput="filterCouriers()">
    </div>
    
    <!-- Status Auto Update -->
    <div style="display:flex; align-items:center; justify-content:space-between; font-size:11.5px; color:#475569; background:#f8fafc; padding:10px 12px; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:12px; flex-shrink:0;">
      <div style="display:flex; align-items:center; gap:6px;">
        <span class="pulse-indicator-refresh" id="refreshTimerDot" style="width:6px; height:6px; background:#22c55e; border-radius:50%; display:inline-block;"></span>
        <span id="refreshTimerText" style="font-weight:600;">Update: 10 detik</span>
      </div>
      <button class="btn btn-outline btn-sm" onclick="manualRefresh()" style="padding:4px 10px; font-size:11px; height:auto; background:#fff; font-weight:700;">🔄 Segarkan</button>
    </div>

    <!-- Scroll Daftar Petugas -->
    <div id="courierList" class="courier-list-scroll">
      <!-- Loading State -->
      <div style="text-align:center; padding:50px 0; color:#94a3b8;">
        <div style="font-size:32px; margin-bottom:10px; animation: pulse-refresh 1.5s infinite;">⏳</div>
        <div style="font-size:13px; font-weight:600;">Mengambil lokasi petugas...</div>
      </div>
    </div>
  </div>
  
  <!-- Container Map -->
  <div class="card map-container">
    <div id="liveMap"></div>
    
    <!-- Legend Peta -->
    <div class="map-legend">
      <div style="font-weight: 800; color: #0f172a; margin-bottom: 2px; border-bottom: 1.5px solid #e2e8f0; padding-bottom: 6px; font-size:12px;">KETERANGAN PETA</div>
      <div class="legend-item">
        <span class="legend-color-dot" style="background: #1c6434;"></span>
        <span style="font-weight:700; color:#334155;">🏭 Depot MRH</span>
      </div>
      <div class="legend-item">
        <span class="legend-color-dot" style="background: #22c55e;"></span>
        <span style="font-weight:700; color:#334155;">🛵 Petugas (Aktif / Online)</span>
      </div>
      <div class="legend-item">
        <span class="legend-color-dot" style="background: #94a3b8;"></span>
        <span style="font-weight:700; color:#334155;">🛵 Petugas (Offline)</span>
      </div>
      <div class="legend-item">
        <span class="legend-color-dot" style="background: #3b82f6;"></span>
        <span style="font-weight:700; color:#334155;">♻️ Tugas Daur Ulang Aktif</span>
      </div>
      <div class="legend-item">
        <span class="legend-color-dot" style="background: #f59e0b;"></span>
        <span style="font-weight:700; color:#334155;">🧹 Tugas Clean Up Aktif</span>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
const DEPOT_LAT = <?= DEPOT_LAT ?>;
const DEPOT_LNG = <?= DEPOT_LNG ?>;
const DEPOT_NAME = '<?= addslashes(DEPOT_NAME) ?>';

let mapInstance = null;
let depotMarker = null;

let couriersData = [];
let officerMarkers = {}; // dictionary: officerId -> marker
let taskMarkers = [];    // array of task markers currently on map
let routePolylines = []; // array of polylines currently on map

let selectedCourierId = null;
let searchFilter = '';

let updateIntervalSeconds = 10;
let secondsRemaining = updateIntervalSeconds;
let refreshTimerId = null;

// Initialize Map
function initMap() {
  mapInstance = L.map('liveMap').setView([DEPOT_LAT, DEPOT_LNG], 13);
  
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(mapInstance);

  // Custom Depot Icon
  const depotIcon = L.divIcon({
    className: '',
    html: '<div style="background:#1c6434;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:12px;border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">D</div>',
    iconSize: [28, 28],
    iconAnchor: [14, 14]
  });

  depotMarker = L.marker([DEPOT_LAT, DEPOT_LNG], { icon: depotIcon })
    .addTo(mapInstance)
    .bindPopup(`<strong style="color:#1c6434">🏭 ${DEPOT_NAME}</strong><br><span style="font-size:11px;color:#666">Pusat/Gudang Utama Penjemputan</span>`);
}

// Fetch Locations from AJAX
function fetchLocations() {
  fetch('live_tracking.php?ajax=get_locations')
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        couriersData = res.data;
        updateUI();
      }
    })
    .catch(err => console.error("Error fetching locations:", err));
}

// Update UI (list & map markers)
function updateUI() {
  const container = document.getElementById('courierList');
  const onlineBadge = document.getElementById('onlineCountBadge');
  
  if (couriersData.length === 0) {
    container.innerHTML = `
      <div style="text-align:center; padding:40px 0; color:#94a3b8;">
        <div style="font-size:32px; margin-bottom:8px;">🤷‍♂️</div>
        <div style="font-size:13px; font-weight:600;">Tidak ada petugas aktif.</div>
      </div>
    `;
    onlineBadge.textContent = "0 Aktif";
    onlineBadge.className = "badge badge-gray";
    
    // Clear all courier markers from map
    for (let id in officerMarkers) {
      mapInstance.removeLayer(officerMarkers[id]);
      delete officerMarkers[id];
    }
    clearRouteAndTasks();
    return;
  }

  // Count active/online officers
  const onlineCount = couriersData.filter(c => c.is_online).length;
  onlineBadge.textContent = `${onlineCount} Aktif`;
  if (onlineCount > 0) {
    onlineBadge.className = "badge badge-green";
  } else {
    onlineBadge.className = "badge badge-amber";
  }

  // Filter couriers based on search text
  const filtered = couriersData.filter(c => 
    c.nama.toLowerCase().includes(searchFilter.toLowerCase()) || 
    c.code.toLowerCase().includes(searchFilter.toLowerCase())
  );

  // Render Sidebar List
  container.innerHTML = '';
  filtered.forEach(c => {
    const isSelected = selectedCourierId === c.id;
    const totalTasks = c.tasks.length;
    
    // Status label
    const badgeClass = c.is_online ? 'status-online' : 'status-offline';
    const badgeText = c.is_online ? 'Online' : 'Offline';
    
    // Tasks chip helper
    let taskChipHtml = `<span class="task-chip-count none">🚫 Tidak ada tugas</span>`;
    if (totalTasks > 0) {
      const hasCleanup = c.tasks.some(t => t.tipe === 'cleanup');
      const chipClass = hasCleanup ? 'task-chip-count cleanup' : 'task-chip-count';
      const icon = hasCleanup ? '🧹' : '♻️';
      taskChipHtml = `<span class="${chipClass}">${icon} ${totalTasks} Tugas Aktif</span>`;
    }

    const card = document.createElement('div');
    card.className = `courier-card ${isSelected ? 'selected' : ''}`;
    card.onclick = () => selectCourier(c.id);
    card.innerHTML = `
      <div class="courier-name">${escapeHtml(c.nama)}</div>
      <div class="courier-badge-status ${badgeClass}">${badgeText}</div>
      <div class="courier-meta">
        <span>🆔 <strong>${escapeHtml(c.code)}</strong></span>
        <span>•</span>
        <span>🚚 ${escapeHtml(c.kendaraan || '—')}</span>
      </div>
      <div class="courier-meta" style="margin-top:2px;">
        <span>⏱️ ${c.last_seen_ago}</span>
      </div>
      <div style="margin-top:4px; display:flex; align-items:center; justify-content:space-between;">
        ${taskChipHtml}
        ${c.nomor_wa ? `<a href="https://wa.me/${c.nomor_wa.replace(/[^0-9]/g, '')}" target="_blank" onclick="event.stopPropagation()" style="font-size:16px; display:inline-flex; padding:4px; border-radius:6px; border:1px solid #bbf7d0; background:#f0fdf4; color:#16a34a;" title="Hubungi WhatsApp">💬</a>` : ''}
      </div>
    `;
    container.appendChild(card);
  });

  if (filtered.length === 0) {
    container.innerHTML = `
      <div style="text-align:center; padding:30px 0; color:#94a3b8;">
        <div style="font-size:24px; margin-bottom:6px;">🔍</div>
        <div style="font-size:12px;">Petugas tidak ditemukan.</div>
      </div>
    `;
  }

  // Update Map Markers for Officers
  couriersData.forEach(c => {
    if (c.last_lat === null || c.last_lng === null || c.last_lat == 0 || c.last_lng == 0) {
      // Officer doesn't have a valid location yet
      if (officerMarkers[c.id]) {
        mapInstance.removeLayer(officerMarkers[c.id]);
        delete officerMarkers[c.id];
      }
      return;
    }

    const latlng = [c.last_lat, c.last_lng];
    
    // Popup Html content
    const popupHtml = `
      <div style="font-family:'Inter',sans-serif; min-width:200px; padding:2px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
          <strong style="color:var(--green-700); font-size:13px;">${escapeHtml(c.nama)}</strong>
          <span style="font-size:9px; background:${c.is_online ? '#dcfce7' : '#f1f5f9'}; color:${c.is_online ? '#166534' : '#475569'}; padding:2px 6px; border-radius:10px; font-weight:800">${c.is_online ? 'Online' : 'Offline'}</span>
        </div>
        <div style="font-size:11px; color:#475569; margin-bottom:8px; line-height:1.4;">
          Code: <strong>${escapeHtml(c.code)}</strong><br>
          Kendaraan: ${escapeHtml(c.kendaraan || '—')}<br>
          Terakhir Aktif: ${c.last_seen_ago}<br>
          Koordinat: ${c.last_lat.toFixed(5)}, ${c.last_lng.toFixed(5)}
        </div>
        <div style="display:flex; gap:6px;">
          <button class="btn btn-primary btn-sm" onclick="selectCourier(${c.id})" style="padding:4px 8px; font-size:11px; flex:1; justify-content:center;">🎯 Fokus & Rute</button>
          ${c.nomor_wa ? `<a href="https://wa.me/${c.nomor_wa.replace(/[^0-9]/g, '')}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px; font-size:12px; display:inline-flex; align-items:center; justify-content:center;"><span style="font-size:14px">💬</span></a>` : ''}
        </div>
      </div>
    `;

    if (officerMarkers[c.id]) {
      // Update existing marker position
      officerMarkers[c.id].setLatLng(latlng);
      officerMarkers[c.id].getPopup().setContent(popupHtml);
      
      // Update icon classes if online/offline changed
      const currentIconHtml = createIconHtmlForOfficer(c);
      officerMarkers[c.id].setIcon(L.divIcon({
        className: '',
        html: currentIconHtml,
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      }));
    } else {
      // Create new marker
      const currentIconHtml = createIconHtmlForOfficer(c);
      const icon = L.divIcon({
        className: '',
        html: currentIconHtml,
        iconSize: [30, 30],
        iconAnchor: [15, 15]
      });

      const marker = L.marker(latlng, { icon })
        .addTo(mapInstance)
        .bindPopup(popupHtml);
        
      officerMarkers[c.id] = marker;
      
      // On click, select the courier
      marker.on('click', () => {
        selectCourier(c.id, false); // select but don't pan map again (since marker was clicked)
      });
    }
  });

  // Handle selected courier route & tasks redraw
  if (selectedCourierId !== null) {
    const activeCourier = couriersData.find(c => c.id === selectedCourierId);
    if (activeCourier) {
      drawRouteAndTasksForCourier(activeCourier);
    } else {
      clearRouteAndTasks();
      selectedCourierId = null;
    }
  }
}

// Icon helper function for online/offline styles
function createIconHtmlForOfficer(c) {
  if (c.is_online) {
    return `<div style="position:relative;">
              <div class="pulse-indicator-map" style="position:absolute;top:-2px;left:-2px;width:34px;height:34px;background:rgba(34,197,94,0.35);"></div>
              <div style="position:relative;background:#22c55e;color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);font-weight:bold;">🛵</div>
            </div>`;
  } else {
    return `<div style="background:#94a3b8;color:#fff;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);font-weight:bold;">🛵</div>`;
  }
}

// Select a courier and draw tasks/routes
function selectCourier(id, centerMap = true) {
  selectedCourierId = id;
  
  // Highlight card in sidebar list
  const cards = document.querySelectorAll('.courier-card');
  cards.forEach(card => card.classList.remove('selected'));
  
  const courier = couriersData.find(c => c.id === id);
  if (!courier) return;

  // Find and select the card in the sidebar DOM
  fetchLocations(); // Trigger location fetch to redraw route
  
  if (centerMap && courier.last_lat !== null && courier.last_lng !== null && courier.last_lat != 0 && courier.last_lng != 0) {
    mapInstance.setView([courier.last_lat, courier.last_lng], 15);
    if (officerMarkers[id]) {
      officerMarkers[id].openPopup();
    }
  }
}

// Clear all tasks and routes from the map
function clearRouteAndTasks() {
  taskMarkers.forEach(m => mapInstance.removeLayer(m));
  taskMarkers = [];
  
  routePolylines.forEach(p => mapInstance.removeLayer(p));
  routePolylines = [];
}

// Draw polyline and target task markers for selected courier
function drawRouteAndTasksForCourier(c) {
  clearRouteAndTasks();
  
  if (!c.tasks || c.tasks.length === 0) return;
  
  // Check if courier has a valid starting position
  const startLat = c.last_lat;
  const startLng = c.last_lng;
  if (startLat === null || startLng === null || startLat == 0 || startLng == 0) return;

  const points = [[startLat, startLng]];

  // Task Icons
  const taskPickupIcon = L.divIcon({
    className: '',
    html: '<div style="background:#3b82f6;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.25)">♻️</div>',
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  });

  const taskCleanupIcon = L.divIcon({
    className: '',
    html: '<div style="background:#f59e0b;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.25)">🧹</div>',
    iconSize: [24, 24],
    iconAnchor: [12, 12]
  });

  c.tasks.forEach((t, index) => {
    if (t.lat === null || t.lng === null || t.lat == 0 || t.lng == 0) return;
    
    const latlng = [t.lat, t.lng];
    points.push(latlng);
    
    // Tipe task
    const isCleanup = t.tipe === 'cleanup';
    const taskIcon = isCleanup ? taskCleanupIcon : taskPickupIcon;
    const taskTypeLabel = isCleanup ? 'Clean Up' : 'Daur Ulang';
    
    const popupHtml = `
      <div style="font-family:\'Inter\',sans-serif; min-width:180px;">
        <strong style="color:var(--green-700); font-size:12px;">${t.code}</strong>
        <span style="font-size:9px; background:${isCleanup ? '#fef3c7' : '#dbeafe'}; color:${isCleanup ? '#b45309' : '#1e40af'}; padding:2px 6px; border-radius:10px; margin-left:6px; font-weight:800;">${taskTypeLabel}</span>
        <div style="font-size:11px; margin-top:4px; font-weight:700;">Pemohon: ${escapeHtml(t.nama)}</div>
        <div style="font-size:10px; color:#64748b; margin-top:2px;">📍 ${escapeHtml(t.alamat)}</div>
        <div style="font-size:10px; color:#1e293b; margin-top:2px; font-weight:600;">Status: ${escapeHtml(t.status.toUpperCase())}</div>
      </div>
    `;

    const marker = L.marker(latlng, { icon: taskIcon })
      .addTo(mapInstance)
      .bindPopup(popupHtml);
      
    taskMarkers.push(marker);
  });

  // Draw lines connecting courier to all tasks
  // Use a distinct colored line with directional feel
  const polyline = L.polyline(points, {
    color: '#3b82f6',
    weight: 3.5,
    opacity: 0.8,
    dashArray: '8, 8',
    lineJoin: 'round'
  }).addTo(mapInstance);
  
  routePolylines.push(polyline);
}

// Filter couriers in sidebar
function filterCouriers() {
  searchFilter = document.getElementById('courierSearch').value;
  updateUI();
}

// Timer for auto refresh
function startTimer() {
  secondsRemaining = updateIntervalSeconds;
  updateTimerText();
  
  if (refreshTimerId) clearInterval(refreshTimerId);
  
  refreshTimerId = setInterval(() => {
    secondsRemaining--;
    if (secondsRemaining <= 0) {
      fetchLocations();
      secondsRemaining = updateIntervalSeconds;
    }
    updateTimerText();
  }, 1000);
}

function updateTimerText() {
  document.getElementById('refreshTimerText').textContent = `Update: ${secondsRemaining} detik`;
}

function manualRefresh() {
  fetchLocations();
  startTimer(); // reset timer
}

// Helper to escape HTML characters
function escapeHtml(text) {
  if (!text) return '';
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

window.addEventListener('load', () => {
  initMap();
  fetchLocations();
  startTimer();
});
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>

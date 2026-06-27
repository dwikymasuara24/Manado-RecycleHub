<?php
$page_id    = 'peta';
$page_title = 'Peta & Rute';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/algorithms.php'; // defines DEPOT_LAT/LNG
require_once __DIR__ . '/layout/header.php';

$gmapsKey = getGmapsKey();

// ── Filter tipe layanan (all, pickup, cleanup) ─────────────────
$tipe = $_GET['tipe'] ?? 'all';

// Daur ulang tasks
$pickupTasks = [];
if ($tipe === 'all' || $tipe === 'pickup') {
    $stmt = $db->prepare("
        SELECT pr.id, pr.request_code, pr.nama_pemohon, pr.kecamatan, pr.status, pr.latitude, pr.longitude,
               pr.alamat_jemput, pr.place_name, pr.partner_name, pr.pickup_type,
               'pickup' as tipe_layanan,
               pr.tanggal_jemput AS jadwal_tanggal,
               pr.created_at AS route_created_at
        FROM pickup_requests pr
        WHERE pr.officer_id=? AND pr.tanggal_jemput = CURDATE() AND pr.status NOT IN ('selesai','dibatalkan')
        ORDER BY pr.tanggal_jemput ASC, pr.created_at ASC, pr.id ASC
    ");
    $stmt->execute([$officerId]);
    $pickupTasks = $stmt->fetchAll();
}

// Clean up tasks
$cleanupTasks = [];
if ($tipe === 'all' || $tipe === 'cleanup') {
    $stmt = $db->prepare("
        SELECT cr.id, cr.request_code, cr.nama_pemohon, cr.kecamatan, cr.status, cr.latitude, cr.longitude,
               cr.alamat_jemput, NULL as place_name, NULL as partner_name, NULL as pickup_type,
               'cleanup' as tipe_layanan,
               cr.tanggal_tugas AS jadwal_tanggal,
               cr.created_at AS route_created_at
        FROM cleanup_requests cr
        WHERE cr.officer_id=? AND cr.tanggal_tugas = CURDATE() AND cr.status NOT IN ('selesai','dibatalkan')
        ORDER BY cr.tanggal_tugas ASC, cr.created_at ASC, cr.id ASC
    ");
    $stmt->execute([$officerId]);
    $cleanupTasks = $stmt->fetchAll();
}

$mapTasks = array_merge($pickupTasks, $cleanupTasks);

// Sort merged tasks by schedule time so they are plotted in a stable sequence
usort($mapTasks, function($a, $b) {
    $ta = strtotime($a['jadwal_tanggal'] ?? $a['route_created_at'] ?? 'now') ?: 0;
    $tb = strtotime($b['jadwal_tanggal'] ?? $b['route_created_at'] ?? 'now') ?: 0;
    if ($ta === $tb) return $a['id'] <=> $b['id'];
    return $ta <=> $tb;
});

$geoCount = count(array_filter($mapTasks, fn($t)=>floatval($t['latitude']) != 0 && floatval($t['longitude']) != 0));

?>

<!-- Segments Filter Tipe Layanan -->
<div style="display:flex;gap:6px;margin-bottom:16px;background:#fff;padding:6px;border-radius:12px;border:1.5px solid var(--gml)">
  <a href="?tipe=all" class="btn <?= $tipe==='all'?'btn-green':'btn-outline' ?> btn-sm" style="flex:1;text-align:center;font-weight:700;">Semua (<?= count($pickupTasks) + count($cleanupTasks) ?>)</a>
  <a href="?tipe=pickup" class="btn <?= $tipe==='pickup'?'btn-green':'btn-outline' ?> btn-sm" style="flex:1;text-align:center;font-weight:700;">♻️ Daur Ulang (<?= count($pickupTasks) ?>)</a>
  <a href="?tipe=cleanup" class="btn <?= $tipe==='cleanup'?'btn-green':'btn-outline' ?> btn-sm" style="flex:1;text-align:center;font-weight:700;">🧹 Clean Up (<?= count($cleanupTasks) ?>)</a>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

<div class="card">
  <div class="card-title" style="display:flex;justify-content:between;align-items:center;">
    <div style="display:flex;align-items:center;gap:6px;">
      <div class="ct-icon">🗺️</div> Peta Rute &amp; Live Traffic
    </div>
      <span style="font-size:10px;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:10px;font-weight:700;margin-left:auto;">OPENSTREETMAP</span>
  </div>
  <div id="officerMap" style="height:400px; width:100%; border-radius:8px; border:1px solid #ddd; background:#f4f4f4;"></div>
  <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
    <button class="btn btn-green btn-sm" onclick="centerOnMe()">📍 Lokasi Saya</button>
    <button class="btn btn-outline btn-sm" onclick="fitAllTasks()">👁️ Semua Titik</button>
    <button class="btn btn-outline btn-sm" onclick="calcRouteNN()">🔁 Hitung Rute NN</button>
    <button class="btn btn-blue btn-sm" onclick="openGoogleMapsTraffic()">🚗 Navigasi Real Traffic</button>
    <span id="myLocStatus" style="font-size:11px;color:#888;align-self:center"></span>
  </div>
  <div id="routeInfo" style="margin-top:10px"></div>
</div>

<div class="card">
  <div class="card-title"><div class="ct-icon">🏭</div> Depot MRH</div>
  <div style="font-size:13px;color:#555;line-height:1.8">
    <strong><?= defined('DEPOT_NAME') ? DEPOT_NAME : 'Depot MRH — Manado Recycle Hub' ?></strong><br>
    Koordinat: <?= DEPOT_LAT ?>, <?= DEPOT_LNG ?><br>
    <span style="color:#888;font-family:var(--ui);font-size:12px"><?= $geoCount ?> titik aktif memiliki koordinat GPS</span>
  </div>
  <a href="https://maps.app.goo.gl/qH4tHy5r5DgKtgsdA" target="_blank" class="btn btn-blue btn-sm" style="margin-top:10px;display:inline-flex">Buka Depot di Google Maps →</a>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// Data tugas untuk peta
const DEPOT_LAT = <?= DEPOT_LAT ?>;
const DEPOT_LNG = <?= DEPOT_LNG ?>;
const MAP_TASKS = <?= json_encode($mapTasks, JSON_UNESCAPED_UNICODE) ?>;
const DB_ROUTE  = [];
const gmapsActive = false;

let mapInstance = null;
let markers = [];
let myMarker = null;
let myLatLng = null;
let routePolyline = null;
let directionsRendererInstance = null;
let nnRoute = [];

function initMap() {
  if (gmapsActive && typeof google !== 'undefined' && google.maps) {
    initGoogleMap();
  } else {
    initLeafletMap();
  }
}

function initGoogleMap() {
  const depotLoc = { lat: DEPOT_LAT, lng: DEPOT_LNG };
  mapInstance = new google.maps.Map(document.getElementById('officerMap'), {
    center: depotLoc,
    zoom: 13,
    styles: [
      {
        "featureType": "poi",
        "stylers": [{ "visibility": "off" }]
      }
    ]
  });

  // Enable Traffic Layer
  const trafficLayer = new google.maps.TrafficLayer();
  trafficLayer.setMap(mapInstance);

  // Depot Marker
  new google.maps.Marker({
    position: depotLoc,
    map: mapInstance,
    title: "Depot MRH",
    icon: {
      url: "https://maps.google.com/mapfiles/ms/icons/green-dot.png"
    }
  });

  // Plot Task Markers
  const geoT = MAP_TASKS.filter(t => parseFloat(t.latitude) !== 0 && parseFloat(t.longitude) !== 0);
  geoT.forEach((t, i) => {
    const lat = parseFloat(t.latitude);
    const lng = parseFloat(t.longitude);
    const pinColor = t.tipe_layanan === 'cleanup' ? 'orange' : 'blue';
    const marker = new google.maps.Marker({
      position: { lat, lng },
      map: mapInstance,
      label: (i + 1).toString(),
      icon: `https://maps.google.com/mapfiles/ms/icons/${pinColor}-dot.png`
    });

    let popupHtml = `<div style="font-family: 'Nunito', sans-serif; padding: 6px; min-width: 200px;">`;
    popupHtml += `<strong style="color:#1c6434">${t.request_code}</strong>`;
    popupHtml += `<span style="font-size:10px; padding:2px 6px; border-radius:10px; margin-left:6px; background:${t.tipe_layanan === 'cleanup' ? '#fef08a' : '#dbeafe'}; color:${t.tipe_layanan === 'cleanup' ? '#854d0e' : '#1e40af'}">${t.tipe_layanan === 'cleanup' ? 'Clean Up' : 'Daur Ulang'}</span>`;
    popupHtml += `<div style="font-size:13px;margin-top:4px;font-weight:700;">Pemohon: ${t.nama_pemohon}</div>`;
    if (t.place_name) {
      popupHtml += `<div style="color:#1d4ed8;font-size:11px;margin-top:2px"><strong>Place:</strong> ${t.place_name}</div>`;
    }
    if (t.partner_name) {
      popupHtml += `<div style="color:#1e293b;font-size:11px;margin-top:2px"><strong>PIC/Partner:</strong> ${t.partner_name}</div>`;
    }
    if (t.pickup_type) {
      const pkg = t.pickup_type === 'B' ? 'Keranjang' : (t.pickup_type === 'S' ? 'Karung' : t.pickup_type);
      popupHtml += `<div style="color:#64748b;font-size:11px;margin-top:2px"><strong>Wadah:</strong> ${pkg}</div>`;
    }
    popupHtml += `<div style="font-size:11px;color:#666;margin-top:2px;">📍 ${t.alamat_jemput}</div>`;
    popupHtml += `<a href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}&travelmode=driving" target="_blank" style="font-size:12px;color:#1c6434;font-weight:600;display:block;margin-top:8px">🧭 Navigasi</a></div>`;

    const infoWindow = new google.maps.InfoWindow({
      content: popupHtml
    });

    marker.addListener('click', () => {
      infoWindow.open(mapInstance, marker);
    });

    markers.push(marker);
  });

  // Load My Location
  centerOnMeQuietly();

  // Draw DB route if it exists
  if (DB_ROUTE.length > 0) {
    drawIntegratedRouteGmaps();
  }
}

function initLeafletMap() {
  mapInstance = L.map('officerMap').setView([DEPOT_LAT, DEPOT_LNG], 13);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19
  }).addTo(mapInstance);

  // Depot marker
  const depotIcon = L.divIcon({
    className: '',
    html: '<div style="background:#1c6434;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3)">D</div>',
    iconSize: [26, 26],
    iconAnchor: [13, 13]
  });
  L.marker([DEPOT_LAT, DEPOT_LNG], { zIndexOffset: 1000, icon: depotIcon })
    .addTo(mapInstance)
    .bindPopup('<strong style="color:#1c6434">🏭 Depot MRH</strong>');

  // Task markers
  const geoT = MAP_TASKS.filter(t => parseFloat(t.latitude) !== 0 && parseFloat(t.longitude) !== 0);
  geoT.forEach((t, i) => {
    const pinColor = t.tipe_layanan === 'cleanup' ? '#f59e0b' : '#3b82f6';
    const icon = L.divIcon({
      className: '',
      html: `<div style="background:${pinColor};color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:10px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.25)">${i + 1}</div>`,
      iconSize: [22, 22],
      iconAnchor: [11, 11]
    });
    let popupHtml = `<div style="font-family:'Nunito',sans-serif;padding:4px;min-width:180px;">`;
    popupHtml += `<strong style="color:#1c6434">${t.request_code}</strong>`;
    popupHtml += `<span style="font-size:10px;padding:2px 6px;border-radius:10px;margin-left:6px;background:${t.tipe_layanan==='cleanup'?'#fef08a':'#dbeafe'};color:${t.tipe_layanan==='cleanup'?'#854d0e':'#1e40af'}">${t.tipe_layanan==='cleanup'?'Clean Up':'Daur Ulang'}</span>`;
    popupHtml += `<div style="font-size:12px;font-weight:700;margin-top:4px;">Pemohon: ${t.nama_pemohon}</div>`;
    if (t.place_name) {
      popupHtml += `<div style="color:#1d4ed8;font-size:11px;margin-top:2px"><strong>Place:</strong> ${t.place_name}</div>`;
    }
    if (t.partner_name) {
      popupHtml += `<div style="color:#1e293b;font-size:11px;margin-top:2px"><strong>PIC/Partner:</strong> ${t.partner_name}</div>`;
    }
    if (t.pickup_type) {
      const pkg = t.pickup_type === 'B' ? 'Keranjang' : (t.pickup_type === 'S' ? 'Karung' : t.pickup_type);
      popupHtml += `<div style="color:#64748b;font-size:11px;margin-top:2px"><strong>Wadah:</strong> ${pkg}</div>`;
    }
    popupHtml += `<div style="font-size:11px;color:#666;margin-top:2px;">📍 ${t.alamat_jemput}</div>`;
    popupHtml += `<a href="https://www.google.com/maps/dir/?api=1&destination=${t.latitude},${t.longitude}" target="_blank" style="font-size:12px;color:#1c6434;font-weight:600;display:block;margin-top:8px">🧭 Navigasi</a></div>`;

    const marker = L.marker([parseFloat(t.latitude), parseFloat(t.longitude)], { icon })
      .addTo(mapInstance)
      .bindPopup(popupHtml);
    markers.push(marker);
  });

  centerOnMeQuietly();

  if (geoT.length > 0) {
    const b = L.latLngBounds([[DEPOT_LAT, DEPOT_LNG], ...geoT.map(t => [parseFloat(t.latitude), parseFloat(t.longitude)])]);
    mapInstance.fitBounds(b.pad(0.15));
  }

  if (DB_ROUTE.length > 0) {
    setTimeout(() => drawIntegratedRouteLeaflet(), 500);
  }
}

function centerOnMeQuietly() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(pos => {
      myLatLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      document.getElementById('myLocStatus').textContent = myLatLng.lat.toFixed(5) + ', ' + myLatLng.lng.toFixed(5);
      
      if (gmapsActive && mapInstance) {
        myMarker = new google.maps.Marker({
          position: myLatLng,
          map: mapInstance,
          title: "Lokasi Saya",
          icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 8,
            fillColor: "#3b82f6",
            fillOpacity: 1,
            strokeColor: "#ffffff",
            strokeWeight: 2
          }
        });
      } else if (mapInstance) {
        myMarker = L.circleMarker([myLatLng.lat, myLatLng.lng], {
          radius: 8,
          fillColor: '#3b82f6',
          fillOpacity: 1,
          color: '#fff',
          weight: 3
        }).addTo(mapInstance);
      }
    });
  }
}

function centerOnMe() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(pos => {
      myLatLng = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      document.getElementById('myLocStatus').textContent = myLatLng.lat.toFixed(5) + ', ' + myLatLng.lng.toFixed(5);
      
      if (gmapsActive && mapInstance) {
        mapInstance.setCenter(myLatLng);
        mapInstance.setZoom(16);
        if (myMarker) {
          myMarker.setPosition(myLatLng);
        } else {
          myMarker = new google.maps.Marker({
            position: myLatLng,
            map: mapInstance,
            title: "Lokasi Saya",
            icon: {
              path: google.maps.SymbolPath.CIRCLE,
              scale: 8,
              fillColor: "#3b82f6",
              fillOpacity: 1,
              strokeColor: "#ffffff",
              strokeWeight: 2
            }
          });
        }
      } else if (mapInstance) {
        mapInstance.setView([myLatLng.lat, myLatLng.lng], 16);
        if (myMarker) {
          myMarker.setLatLng([myLatLng.lat, myLatLng.lng]);
        } else {
          myMarker = L.circleMarker([myLatLng.lat, myLatLng.lng], {
            radius: 8,
            fillColor: '#3b82f6',
            fillOpacity: 1,
            color: '#fff',
            weight: 3
          }).addTo(mapInstance);
        }
      }
    }, () => showToast('danger', 'GPS tidak tersedia'));
  }
}

function fitAllTasks() {
  if (!mapInstance) return;
  const geo = MAP_TASKS.filter(t => t.latitude && t.longitude);
  
  if (gmapsActive) {
    const bounds = new google.maps.LatLngBounds();
    bounds.extend({ lat: DEPOT_LAT, lng: DEPOT_LNG });
    geo.forEach(t => bounds.extend({ lat: parseFloat(t.latitude), lng: parseFloat(t.longitude) }));
    if (myLatLng) bounds.extend(myLatLng);
    mapInstance.fitBounds(bounds);
  } else {
    const b = L.latLngBounds([[DEPOT_LAT, DEPOT_LNG]]);
    geo.forEach(t => b.extend([parseFloat(t.latitude), parseFloat(t.longitude)]));
    if (myLatLng) b.extend([myLatLng.lat, myLatLng.lng]);
    mapInstance.fitBounds(b.pad(0.15));
  }
}

function drawIntegratedRouteGmaps() {
  if (!DB_ROUTE.length) return;
  const waypoints = DB_ROUTE.map(r => ({
    lat: r.latitude ? parseFloat(r.latitude) : DEPOT_LAT,
    lng: r.longitude ? parseFloat(r.longitude) : DEPOT_LNG
  }));
  drawGmapsRoute(waypoints);
}

function drawIntegratedRouteLeaflet() {
  if (!DB_ROUTE.length) return;
  const vis = DB_ROUTE.map(r => ({
    lat: r.latitude ? parseFloat(r.latitude) : DEPOT_LAT,
    lng: r.longitude ? parseFloat(r.longitude) : DEPOT_LNG
  }));
  drawLeafletRoute(vis);
}

function drawGmapsRoute(waypoints) {
  if (directionsRendererInstance) {
    directionsRendererInstance.setMap(null);
  }

  const directionsService = new google.maps.DirectionsService();
  directionsRendererInstance = new google.maps.DirectionsRenderer({
    map: mapInstance,
    suppressMarkers: true,
    polylineOptions: {
      strokeColor: '#1c6434',
      strokeOpacity: 0.8,
      strokeWeight: 6
    }
  });

  const origin = waypoints[0];
  const destination = waypoints[waypoints.length - 1];
  const gWaypoints = waypoints.slice(1, -1).map(w => ({
    location: new google.maps.LatLng(w.lat, w.lng),
    stopover: true
  }));

  directionsService.route({
    origin: new google.maps.LatLng(origin.lat, origin.lng),
    destination: new google.maps.LatLng(destination.lat, destination.lng),
    waypoints: gWaypoints,
    travelMode: google.maps.TravelMode.DRIVING,
    drivingOptions: {
      departureTime: new Date(),
      trafficModel: google.maps.TrafficModel.BEST_GUESS
    }
  }, (response, status) => {
    if (status === 'OK') {
      directionsRendererInstance.setDirections(response);
      const route = response.routes[0];
      let totalDistance = 0;
      let totalDuration = 0;
      route.legs.forEach(leg => {
        totalDistance += leg.distance.value;
        totalDuration += leg.duration_in_traffic ? leg.duration_in_traffic.value : leg.duration.value;
      });
      const km = (totalDistance / 1000).toFixed(2);
      const mins = Math.round(totalDuration / 60);

      document.getElementById('routeInfo').innerHTML = `
        <div style="background:#f0fdf4;padding:12px 16px;border-radius:10px;border:1.5px solid #bbf7d0;font-size:13px;color:#14532d;margin-top:10px">
          <strong>✓ Rute Google Maps Real Traffic:</strong> ${km} km &bull; ${waypoints.length - 2} stop &bull; Est. ${mins} menit (live traffic)
        </div>
      `;
    } else {
      showToast('danger', 'Directions request failed: ' + status);
    }
  });
}

function drawLeafletRoute(waypoints) {
  if (routePolyline) {
    mapInstance.removeLayer(routePolyline);
    routePolyline = null;
  }
  const coords = waypoints.map(v => v.lng + ',' + v.lat).join(';');
  document.getElementById('routeInfo').innerHTML = '<div style="background:#f0fdf4;padding:10px 14px;border-radius:8px;border:1px solid #bbf7d0;font-size:13px"><strong>✨ Rute:</strong> ' + waypoints.length + ' titik rute ditemukan.<br><span style="color:#888">Memuat peta jalan...</span></div>';

  fetch('https://router.project-osrm.org/route/v1/driving/' + coords + '?overview=full&geometries=geojson')
    .then(r => r.json()).then(data => {
      if (data.code === 'Ok' && data.routes.length) {
        routePolyline = L.geoJSON(data.routes[0].geometry, { style: { color: '#1c6434', weight: 5, opacity: .8 } }).addTo(mapInstance);
        const km = (data.routes[0].distance / 1000).toFixed(2), mn = Math.round(data.routes[0].duration / 60);

        document.getElementById('routeInfo').innerHTML = `
          <div style="background:#f0fdf4;padding:10px 14px;border-radius:8px;border:1px solid #bbf7d0;font-size:13px">
            <strong>✨ Rute:</strong> ${km} km &bull; ${waypoints.length - 2} stop &bull; Est. ${mn} mnt
          </div>
        `;
        mapInstance.fitBounds(routePolyline.getBounds().pad(0.1));
      }
    }).catch(() => {});
}

function calcRouteNN() {
  const geo = MAP_TASKS.filter(t => t.latitude && t.longitude);
  if (!geo.length) {
    showToast('danger', 'Tidak ada titik GPS');
    return;
  }

  function hav(a, b) {
    const R = 6371;
    const dLat = (b.lat - a.lat) * Math.PI / 180;
    const dLng = (b.lng - a.lng) * Math.PI / 180;
    return R * 2 * Math.atan2(
      Math.sqrt(Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2),
      Math.sqrt(1 - Math.sin(dLat / 2) ** 2 - Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2)
    );
  }

  // Start dari Lokasi Saya (jika GPS aktif) atau Depot
  const startPoint = myLatLng ? { lat: myLatLng.lat, lng: myLatLng.lng } : { lat: DEPOT_LAT, lng: DEPOT_LNG };
  const vis = [startPoint];
  const unvis = geo.map(t => ({ lat: parseFloat(t.latitude), lng: parseFloat(t.longitude), code: t.request_code, nama: t.nama_pemohon, tipe: t.tipe_layanan }));
  let tot = 0;

  while (unvis.length) {
    const last = vis[vis.length - 1];
    let best = null;
    let bestD = Infinity;
    let bestI = -1;
    unvis.forEach((t, i) => {
      const d = hav(last, t);
      if (d < bestD) {
        bestD = d;
        best = t;
        bestI = i;
      }
    });
    vis.push(best);
    tot += bestD;
    unvis.splice(bestI, 1);
  }

  // Akhiri di Depot
  vis.push({ lat: DEPOT_LAT, lng: DEPOT_LNG });
  tot += hav(vis[vis.length - 2], { lat: DEPOT_LAT, lng: DEPOT_LNG });

  nnRoute = vis;

  if (gmapsActive) {
    drawGmapsRoute(vis);
  } else {
    drawLeafletRoute(vis);
  }
  showToast('success', 'Rute berhasil dihitung!');
}

function openGoogleMapsTraffic() {
  let routeToUse = [];
  if (nnRoute && nnRoute.length > 0) {
    routeToUse = nnRoute;
  } else if (DB_ROUTE && DB_ROUTE.length > 0) {
    routeToUse = DB_ROUTE.map(r => ({ lat: parseFloat(r.latitude), lng: parseFloat(r.longitude) }));
  } else {
    const geo = MAP_TASKS.filter(t => t.latitude && t.longitude);
    if (geo.length > 0) {
      routeToUse = [{ lat: DEPOT_LAT, lng: DEPOT_LNG }, ...geo.map(t => ({ lat: parseFloat(t.latitude), lng: parseFloat(t.longitude) })), { lat: DEPOT_LAT, lng: DEPOT_LNG }];
    }
  }

  if (routeToUse.length < 2) {
    showToast('danger', 'Tidak ada rute yang tersedia untuk dinavigasi.');
    return;
  }

  const origin = `${routeToUse[0].lat},${routeToUse[0].lng}`;
  const destination = `${routeToUse[routeToUse.length - 1].lat},${routeToUse[routeToUse.length - 1].lng}`;
  const waypoints = routeToUse.slice(1, -1).map(w => `${w.lat},${w.lng}`).join('|');
  const mapsUrl = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}&waypoints=${encodeURIComponent(waypoints)}&travelmode=driving`;
  window.open(mapsUrl, '_blank');
}

window.addEventListener('load', initMap);
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>

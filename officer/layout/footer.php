</div><!-- /main-wrap -->

<!-- ══ MODAL: UPDATE STATUS ══ -->
<div class="modal-backdrop" id="updateModal">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <div class="modal-title" id="modalTitle">Update Status</div>
    <input type="hidden" id="modalPickupId">
    <div class="form-group">
      <label class="form-label">Status Baru</label>
      <select class="form-input" id="modalStatus" onchange="toggleBeratField()">
        <option value="dijadwalkan">📅 Dijadwalkan</option>
        <option value="dalam_perjalanan">🛵 Dalam Perjalanan</option>
        <option value="sedang_diproses">🔄 Sedang Diproses</option>
        <option value="selesai">✅ Selesai</option>
        <option value="dibatalkan">❌ Dibatalkan</option>
      </select>
    </div>
    <div class="form-group" id="wadahGroup">
      <label class="form-label">Wadah/Kemasan Sampah</label>
      <select class="form-input" id="modalWadah">
        <option value="">-- Pilih Wadah --</option>
        <option value="B">Keranjang</option>
        <option value="S">Karung</option>
        <option value="Lainnya">Lainnya</option>
      </select>
    </div>
    <div class="form-group" id="itemsGroup" style="display:none">
      <label class="form-label">Berat Per Kategori Sampah</label>
      <div id="modalItemsList" style="display:flex;flex-direction:column;gap:8px;background:#f8fafc;padding:10px;border-radius:8px;border:1px solid #e2e8f0;max-height:200px;overflow-y:auto;">
        <!-- Dynamically filled with categories & input fields -->
      </div>
    </div>
    <div class="form-group" id="beratGroup" style="display:none">
      <label class="form-label">Berat Aktual Total Sampah (kg)</label>
      <input type="number" class="form-input" id="modalBerat" step="0.01" min="0" placeholder="Contoh: 12.5">
    </div>
    <div class="form-group" id="priceGroup" style="display:none">
      <label class="form-label">Price per kg</label>
      <input type="number" class="form-input" id="modalPrice" step="0.01" min="0" placeholder="Contoh: 1500">
    </div>
    <div class="form-group" id="infoCatatanGroup" style="display:none">
      <label class="form-label">Catatan / Instruksi Sebelumnya</label>
      <div id="infoCatatanText" style="padding:10px 12px;background:#fffde7;border-left:4px solid #eab308;border-radius:6px;font-size:12.5px;color:#475569;font-weight:600;line-height:1.4"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Catatan Petugas (opsional)</label>
      <textarea class="form-input" id="modalCatatan" placeholder="Contoh: Warga tidak ada, konfirmasi via WA..."></textarea>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button class="btn btn-outline btn-full" onclick="closeUpdateModal()">Batal</button>
      <button class="btn btn-green btn-full" id="btnSubmitUpdate" onclick="submitUpdate()">💾 Simpan</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: KENDALA ══ -->
<div class="modal-backdrop" id="kendalaModal">
  <div class="modal-sheet">
    <div class="modal-handle"></div>
    <div class="modal-title">🚨 Laporkan Kendala</div>
    <input type="hidden" id="kendalaPickupId">
    <div class="form-group">
      <label class="form-label">Jenis Kendala</label>
      <select class="form-input" id="kendalaJenis">
        <option value="[KENDALA] Warga tidak ada di lokasi">Warga tidak ada di lokasi</option>
        <option value="[KENDALA] Akses jalan ditutup">Akses jalan ditutup</option>
        <option value="[KENDALA] Kendaraan bermasalah">Kendaraan bermasalah</option>
        <option value="[KENDALA] Sampah melebihi kapasitas">Sampah melebihi kapasitas</option>
        <option value="[KENDALA] Koordinat salah / susah ditemukan">Koordinat salah / susah ditemukan</option>
        <option value="[KENDALA] Lainnya">Lainnya (isi catatan)</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Keterangan Tambahan</label>
      <textarea class="form-input" id="kendalaCatatan" rows="3" placeholder="Deskripsikan kendala lebih detail..."></textarea>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px">
      <button class="btn btn-outline btn-full" onclick="closeKendalaModal()">Batal</button>
      <button class="btn btn-full" style="background:#ef4444;color:#fff;border:none" onclick="submitKendala()">🚨 Kirim Laporan</button>
    </div>
  </div>
</div>

<!-- ══ SHARED JAVASCRIPT ══ -->
<script>
const OFFICER_ID = <?= $officerId ?? 0 ?>;
const DEPOT_LAT  = <?= defined('DEPOT_LAT') ? DEPOT_LAT : 1.476362 ?>;
const DEPOT_LNG  = <?= defined('DEPOT_LNG') ? DEPOT_LNG : 124.832498 ?>;

// ── Sidebar ────────────────────────────────────────────────────
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); document.getElementById('sidebarOverlay').classList.toggle('open'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('open'); }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeSidebar(); });

const SIDEBAR_COLLAPSED_KEY = 'mrh_officer_sidebar_collapsed';
function toggleSidebarCollapse() {
  const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
  try { localStorage.setItem(SIDEBAR_COLLAPSED_KEY, isCollapsed ? '1' : '0'); } catch(e) {}
}

// ── Collapsible Nav Groups ──
const NAV_STATE_KEY = 'mrh_officer_nav_state';
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

// ── Toast ──────────────────────────────────────────────────────
function showToast(type, msg){
  const c=document.getElementById('toastContainer'), t=document.createElement('div');
  t.className='toast toast-'+type;
  
  const icon = type === 'success' ? '✅' : (type === 'danger' ? '❌' : 'ℹ️');
  t.innerHTML = `
    <div class="toast-icon" style="font-size: 42px; line-height: 1;">${icon}</div>
    <div class="toast-msg" style="font-size: 13.5px; font-weight: 700; color: #1e293b; line-height: 1.5; margin-bottom: 8px;">${msg}</div>
    <button type="button" style="background: var(--green); color: white; border: none; border-radius: 8px; padding: 6px 20px; font-size: 12px; font-weight: 700; cursor: pointer; width: 100%;" onclick="this.parentElement.remove()">Tutup</button>
  `;
  c.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'toast-fade-out 0.25s ease-in forwards';
    setTimeout(() => t.remove(), 250);
  }, 4500);
}

// ── Modal update status ────────────────────────────────────────
function recalculateTotalWeight() {
  const inputs = document.querySelectorAll('.item-aktual-weight');
  let sum = 0;
  let hasValue = false;
  inputs.forEach(inp => {
    const val = parseFloat(inp.value);
    if (!isNaN(val) && val >= 0) {
      sum += val;
      hasValue = true;
    }
  });
  if (hasValue) {
    document.getElementById('modalBerat').value = sum.toFixed(2);
  }
}

async function openUpdateModal(task){
  document.getElementById('modalPickupId').value=task.id;
  document.getElementById('modalTitle').textContent=task.request_code+' — '+task.nama_pemohon;
  document.getElementById('modalStatus').value=(task.status in {dijadwalkan:1,dalam_perjalanan:1,sedang_diproses:1,selesai:1,dibatalkan:1})?task.status:'sedang_diproses';
  document.getElementById('modalWadah').value = task.pickup_type || '';

  // Tampilkan catatan instruksi sebagai informasi saja
  const infoGroup = document.getElementById('infoCatatanGroup');
  const infoText = document.getElementById('infoCatatanText');
  if (task.catatan_officer && task.catatan_officer.trim() !== '') {
    infoText.textContent = task.catatan_officer;
    infoGroup.style.display = 'block';
  } else {
    infoText.textContent = '';
    infoGroup.style.display = 'none';
  }

  document.getElementById('modalCatatan').value='';
  document.getElementById('modalBerat').value=task.berat_total_kg || '';
  document.getElementById('modalPrice').value=task.price_per_kg || '';
  
  // Render empty items list first
  const itemsList = document.getElementById('modalItemsList');
  itemsList.innerHTML = '<div style="font-size:12px;color:#888;">Memuat detail sampah...</div>';

  toggleBeratField();
  document.getElementById('updateModal').classList.add('open');
  document.body.style.overflow='hidden';

  // Fetch items detail
  try {
    const fd = new FormData();
    fd.append('ajax', 'get_details');
    fd.append('id', task.id);
    fd.append('type', 'daur_ulang');
    const r = await fetch('api.php?oid=' + OFFICER_ID, { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok && d.data && d.data.items) {
      itemsList.innerHTML = '';
      d.data.items.forEach(item => {
        const itemRow = document.createElement('div');
        itemRow.style.display = 'flex';
        itemRow.style.alignItems = 'center';
        itemRow.style.justifyContent = 'space-between';
        itemRow.style.gap = '8px';
        itemRow.style.background = '#fff';
        itemRow.style.padding = '6px 8px';
        itemRow.style.borderRadius = '6px';
        itemRow.style.border = '1px solid #cbd5e1';
        itemRow.style.marginBottom = '6px';

        itemRow.innerHTML = `
          <div style="flex:1;font-size:12px;font-weight:700;color:#334155">
            ${item.category_name}
            <div style="font-size:10px;color:#64748b;font-weight:400">Est: ${item.estimasi_kg || 0} kg</div>
          </div>
          <div style="width:110px;">
            <input type="number" class="form-input item-aktual-weight" 
                   data-item-id="${item.id}" 
                   step="0.01" min="0" 
                   value="${item.aktual_kg !== null ? item.aktual_kg : ''}" 
                   placeholder="Aktual (kg)" 
                   style="padding:6px 8px;font-size:12px;"
                   oninput="recalculateTotalWeight()">
          </div>
        `;
        itemsList.appendChild(itemRow);
      });
      if (d.data.items.length === 0) {
        itemsList.innerHTML = '<div style="font-size:12px;color:#ef4444;">Tidak ada kategori sampah.</div>';
      }
    } else {
      itemsList.innerHTML = '<div style="font-size:12px;color:#ef4444;">Gagal memuat kategori sampah.</div>';
    }
  } catch (e) {
    itemsList.innerHTML = '<div style="font-size:12px;color:#ef4444;">Error memuat detail.</div>';
  }
}
function closeUpdateModal(){ document.getElementById('updateModal').classList.remove('open'); document.body.style.overflow=''; }
function toggleBeratField(){ 
  const show = document.getElementById('modalStatus').value==='selesai';
  document.getElementById('beratGroup').style.display=show?'block':'none'; 
  document.getElementById('priceGroup').style.display=show?'block':'none'; 
  document.getElementById('itemsGroup').style.display=show?'block':'none'; 
}
document.getElementById('updateModal').addEventListener('click',e=>{if(e.target===document.getElementById('updateModal'))closeUpdateModal();});

async function submitUpdate(){
  const id=document.getElementById('modalPickupId').value;
  const status=document.getElementById('modalStatus').value;
  const catatan=document.getElementById('modalCatatan').value;
  const berat=document.getElementById('modalBerat').value;
  const price=document.getElementById('modalPrice').value;
  const wadah=document.getElementById('modalWadah').value;
  const btn=document.getElementById('btnSubmitUpdate');
  btn.textContent='Menyimpan...'; btn.disabled=true;
  try{
    const fd=new FormData();
    fd.append('ajax','update_status'); 
    fd.append('pickup_id',id); 
    fd.append('status',status);
    fd.append('pickup_type',wadah);
    if(catatan.trim() !== '') {
      fd.append('catatan_officer',catatan);
    }
    if(berat) fd.append('berat_aktual',berat);
    if(price) fd.append('price_per_kg',price);
    
    // Gather individual weights
    const inputs = document.querySelectorAll('.item-aktual-weight');
    const itemWeights = [];
    inputs.forEach(inp => {
      itemWeights.push({
        id: inp.getAttribute('data-item-id'),
        weight: inp.value
      });
    });
    fd.append('item_weights', JSON.stringify(itemWeights));

    const r=await fetch('api.php?oid='+OFFICER_ID,{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){showToast('success','Status diperbarui!'); closeUpdateModal(); setTimeout(()=>location.reload(),900);}
    else showToast('danger','Gagal update status.');
  }catch(e){showToast('danger','Error: '+e.message);}
  btn.textContent='💾 Simpan'; btn.disabled=false;
}

// ── Modal kendala ──────────────────────────────────────────────
function openKendalaModal(pickupId){
  document.getElementById('kendalaPickupId').value=pickupId;
  document.getElementById('kendalaModal').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeKendalaModal(){ document.getElementById('kendalaModal').classList.remove('open'); document.body.style.overflow=''; }
document.getElementById('kendalaModal').addEventListener('click',e=>{if(e.target===document.getElementById('kendalaModal'))closeKendalaModal();});

async function submitKendala(){
  const id=document.getElementById('kendalaPickupId').value;
  const jenis=document.getElementById('kendalaJenis').value;
  const catatan=document.getElementById('kendalaCatatan').value;
  const fullCat=jenis+(catatan?' — '+catatan:'');
  const fd=new FormData();
  fd.append('ajax','update_status'); 
  fd.append('pickup_id',id); 
  fd.append('status','dijadwalkan'); 
  fd.append('catatan_officer',fullCat);
  fd.append('is_kendala', 1);
  try{
    const r=await fetch('api.php?oid='+OFFICER_ID,{method:'POST',body:fd});
    const d=await r.json();
    if(d.ok){showToast('success','Kendala dilaporkan!'); closeKendalaModal(); setTimeout(()=>location.reload(),900);}
    else showToast('danger','Gagal kirim laporan.');
  }catch(e){showToast('danger','Error: '+e.message);}
}

// ── GPS Tracking ───────────────────────────────────────────────
let myLatLng=null, gpsLastSent=0;
function startGPS(){
  if(!navigator.geolocation) return;
  navigator.geolocation.watchPosition(pos=>{
    myLatLng={lat:pos.coords.latitude,lng:pos.coords.longitude};
    const dot=document.getElementById('gpsDot');
    if(dot) dot.style.background='#4ade80';
    if(Date.now()-gpsLastSent>60000){
      gpsLastSent=Date.now();
      const fd=new FormData(); fd.append('ajax','update_location'); fd.append('lat',myLatLng.lat); fd.append('lng',myLatLng.lng);
      fetch('api.php?oid='+OFFICER_ID,{method:'POST',body:fd});
    }
  },()=>{const dot=document.getElementById('gpsDot'); if(dot) dot.style.background='#f59e0b';},{enableHighAccuracy:true,maximumAge:30000,timeout:10000});
}
startGPS();

// ── PWA Service Worker Registration ────────────────────────────
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    // Gunakan root-relative path untuk memastikan kompatibilitas pendaftaran Service Worker
    const swPath = '<?= parse_url(baseUrl("service-worker.js"), PHP_URL_PATH) ?>';
    navigator.serviceWorker.register(swPath)
      .then(reg => console.log('SW Registered', reg.scope))
      .catch(err => console.error('SW Registration Failed', err));
  });
}

// ── PWA Install Prompt Handler ──────────────────────────────────
let deferredPrompt;
const btnInstall = document.getElementById('btnInstallPWA');

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  if (btnInstall) {
    btnInstall.style.display = 'flex';
  }
});

// Jika tidak diprompt otomatis karena masalah protokol HTTP (bukan HTTPS / localhost)
setTimeout(() => {
  if (!deferredPrompt && btnInstall) {
    const isLocal = ['localhost', '127.0.0.1'].includes(window.location.hostname);
    const isHttps = window.location.protocol === 'https:';
    if (!isHttps && !isLocal) {
      btnInstall.style.display = 'flex';
      btnInstall.style.opacity = '0.6';
      btnInstall.title = 'PWA membutuhkan HTTPS untuk instalasi di HP';
      btnInstall.addEventListener('click', (e) => {
        e.preventDefault();
        alert('Instalasi Aplikasi Gagal:\n\nAplikasi hanya dapat diunduh langsung di HP jika diakses melalui koneksi aman (HTTPS). Saat ini Anda menggunakan koneksi HTTP biasa.\n\nSilakan hubungi administrator untuk memasang SSL (HTTPS) atau jalankan di localhost.');
      });
    }
  }
}, 3000);

if (btnInstall) {
  btnInstall.addEventListener('click', async (e) => {
    e.preventDefault();
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    console.log(`User response to install: ${outcome}`);
    deferredPrompt = null;
    btnInstall.style.display = 'none';
  });
}

window.addEventListener('appinstalled', () => {
  console.log('App was installed successfully.');
  if (btnInstall) {
    btnInstall.style.display = 'none';
  }
});
</script>
</body>
</html>

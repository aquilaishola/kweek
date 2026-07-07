  </div><!-- /.admin-content -->
</main><!-- /.admin-main -->

<script>
window.addEventListener('load', () => setTimeout(() => document.getElementById('preloader').classList.add('hide'), 700));


// Modal helpers
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});

// Toast
function showToast(type, title, msg) {
    const icons = { success: 'circle-check', error: 'alert-circle', info: 'info-circle', warning: 'alert-triangle' };
    const colors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--p600)', warning: 'var(--warning)' };
    const bgs    = { success: 'var(--success-bg)', error: 'var(--danger-bg)', info: 'var(--p50)', warning: 'var(--warning-bg)' };
    let c = document.getElementById('toast-c');
    if (!c) {
        c = document.createElement('div');
        c.id = 'toast-c';
        c.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9000;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(c);
    }
    const t = document.createElement('div');
    t.style.cssText = `display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--n200);border-radius:var(--r-lg);padding:12px 16px;box-shadow:0 8px 28px rgba(0,0,0,.1);min-width:280px;animation:slideUp .3s ease`;
    t.innerHTML = `<div style="width:32px;height:32px;border-radius:50%;background:${bgs[type]||bgs.info};display:flex;align-items:center;justify-content:center;color:${colors[type]||colors.info};font-size:16px;flex-shrink:0"><i class="ti ti-${icons[type]||icons.info}"></i></div><div style="flex:1"><div style="font-size:13px;font-weight:700;color:var(--n900)">${title}</div><div style="font-size:12px;color:var(--n500);margin-top:1px">${msg||''}</div></div><button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:var(--n400);font-size:16px;flex-shrink:0;padding:2px"><i class="ti ti-x"></i></button>`;
    c.appendChild(t);
    setTimeout(() => t.remove(), 5000);
}

// Flash auto-hide
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => { flash.style.transition = 'opacity .5s'; flash.style.opacity = '0'; setTimeout(() => flash.remove(), 500); }, 4000);
</script>
<style>
@keyframes slideUp { from { transform: translateY(12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
</style>
</body>
</html>

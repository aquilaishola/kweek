  </div>
</main>

<script>
(function () {
    const wrap = document.getElementById('flash-wrap');
    if (!wrap) return;

    wrap.style.maxHeight = wrap.offsetHeight + 'px';

    setTimeout(() => {
        const height = wrap.offsetHeight;

        wrap.classList.add('collapsing');

        window.scrollBy({
            top: -height,
            behavior: 'smooth'
        });

        wrap.addEventListener('transitionend', () => {
            wrap.remove();
        }, { once: true });

    }, 3500);
})();

// Preloader
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader').classList.add('hide'), 800);
});

// Sidebar
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.body.style.overflow = '';
}

// Auto-dismiss flash
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.opacity = '0', 4000);

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

// Toast helper
function showToast(type, title, message) {
  const t = document.createElement('div');
  t.className = 'toast-item toast-' + type;
  t.innerHTML = `<div class="toast-ic"><i class="ti ti-${type === 'success' ? 'circle-check' : type === 'error' ? 'alert-circle' : 'info-circle'}"></i></div><div><div class="toast-t1">${title}</div><div class="toast-t2">${message}</div></div><button onclick="this.parentElement.remove()" class="toast-close"><i class="ti ti-x"></i></button>`;
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9000;display:flex;flex-direction:column;gap:10px;';
    document.body.appendChild(container);
  }
  container.appendChild(t);
  setTimeout(() => t.remove(), 5000);
}
</script>
<style>
.toast-item{display:flex;align-items:center;gap:12px;background:var(--n0);border:1px solid var(--n200);border-radius:var(--r-lg);padding:14px 16px;box-shadow:0 8px 32px rgba(0,0,0,.12);min-width:300px;max-width:380px;animation:slideUp .3s ease}
@keyframes slideUp{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
.toast-ic{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.toast-success .toast-ic{background:var(--success-bg);color:var(--success)}
.toast-error .toast-ic{background:var(--danger-bg);color:var(--danger)}
.toast-info .toast-ic{background:var(--p50);color:var(--p600)}
.toast-t1{font-size:13px;font-weight:700;color:var(--n900)}
.toast-t2{font-size:12px;color:var(--n500);margin-top:2px}
.toast-close{margin-left:auto;background:none;border:none;cursor:pointer;color:var(--n400);font-size:16px;padding:4px;flex-shrink:0}
</style>
</body>
</html>

</main>
  </div>
</div>
<script>
function kwOpenSidebar(){ document.getElementById('app-sidebar').classList.add('open'); document.getElementById('app-overlay').classList.add('open'); }
function kwCloseSidebar(){ document.getElementById('app-sidebar').classList.remove('open'); document.getElementById('app-overlay').classList.remove('open'); }
window.addEventListener('resize', () => { if (window.innerWidth > 960) kwCloseSidebar(); });
document.querySelectorAll('.side-link').forEach(l => l.addEventListener('click', () => { if (window.innerWidth <= 960) kwCloseSidebar(); }));
</script>
</body>
</html>
<?php
/**
 * Kweek — global preloader
 * ---------------------------------------------------------
 * Drop this include at the very top of <body> on ANY page:
 *
 *     <?php include __DIR__ . '/partials/preloader.php'; ?>
 *
 * Self-contained, no external image dependency — the mark is
 * inline SVG (same K glyph used in the site nav) so it always
 * renders instantly with zero asset/path risk.
 * ---------------------------------------------------------
 */
?>
<div id="kweek-preloader">
  <div class="kw-mark">
    <svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg>
  </div>
</div>

<style>
#kweek-preloader{
  position:fixed; inset:0; z-index:99999;
  background:#0F0E1C;
  display:flex; align-items:center; justify-content:center;
  transition:opacity .45s ease, visibility .45s ease;
}
#kweek-preloader.kw-hide{ opacity:0; visibility:hidden; pointer-events:none; }

#kweek-preloader .kw-mark{
  width:72px; height:72px; border-radius:18px;
  background:#4F43D4;
  display:flex; align-items:center; justify-content:center;
  animation:kwZoom 1.1s ease-in-out infinite;
  box-shadow:0 24px 60px -16px rgba(79,67,212,.65);
}
#kweek-preloader .kw-mark svg{ width:40px; height:40px; }

@keyframes kwZoom{
  0%   { transform:scale(.78); opacity:.7; }
  50%  { transform:scale(1.12); opacity:1; }
  100% { transform:scale(.78); opacity:.7; }
}

@media (prefers-reduced-motion: reduce){
  #kweek-preloader .kw-mark{ animation:none; }
}
</style>

<script>
(function(){
  function hide(){
    var el = document.getElementById('kweek-preloader');
    if (el) el.classList.add('kw-hide');
  }
  window.kweekHidePreloader = hide;
  window.addEventListener('load', function(){ setTimeout(hide, 450); });
  setTimeout(hide, 4000); // safety net if load stalls
})();
</script>
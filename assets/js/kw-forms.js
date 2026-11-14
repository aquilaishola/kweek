(function () {
  function getSubmitButton(form) {
    return form.querySelector('button[type="submit"], input[type="submit"]');
  }

  function setLoading(btn) {
    if (!btn || btn.disabled) return;

    btn.dataset.originalHtml = btn.innerHTML;

    var loadingText = btn.dataset.loadingText || 'Please wait...';

    if (btn.tagName === 'BUTTON') {
      btn.innerHTML = '<div class="kw-spinner"></div> ' + loadingText;
    } else {
      btn.value = loadingText;
    }

    btn.disabled = true;
  }

  function restoreButton(btn) {
    if (!btn || btn.dataset.originalHtml === undefined) return;
    if (btn.tagName === 'BUTTON') {
      btn.innerHTML = btn.dataset.originalHtml;
    }
    btn.disabled = false;
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (e.defaultPrevented) return;

    if (form.hasAttribute('data-kw-no-autoloading')) return;

    if (form.noValidate === false && !form.checkValidity()) return;

    var btn = getSubmitButton(form);
    if (!btn) return;

    if (btn.disabled || btn.dataset.kwLoading === 'true') return;

    if (btn.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = btn.name;
      hidden.value = btn.value;
      form.appendChild(hidden);
    }

    btn.dataset.kwLoading = 'true';
    setLoading(btn);
  });

  window.kwRestoreButton = restoreButton;

  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-loading-text]');
    if (!el) return;

    if (el.closest('form')) return;

    if (el.dataset.kwLoading === 'true') {
      e.preventDefault();
      return;
    }
    el.dataset.kwLoading = 'true';

    if (el.tagName === 'BUTTON' || el.tagName === 'INPUT') {
      setLoading(el);
    } else {
      el.dataset.originalHtml = el.innerHTML;
      el.innerHTML = '<div class="kw-spinner"></div> ' + el.dataset.loadingText;
      el.style.pointerEvents = 'none';
    }
  });
})();

(function () {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    window.kwPush = {
      supported: false,
      enable: () => Promise.resolve(false),
      disable: () => Promise.resolve(false),
      isEnabled: () => Promise.resolve(false),
    };
    return;
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
  }

  async function getCsrfToken() {
    // Reuses the token already rendered into the page's own form(s) via
    // csrfField() — falls back to a meta tag if a page has no form.
    const input = document.querySelector('input[name="csrf_token"]');
    if (input) return input.value;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  async function register() {
    return navigator.serviceWorker.register('/sw.js');
  }

 async function enable() {
  try {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    const reg = await register();
    const keyRes = await fetch('/push-subscribe.php?action=key');
    const keyData = await keyRes.json();
    if (!keyData.ok) {
      console.error('kw-push: could not fetch VAPID key', keyData.error);
      return false;
    }

    let subscription = await reg.pushManager.getSubscription();
    if (!subscription) {
      subscription = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(keyData.key),
      });
    }

    const csrf = await getCsrfToken();
    const res = await fetch('/push-subscribe.php?action=subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ subscription: subscription.toJSON() }),
    });
    const data = await res.json();

    if (!data.ok) {
      await subscription.unsubscribe();  
      return false;
    }
    return true;
  } catch (err) {
    console.error('kw-push: enable() failed', err);
    return false;
  }
}

async function disable() {
  try {
    const reg = await navigator.serviceWorker.getRegistration();
    if (!reg) return true;
    const subscription = await reg.pushManager.getSubscription();
    if (!subscription) return true;

    const endpoint = subscription.endpoint;
    await subscription.unsubscribe();

    const csrf = await getCsrfToken();
    await fetch('/push-subscribe.php?action=unsubscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ endpoint }),
    });
    return true;
  } catch (err) {
    console.error('kw-push: disable() failed', err);
    return false;
  }
}

  async function isEnabled() {
    if (Notification.permission !== 'granted') return false;
    const reg = await navigator.serviceWorker.getRegistration();
    if (!reg) return false;
    const subscription = await reg.pushManager.getSubscription();
    return !!subscription;
  }

  register().catch((err) => console.error('kw-push: service worker registration failed', err));

  window.kwPush = { supported: true, enable, disable, isEnabled };
})();

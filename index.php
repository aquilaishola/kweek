<!DOCTYPE php>
<php lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kweek — Get paid instantly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --p50:#F0EFFE; --p100:#DCD9FC; --p200:#BCB6F8; --p300:#9B93F3;
  --p400:#7B6FEE; --p500:#6457E8; --p600:#4F43D4; --p700:#3D33B8;
  --p800:#2E2690; --p900:#1C1660;
  --n0:#FFFFFF; --n50:#F9F9FB; --n100:#F2F1F6; --n200:#E4E3EE;
  --n300:#C9C8D8; --n400:#9896B0; --n500:#6E6C88; --n600:#4A4862;
  --n700:#302E45; --n800:#1E1C32; --n900:#0F0E1C;
  --success:#16A34A; --success-bg:#DCFCE7;
  --font-display:'Sora',sans-serif; --font-body:'Inter',sans-serif;
  --r-sm:8px; --r-md:12px; --r-lg:16px; --r-xl:24px; --r-full:9999px;
}

php { scroll-behavior: smooth; }
body { font-family: var(--font-body); background: var(--n0); color: var(--n800); -webkit-font-smoothing: antialiased; overflow-x: hidden; }

.section-label { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: var(--p600); margin-bottom: 14px; }
.section-title { font-family: var(--font-display); font-size: clamp(28px,4vw,44px); font-weight: 800; line-height: 1.12; letter-spacing: -1px; color: var(--n900); margin-bottom: 14px; }
.section-lead { font-size: 16px; color: var(--n500); line-height: 1.7; max-width: 560px; }

/* PRELOADER */
#preloader { position: fixed; inset: 0; background: var(--n900); z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 28px; transition: opacity 0.6s ease, visibility 0.6s ease; }
#preloader.hide { opacity: 0; visibility: hidden; pointer-events: none; }
.pre-logo { font-family: var(--font-display); font-size: 36px; font-weight: 800; color: var(--n0); letter-spacing: -1.5px; animation: pulseLogo 1s ease infinite alternate; }
.pre-logo .acc { color: var(--p400); }
@keyframes pulseLogo { from { opacity: 0.7; } to { opacity: 1; } }
.pre-ring { width: 56px; height: 56px; border-radius: 50%; border: 3px solid var(--n800); border-top-color: var(--p500); animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.pre-hint { font-size: 12px; color: var(--n600); letter-spacing: 2px; text-transform: uppercase; }

/* NAV */
.nav { position: fixed; top: 0; left: 0; right: 0; z-index: 500; height: 68px; display: flex; align-items: center; padding: 0 5%; transition: background 0.35s, box-shadow 0.35s; }
.nav.scrolled { background: rgba(15,14,28,0.95); backdrop-filter: blur(20px); box-shadow: 0 1px 0 rgba(255,255,255,0.07); }
.nav-inner { width: 100%; max-width: 1160px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; }
.logo { font-family: var(--font-display); font-weight: 800; font-size: 24px; color: var(--n0); text-decoration: none; letter-spacing: -0.8px; }
.logo .acc { color: var(--p400); }
.nav-links { display: flex; align-items: center; gap: 36px; list-style: none; }
.nav-links a { text-decoration: none; color: rgba(255,255,255,0.6); font-size: 14px; font-weight: 500; transition: color 0.2s; position: relative; }
.nav-links a::after { content:''; position: absolute; bottom:-4px; left:0; right:0; height: 2px; background: var(--p400); border-radius: var(--r-full); transform: scaleX(0); transition: transform 0.2s; transform-origin: left; }
.nav-links a:hover { color: var(--n0); }
.nav-links a:hover::after { transform: scaleX(1); }
.nav-cta { display: flex; align-items: center; gap: 10px; }
.btn-nav-ghost { font-family: var(--font-body); font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.7); background: none; border: none; padding: 8px 16px; border-radius: var(--r-md); cursor: pointer; text-decoration: none; transition: color 0.2s, background 0.2s; }
.btn-nav-ghost:hover { color: var(--n0); background: rgba(255,255,255,0.08); }
.btn-nav-primary { font-family: var(--font-body); font-size: 14px; font-weight: 600; color: var(--n0); background: var(--p600); border: none; padding: 10px 20px; border-radius: var(--r-md); cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; white-space: nowrap; }
.btn-nav-primary:hover { background: var(--p700); }
.hamburger { display: none; background: none; border: none; cursor: pointer; padding: 8px; color: var(--n0); font-size: 26px; line-height: 1; }

/* MOBILE MENU */
.mobile-menu { position: fixed; top: 68px; left: 0; right: 0; background: var(--n900); border-bottom: 1px solid var(--n700); z-index: 499; padding: 16px 5% 24px; flex-direction: column; gap: 4px; transform: translateY(-8px); opacity: 0; pointer-events: none; transition: all 0.25s ease; display: flex; }
.mobile-menu.open { transform: translateY(0); opacity: 1; pointer-events: all; }
.mobile-menu a { display: flex; align-items: center; gap: 12px; padding: 13px 16px; text-decoration: none; color: rgba(255,255,255,0.7); font-size: 15px; font-weight: 500; border-radius: var(--r-md); transition: all 0.15s; }
.mobile-menu a i { font-size: 18px; color: var(--p400); }
.mobile-menu a:hover { background: var(--n800); color: var(--n0); }
.mobile-divider { height: 1px; background: var(--n800); margin: 8px 0; }
.mobile-menu .mob-cta { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; padding: 0 4px; }
.mob-cta-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 13px; border-radius: var(--r-md); font-family: var(--font-body); font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
.mob-cta-btn.primary { background: var(--p600); color: var(--n0); }
.mob-cta-btn.primary:hover { background: var(--p700); }
.mob-cta-btn.outline { background: transparent; color: var(--n0); border: 1.5px solid var(--n700); }
.mob-cta-btn.outline:hover { border-color: var(--p400); color: var(--p300); }

/* HERO */
.hero { min-height: 100vh; background: var(--n900); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 120px 5% 80px; text-align: center; position: relative; overflow: hidden; }
.hero::before { content: ''; position: absolute; top: -200px; left: 50%; transform: translateX(-50%); width: min(900px, 100vw); height: 900px; background: radial-gradient(ellipse at center, rgba(100,87,232,0.18) 0%, transparent 70%); pointer-events: none; }
.hero::after { content: ''; position: absolute; bottom: -100px; right: -200px; width: min(600px,80vw); height: min(600px,80vw); background: radial-gradient(ellipse at center, rgba(61,51,184,0.12) 0%, transparent 70%); pointer-events: none; }
.hero-orb { position: absolute; border-radius: 50%; pointer-events: none; }
.orb-1 { width: 300px; height: 300px; background: rgba(100,87,232,0.07); top: 15%; left: -80px; animation: float1 8s ease-in-out infinite; }
.orb-2 { width: 200px; height: 200px; background: rgba(61,51,184,0.08); bottom: 20%; right: -60px; animation: float2 10s ease-in-out infinite; }
@keyframes float1 { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-30px)} }
@keyframes float2 { 0%,100%{transform:translateY(0)} 50%{transform:translateY(20px)} }
.hero-inner { position: relative; z-index: 2; max-width: 760px; margin: 0 auto; width: 100%; }
.hero-badge { display: inline-flex; align-items: center; gap: 7px; background: rgba(100,87,232,0.15); border: 1px solid rgba(100,87,232,0.35); color: var(--p300); font-size: 13px; font-weight: 600; padding: 7px 16px; border-radius: var(--r-full); margin-bottom: 32px; animation: fadeDown 0.6s ease both; }
.hero-badge i { font-size: 14px; color: var(--p400); }
@keyframes fadeDown { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
@keyframes fadeUp   { from{opacity:0;transform:translateY(16px)}  to{opacity:1;transform:translateY(0)} }
.hero h1 { font-family: var(--font-display); font-size: clamp(32px,6.5vw,68px); font-weight: 800; line-height: 1.08; letter-spacing: clamp(-1px,-0.03em,-2px); color: var(--n0); margin-bottom: 22px; animation: fadeDown 0.6s ease 0.1s both; }
.hero h1 .purple { color: var(--p400); }
.hero p { font-size: clamp(15px,2.5vw,18px); color: var(--n400); max-width: 500px; margin: 0 auto 40px; line-height: 1.7; animation: fadeDown 0.6s ease 0.2s both; }
.hero-actions { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; margin-bottom: 48px; animation: fadeDown 0.6s ease 0.3s both; }
.btn-hero-primary { display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-body); font-size: 16px; font-weight: 700; color: var(--n0); background: var(--p600); padding: 16px 32px; border-radius: var(--r-lg); border: none; text-decoration: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-hero-primary:hover { background: var(--p700); transform: translateY(-2px); box-shadow: 0 8px 28px rgba(100,87,232,0.35); }
.btn-hero-outline { display: inline-flex; align-items: center; gap: 8px; font-family: var(--font-body); font-size: 16px; font-weight: 600; color: rgba(255,255,255,0.8); border: 1.5px solid rgba(255,255,255,0.2); padding: 15px 32px; border-radius: var(--r-lg); background: transparent; text-decoration: none; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.btn-hero-outline:hover { border-color: var(--p400); color: var(--p300); background: rgba(100,87,232,0.1); }
.hero-proof { display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--n600); font-size: 13px; animation: fadeDown 0.6s ease 0.4s both; flex-wrap: wrap; text-align: center; }
.hero-proof i { color: var(--success); font-size: 15px; }
.hero-stats { display: flex; align-items: center; justify-content: center; gap: 24px; margin-top: 64px; padding-top: 40px; border-top: 1px solid rgba(255,255,255,0.06); animation: fadeUp 0.7s ease 0.5s both; flex-wrap: wrap; }
.hstat { text-align: center; min-width: 90px; }
.hstat-num { font-family: var(--font-display); font-size: 28px; font-weight: 800; color: var(--n0); letter-spacing: -0.5px; }
.hstat-label { font-size: 12px; color: var(--n500); margin-top: 2px; }

/* DEMO */
.demo-section { background: var(--n900); padding: 0 5% 100px; position: relative; z-index: 2; overflow: hidden; }
.demo-wrap { max-width: 900px; margin: 0 auto; animation: fadeUp 0.8s ease 0.6s both; }
.demo-card { background: var(--n800); border: 1px solid var(--n700); border-radius: var(--r-xl); overflow: hidden; position: relative; }
.demo-bar { background: var(--n900); border-bottom: 1px solid var(--n700); padding: 14px 20px; display: flex; align-items: center; gap: 12px; }
.dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.dot-r{background:#FF5F57;} .dot-y{background:#FEBC2E;} .dot-g{background:#28C840;}
.demo-url { flex: 1; background: var(--n800); border: 1px solid var(--n700); border-radius: var(--r-full); padding: 5px 14px; font-size: 12px; color: var(--n400); text-align: center; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.demo-body { display: grid; grid-template-columns: 1fr 1fr; min-height: 380px; }
.demo-left { padding: 24px; border-right: 1px solid var(--n700); display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.demo-merchant { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.merchant-av { width: 46px; height: 46px; border-radius: 50%; background: var(--p800); display: flex; align-items: center; justify-content: center; color: var(--p200); font-size: 15px; font-weight: 700; font-family: var(--font-display); flex-shrink: 0; }
.merchant-name { font-weight: 700; font-size: 14px; color: var(--n0); }
.merchant-sub { font-size: 11px; color: var(--n500); margin-top: 1px; }
.demo-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; background: var(--n700); border: 1px solid var(--n600); border-radius: var(--r-md); }
.demo-item-l { display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1; }
.item-ico { width: 32px; height: 32px; border-radius: var(--r-sm); background: var(--p900); display: flex; align-items: center; justify-content: center; color: var(--p400); font-size: 15px; flex-shrink: 0; }
.item-n { font-size: 12px; font-weight: 600; color: var(--n100); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-s { font-size: 11px; color: var(--n500); margin-top: 1px; }
.item-price { font-size: 12px; font-weight: 700; color: var(--n0); font-family: var(--font-display); white-space: nowrap; flex-shrink: 0; }
.demo-right { padding: 24px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.demo-right h4 { font-family: var(--font-display); font-size: 14px; font-weight: 700; color: var(--n0); }
.demo-field { display: flex; flex-direction: column; gap: 4px; }
.demo-field label { font-size: 10px; font-weight: 600; color: var(--n500); text-transform: uppercase; letter-spacing: 0.5px; }
.demo-field input { background: var(--n700); border: 1px solid var(--n600); border-radius: var(--r-sm); padding: 9px 10px; font-size: 12px; color: var(--n200); font-family: var(--font-body); outline: none; width: 100%; }
.demo-field input:focus { border-color: var(--p500); }
.pay-btn { margin-top: auto; width: 100%; background: var(--p600); color: var(--n0); border: none; border-radius: var(--r-md); padding: 12px; font-family: var(--font-body); font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: background 0.2s; }
.pay-btn:hover { background: var(--p700); }
.secure-row { display: flex; align-items: center; justify-content: center; gap: 5px; font-size: 10px; color: var(--n600); flex-wrap: wrap; text-align: center; }
.notif-pop { display: flex; align-items: center; gap: 10px; background: var(--n0); border: 1px solid var(--n200); border-radius: var(--r-lg); padding: 12px 16px; position: absolute; bottom: 20px; right: 20px; box-shadow: 0 12px 40px rgba(0,0,0,0.25); animation: popIn 0.5s ease 1.2s both; z-index: 10; }
@keyframes popIn { from{opacity:0;transform:scale(0.85) translateY(8px)} to{opacity:1;transform:scale(1) translateY(0)} }
.notif-ic { width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0; background: var(--success-bg); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 17px; }
.notif-t1 { font-size: 12px; font-weight: 700; color: var(--n900); white-space: nowrap; }
.notif-t2 { font-size: 11px; color: var(--n500); margin-top: 1px; white-space: nowrap; }

/* MARQUEE */
.marquee-section { background: var(--n800); border-top: 1px solid var(--n700); border-bottom: 1px solid var(--n700); padding: 20px 0; overflow: hidden; }
.marquee-label { text-align: center; font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: var(--n600); margin-bottom: 16px; }
.marquee-track { display: flex; gap: 48px; animation: marquee 20s linear infinite; white-space: nowrap; }
.marquee-track:hover { animation-play-state: paused; }
.marquee-item { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; color: var(--n500); flex-shrink: 0; }
.marquee-item i { font-size: 18px; color: var(--n600); }
@keyframes marquee { from{transform:translateX(0)} to{transform:translateX(-50%)} }

/* HOW IT WORKS */
.hiw-section { padding: 80px 5%; background: var(--n0); }
.hiw-inner { max-width: 1100px; margin: 0 auto; }
.hiw-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 24px; margin-top: 60px; position: relative; }
.hiw-grid::before { content: ''; position: absolute; top: 44px; left: calc(33.3% + 20px); right: calc(33.3% + 20px); height: 2px; background: var(--n200); z-index: 0; }
.hiw-card { background: var(--n0); border: 1px solid var(--n200); border-radius: var(--r-xl); padding: 36px 28px; position: relative; z-index: 1; transition: border-color 0.25s, transform 0.25s; }
.hiw-card:hover { border-color: var(--p300); transform: translateY(-4px); }
.hiw-num { font-family: var(--font-display); font-size: 11px; font-weight: 800; letter-spacing: 2px; color: var(--p500); text-transform: uppercase; margin-bottom: 20px; }
.hiw-icon { width: 52px; height: 52px; border-radius: var(--r-lg); background: var(--p50); display: flex; align-items: center; justify-content: center; color: var(--p600); font-size: 24px; margin-bottom: 20px; border: 1px solid var(--p100); }
.hiw-card h3 { font-family: var(--font-display); font-size: 18px; font-weight: 700; color: var(--n900); margin-bottom: 10px; letter-spacing: -0.3px; }
.hiw-card p { font-size: 14px; color: var(--n500); line-height: 1.7; }

/* FEATURES */
.feat-section { padding: 80px 5%; background: var(--n50); border-top: 1px solid var(--n200); }
.feat-inner { max-width: 1100px; margin: 0 auto; }
.feat-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-top: 60px; }
.feat-card { background: var(--n0); border: 1px solid var(--n200); border-radius: var(--r-xl); padding: 32px 26px; transition: border-color 0.25s, transform 0.25s; }
.feat-card:hover { border-color: var(--p300); transform: translateY(-3px); }
.feat-card.hero-card { background: var(--n900); border-color: var(--n800); grid-column: span 2; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: center; }
.feat-card.hero-card .f-icon { background: var(--p900); color: var(--p300); }
.feat-card.hero-card h3 { color: var(--n0); }
.feat-card.hero-card p { color: var(--n400); }
.feat-card.hero-card .hero-visual { background: var(--n800); border: 1px solid var(--n700); border-radius: var(--r-lg); padding: 20px; }
.f-icon { width: 48px; height: 48px; border-radius: var(--r-md); background: var(--p50); display: flex; align-items: center; justify-content: center; color: var(--p600); font-size: 22px; margin-bottom: 20px; border: 1px solid var(--p100); }
.feat-card h3 { font-family: var(--font-display); font-size: 17px; font-weight: 700; color: var(--n900); margin-bottom: 8px; letter-spacing: -0.3px; }
.feat-card p { font-size: 13px; color: var(--n500); line-height: 1.65; }
.receipt-mock { display: flex; flex-direction: column; gap: 10px; }
.r-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: var(--n700); border-radius: var(--r-sm); gap: 8px; }
.r-label { font-size: 12px; color: var(--n400); flex-shrink: 0; }
.r-val { font-size: 12px; font-weight: 600; color: var(--n100); text-align: right; }
.r-verified { display: flex; align-items: center; gap: 6px; background: rgba(22,163,74,0.12); border: 1px solid rgba(22,163,74,0.25); border-radius: var(--r-sm); padding: 8px 12px; font-size: 12px; font-weight: 600; color: #4ADE80; }
.r-verified i { font-size: 14px; flex-shrink: 0; }

/* TESTIMONIALS */
.testi-section { padding: 80px 5%; background: var(--n900); }
.testi-inner { max-width: 1100px; margin: 0 auto; }
.testi-section .section-label { color: var(--p400); }
.testi-section .section-title { color: var(--n0); }
.testi-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-top: 56px; }
.testi-card { background: var(--n800); border: 1px solid var(--n700); border-radius: var(--r-xl); padding: 28px; transition: border-color 0.2s; }
.testi-card:hover { border-color: var(--p600); }
.testi-stars { color: var(--p400); font-size: 14px; display: flex; gap: 2px; margin-bottom: 16px; }
.testi-quote { font-size: 14px; color: var(--n300); line-height: 1.75; margin-bottom: 22px; }
.testi-author { display: flex; align-items: center; gap: 10px; }
.testi-av { width: 38px; height: 38px; border-radius: 50%; background: var(--p800); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: var(--p200); font-family: var(--font-display); flex-shrink: 0; }
.testi-name { font-size: 13px; font-weight: 700; color: var(--n100); }
.testi-role { font-size: 12px; color: var(--n500); margin-top: 1px; }

/* PRICING */
.pricing-section { padding: 80px 5%; background: var(--n0); }
.pricing-inner { max-width: 1000px; margin: 0 auto; }
.pricing-toggle { display: flex; align-items: center; background: var(--n100); border-radius: var(--r-full); padding: 4px; margin: 32px auto 0; width: fit-content; }
.ptoggle-btn { padding: 8px 20px; border-radius: var(--r-full); font-family: var(--font-body); font-size: 14px; font-weight: 600; cursor: pointer; border: none; background: transparent; color: var(--n500); transition: all 0.2s; }
.ptoggle-btn.active { background: var(--n0); color: var(--n900); box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
.pricing-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 20px; margin-top: 48px; }
.p-card { background: var(--n0); border: 1.5px solid var(--n200); border-radius: var(--r-xl); padding: 36px 28px; display: flex; flex-direction: column; transition: border-color 0.25s, transform 0.25s; }
.p-card:hover { border-color: var(--p300); }
.p-card.featured { background: var(--p900); border-color: var(--p700); position: relative; transform: scale(1.03); }
.p-card.featured:hover { transform: scale(1.05); }
.p-popular { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: var(--p500); color: var(--n0); font-size: 11px; font-weight: 700; letter-spacing: 0.5px; padding: 5px 14px; border-radius: var(--r-full); white-space: nowrap; }
.p-plan { font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--n400); margin-bottom: 12px; }
.featured .p-plan { color: var(--p300); }
.p-price { font-family: var(--font-display); font-size: 36px; font-weight: 800; color: var(--n900); letter-spacing: -1.5px; margin-bottom: 4px; word-break: break-word; }
.featured .p-price { color: var(--n0); }
.p-price sub { font-size: 13px; font-weight: 400; color: var(--n400); vertical-align: baseline; }
.featured .p-price sub { color: var(--p300); }
.p-desc { font-size: 13px; color: var(--n500); margin-bottom: 24px; line-height: 1.5; }
.featured .p-desc { color: var(--p300); }
.p-divider { border: none; border-top: 1px solid var(--n200); margin-bottom: 24px; }
.featured .p-divider { border-color: var(--p700); }
.p-features { list-style: none; display: flex; flex-direction: column; gap: 11px; flex: 1; margin-bottom: 28px; }
.p-feat { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; color: var(--n600); }
.p-feat i { font-size: 15px; color: var(--p500); margin-top: 1px; flex-shrink: 0; }
.featured .p-feat { color: var(--p200); }
.featured .p-feat i { color: var(--p400); }
.p-btn { width: 100%; padding: 13px; border-radius: var(--r-md); font-family: var(--font-body); font-size: 14px; font-weight: 700; cursor: pointer; border: 1.5px solid var(--n200); background: var(--n0); color: var(--n900); transition: all 0.2s; }
.p-btn:hover { border-color: var(--p400); color: var(--p700); background: var(--p50); }
.p-btn.featured-btn { background: var(--p600); border-color: var(--p600); color: var(--n0); }
.p-btn.featured-btn:hover { background: var(--p700); border-color: var(--p700); }

/* STATS BAND */
.stats-band { background: var(--p800); padding: 60px 5%; }
.stats-band-inner { max-width: 1000px; margin: 0 auto; display: grid; grid-template-columns: repeat(4,1fr); }
.sband-item { text-align: center; padding: 0 16px; border-right: 1px solid rgba(255,255,255,0.12); }
.sband-item:last-child { border-right: none; }
.sband-num { font-family: var(--font-display); font-size: 34px; font-weight: 800; color: var(--n0); letter-spacing: -1px; margin-bottom: 4px; }
.sband-label { font-size: 13px; color: var(--p200); }

/* CTA */
.cta-section { background: var(--n900); padding: 100px 5%; text-align: center; position: relative; overflow: hidden; }
.cta-section::before { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); width: min(800px,100vw); height: 400px; background: radial-gradient(ellipse, rgba(100,87,232,0.15) 0%, transparent 70%); pointer-events: none; }
.cta-inner { position: relative; z-index: 1; max-width: 600px; margin: 0 auto; }
.cta-inner .section-title { color: var(--n0); font-size: clamp(28px,5vw,52px); }
.cta-inner p { font-size: 17px; color: var(--n400); margin: 0 auto 40px; line-height: 1.7; }
.email-capture { display: flex; gap: 8px; max-width: 440px; margin: 0 auto; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--r-lg); padding: 6px 6px 6px 16px; }
.email-capture input { flex: 1; background: none; border: none; outline: none; font-family: var(--font-body); font-size: 15px; color: var(--n0); min-width: 0; }
.email-capture input::placeholder { color: var(--n600); }

/* FOOTER */
footer { background: var(--n900); border-top: 1px solid var(--n800); padding: 72px 5% 40px; }
.footer-inner { max-width: 1160px; margin: 0 auto; }
.footer-top { display: grid; grid-template-columns: 1.5fr repeat(3,1fr); gap: 48px; margin-bottom: 56px; }
.footer-brand .logo { font-size: 22px; }
.footer-tagline { font-size: 13px; color: var(--n500); margin-top: 12px; line-height: 1.65; max-width: 220px; }
.footer-social { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
.soc-btn { width: 36px; height: 36px; border-radius: var(--r-md); background: var(--n800); border: 1px solid var(--n700); display: flex; align-items: center; justify-content: center; color: var(--n500); font-size: 17px; text-decoration: none; transition: all 0.2s; }
.soc-btn:hover { background: var(--p800); color: var(--p300); border-color: var(--p700); }
.footer-col h5 { font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--n500); margin-bottom: 18px; }
.footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 11px; }
.footer-col a { font-size: 14px; color: var(--n500); text-decoration: none; transition: color 0.2s; }
.footer-col a:hover { color: var(--n0); }
.footer-bottom { border-top: 1px solid var(--n800); padding-top: 28px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--n600); flex-wrap: wrap; gap: 12px; }
.footer-bottom a { color: var(--n600); text-decoration: none; }
.footer-bottom a:hover { color: var(--n300); }

/* SCROLL REVEAL */
.reveal { opacity: 0; transform: translateY(28px); transition: opacity 0.65s ease, transform 0.65s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }
.d1{transition-delay:0.1s} .d2{transition-delay:0.2s} .d3{transition-delay:0.3s} .d4{transition-delay:0.4s}

/* ── RESPONSIVE 900px ── */
@media (max-width: 900px) {
  .hiw-grid { grid-template-columns: 1fr; }
  .hiw-grid::before { display: none; }
  .feat-grid { grid-template-columns: 1fr 1fr; }
  .feat-card.hero-card { grid-column: span 2; }
  .testi-grid { grid-template-columns: 1fr 1fr; }
  .pricing-grid { grid-template-columns: 1fr; max-width: 420px; margin-left: auto; margin-right: auto; }
  .p-card.featured { transform: scale(1); }
  .p-card.featured:hover { transform: scale(1.02); }
  .stats-band-inner { grid-template-columns: repeat(2,1fr); gap: 0; }
  .sband-item { border-right: none; padding: 20px 0; border-bottom: 1px solid rgba(255,255,255,0.12); }
  .sband-item:nth-child(odd) { border-right: 1px solid rgba(255,255,255,0.12); }
  .sband-item:nth-child(3),
  .sband-item:nth-child(4) { border-bottom: none; }
  .footer-top { grid-template-columns: 1fr 1fr; }
  .demo-body { grid-template-columns: 1fr; }
  .demo-left { border-right: none; border-bottom: 1px solid var(--n700); }
  .notif-pop { position: static; margin: 0 16px 16px; }
}

/* ── RESPONSIVE 768px ── */
@media (max-width: 768px) {
  .nav-links, .nav-cta { display: none; }
  .hamburger { display: block; }
  .hero { padding: 100px 5% 60px; }
  .hero-badge { font-size: 11px; padding: 6px 12px; }
  .hero-stats { gap: 16px; margin-top: 40px; padding-top: 28px; }
  .feat-grid { grid-template-columns: 1fr; }
  .feat-card.hero-card { grid-column: span 1; display: flex; flex-direction: column; }
  .testi-grid { grid-template-columns: 1fr; }
  .footer-top { grid-template-columns: 1fr 1fr; gap: 28px; }
  .footer-tagline { max-width: 100%; }
  .demo-section { padding: 0 4% 60px; }
  .hiw-section, .feat-section, .testi-section, .pricing-section { padding: 60px 5%; }
  .cta-section { padding: 80px 5%; }
  .stats-band { padding: 40px 5%; }
  .hiw-grid, .feat-grid { margin-top: 36px; }
  .email-capture { flex-direction: column; padding: 12px; border-radius: var(--r-md); }
  .email-capture input { width: 100%; font-size: 16px; }
  .email-capture .btn-nav-primary { width: 100% !important; justify-content: center; font-size: 15px !important; padding: 14px !important; border-radius: var(--r-md) !important; }
}

/* ── RESPONSIVE 480px ── */
@media (max-width: 480px) {
  .hero-actions { flex-direction: column; align-items: stretch; }
  .btn-hero-primary, .btn-hero-outline { justify-content: center; padding: 14px 20px; font-size: 15px; }
  .hstat-num { font-size: 22px; }
  .hstat-label { font-size: 11px; }
  .hstat { min-width: 70px; }
  .demo-bar { padding: 10px 12px; }
  .demo-left, .demo-right { padding: 16px; }
  .sband-num { font-size: 26px; }
  .footer-top { grid-template-columns: 1fr; gap: 28px; }
  .footer-tagline { max-width: 100%; }
  .footer-bottom { flex-direction: column; align-items: flex-start; }
  .cta-inner p { font-size: 15px; }
}
</style>
</head>
<body>

<!-- PRELOADER -->
<div id="preloader">
  <div class="pre-logo">Kw<span class="acc">ee</span>k</div>
  <div class="pre-ring"></div>
  <div class="pre-hint">Loading your experience</div>
</div>

<!-- NAV -->
<nav class="nav" id="nav">
  <div class="nav-inner">
    <a href="index.php" class="logo">Kw<span class="acc">ee</span>k</a>
    <ul class="nav-links">
      <li><a href="#how">How it works</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="#pricing">Pricing</a></li>
      <li><a href="#testimonials">Reviews</a></li>
    </ul>
    <div class="nav-cta">
      <a href="login.php" class="btn-nav-ghost">Sign in</a>
      <a href="register.php" class="btn-nav-primary">Get started free <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Open menu">
      <i class="ti ti-menu-2" aria-hidden="true"></i>
    </button>
  </div>
</nav>

<!-- MOBILE MENU -->
<div class="mobile-menu" id="mobile-menu">
  <a href="#how"><i class="ti ti-route" aria-hidden="true"></i> How it works</a>
  <a href="#features"><i class="ti ti-sparkles" aria-hidden="true"></i> Features</a>
  <a href="#pricing"><i class="ti ti-tag" aria-hidden="true"></i> Pricing</a>
  <a href="#testimonials"><i class="ti ti-message-dots" aria-hidden="true"></i> Reviews</a>
  <div class="mobile-divider"></div>
  <div class="mob-cta">
    <a href="register.php" class="mob-cta-btn primary"><i class="ti ti-link" aria-hidden="true"></i> Create free link</a>
    <a href="login.php" class="mob-cta-btn outline">Sign in to your account</a>
  </div>
</div>

<!-- HERO -->
<section class="hero">
  <div class="hero-orb orb-1"></div>
  <div class="hero-orb orb-2"></div>
  <div class="hero-inner">
    <div class="hero-badge"><i class="ti ti-bolt" aria-hidden="true"></i> Built for Nigerian merchants · Powered by Nomba</div>
    <h1>Stop chasing payment.<br><span class="purple">Start getting paid.</span></h1>
    <p>Create a payment link in 30 seconds. Share it on WhatsApp. Get notified the instant money lands — no account numbers, no manual confirmation.</p>
    <div class="hero-actions">
      <a href="register.php" class="btn-hero-primary"><i class="ti ti-link" aria-hidden="true"></i> Create your free link</a>
      <a href="#how" class="btn-hero-outline"><i class="ti ti-player-play" aria-hidden="true"></i> See how it works</a>
    </div>
    <div class="hero-proof"><i class="ti ti-circle-check" aria-hidden="true"></i> Free forever · No CAC required · Setup in 2 minutes</div>
    <div class="hero-stats">
      <div class="hstat"><div class="hstat-num">30s</div><div class="hstat-label">To create a link</div></div>
      <div class="hstat"><div class="hstat-num">40M+</div><div class="hstat-label">SMEs in Nigeria</div></div>
      <div class="hstat"><div class="hstat-num">₦0</div><div class="hstat-label">To get started</div></div>
      <div class="hstat"><div class="hstat-num">100%</div><div class="hstat-label">Fraud-proof receipts</div></div>
    </div>
  </div>
</section>

<!-- DEMO CARD -->
<div class="demo-section">
  <div class="demo-wrap">
    <div class="demo-card">
      <div class="demo-bar">
        <div class="dot dot-r"></div><div class="dot dot-y"></div><div class="dot dot-g"></div>
        <div class="demo-url">kweek.ng/pay/temi-kitchen</div>
      </div>
      <div class="demo-body">
        <div class="demo-left">
          <div class="demo-merchant">
            <div class="merchant-av">TK</div>
            <div><div class="merchant-name">Temi's Kitchen</div><div class="merchant-sub">Food vendor · Surulere, Lagos</div></div>
          </div>
          <div class="demo-item">
            <div class="demo-item-l"><div class="item-ico"><i class="ti ti-bowl" aria-hidden="true"></i></div><div><div class="item-n">Jollof rice + chicken</div><div class="item-s">Ready in 30 mins</div></div></div>
            <div class="item-price">₦3,500</div>
          </div>
          <div class="demo-item">
            <div class="demo-item-l"><div class="item-ico"><i class="ti ti-salad" aria-hidden="true"></i></div><div><div class="item-n">Fried plantain (extra)</div><div class="item-s">Per portion</div></div></div>
            <div class="item-price">₦500</div>
          </div>
          <div class="demo-item">
            <div class="demo-item-l"><div class="item-ico"><i class="ti ti-cup" aria-hidden="true"></i></div><div><div class="item-n">Small chops platter</div><div class="item-s">Serves 4</div></div></div>
            <div class="item-price">₦8,000</div>
          </div>
        </div>
        <div class="demo-right">
          <h4>Complete your order</h4>
          <div class="demo-field"><label>Full name</label><input type="text" value="Kemi Adeyemi" readonly></div>
          <div class="demo-field"><label>Phone number</label><input type="text" value="0812 345 6789" readonly></div>
          <div class="demo-field"><label>Delivery area</label><input type="text" value="Surulere (+₦500)" readonly></div>
          <button class="pay-btn"><i class="ti ti-lock" aria-hidden="true"></i> Pay ₦4,000 securely</button>
          <div class="secure-row"><i class="ti ti-shield-check" aria-hidden="true"></i> Secured by Kweek · Powered by Nomba</div>
        </div>
      </div>
      <div class="notif-pop">
        <div class="notif-ic"><i class="ti ti-circle-check" aria-hidden="true"></i></div>
        <div><div class="notif-t1">Payment received!</div><div class="notif-t2">₦4,000 from Kemi · just now</div></div>
      </div>
    </div>
  </div>
</div>

<!-- MARQUEE -->
<div class="marquee-section">
  <div class="marquee-label">Trusted by merchants across Nigeria</div>
  <div style="overflow:hidden;">
    <div class="marquee-track">
      <div class="marquee-item"><i class="ti ti-building-store" aria-hidden="true"></i> Fashion Vendors</div>
      <div class="marquee-item"><i class="ti ti-bowl" aria-hidden="true"></i> Food Sellers</div>
      <div class="marquee-item"><i class="ti ti-device-mobile" aria-hidden="true"></i> Gadget Stores</div>
      <div class="marquee-item"><i class="ti ti-sun" aria-hidden="true"></i> Solar Merchants</div>
      <div class="marquee-item"><i class="ti ti-camera" aria-hidden="true"></i> Photographers</div>
      <div class="marquee-item"><i class="ti ti-scissors" aria-hidden="true"></i> Hair Stylists</div>
      <div class="marquee-item"><i class="ti ti-shirt" aria-hidden="true"></i> Tailors</div>
      <div class="marquee-item"><i class="ti ti-cake" aria-hidden="true"></i> Bakers</div>
      <div class="marquee-item"><i class="ti ti-building-store" aria-hidden="true"></i> Fashion Vendors</div>
      <div class="marquee-item"><i class="ti ti-bowl" aria-hidden="true"></i> Food Sellers</div>
      <div class="marquee-item"><i class="ti ti-device-mobile" aria-hidden="true"></i> Gadget Stores</div>
      <div class="marquee-item"><i class="ti ti-sun" aria-hidden="true"></i> Solar Merchants</div>
      <div class="marquee-item"><i class="ti ti-camera" aria-hidden="true"></i> Photographers</div>
      <div class="marquee-item"><i class="ti ti-scissors" aria-hidden="true"></i> Hair Stylists</div>
      <div class="marquee-item"><i class="ti ti-shirt" aria-hidden="true"></i> Tailors</div>
      <div class="marquee-item"><i class="ti ti-cake" aria-hidden="true"></i> Bakers</div>
    </div>
  </div>
</div>

<!-- HOW IT WORKS -->
<section class="hiw-section" id="how">
  <div class="hiw-inner">
    <div class="section-label reveal"><i class="ti ti-route" aria-hidden="true"></i> How it works</div>
    <h2 class="section-title reveal d1">From zero to getting paid<br>in three steps</h2>
    <p class="section-lead reveal d2">No technical setup. No bank visits. No approval waiting. Just a link that works from day one.</p>
    <div class="hiw-grid">
      <div class="hiw-card reveal d1">
        <div class="hiw-num">Step 01</div>
        <div class="hiw-icon"><i class="ti ti-user-plus" aria-hidden="true"></i></div>
        <h3>Sign up in 2 minutes</h3>
        <p>Create your account with phone + email. Verify your BVN once — that's it. No CAC documents, no waiting for approval, no bank visits.</p>
      </div>
      <div class="hiw-card reveal d2">
        <div class="hiw-num">Step 02</div>
        <div class="hiw-icon"><i class="ti ti-link" aria-hidden="true"></i></div>
        <h3>Build your payment page</h3>
        <p>Add your products or services with prices. Set delivery zones. Your branded link is live instantly — kweek.ng/pay/your-brand-name.</p>
      </div>
      <div class="hiw-card reveal d3">
        <div class="hiw-num">Step 03</div>
        <div class="hiw-icon"><i class="ti ti-bell-ringing" aria-hidden="true"></i></div>
        <h3>Get paid, get notified</h3>
        <p>Share on WhatsApp or Instagram bio. Customer pays, you get an instant alert. Withdraw to your bank account in one tap anytime.</p>
      </div>
    </div>
  </div>
</section>

<!-- FEATURES -->
<section class="feat-section" id="features">
  <div class="feat-inner">
    <div class="section-label reveal"><i class="ti ti-sparkles" aria-hidden="true"></i> Features</div>
    <h2 class="section-title reveal d1">Everything a Nigerian merchant<br>actually needs</h2>
    <p class="section-lead reveal d2">Built around how you sell — on WhatsApp, with variable stock, no technical knowledge needed.</p>
    <div class="feat-grid">
      <div class="feat-card hero-card reveal">
        <div>
          <div class="f-icon"><i class="ti ti-shield-check" aria-hidden="true"></i></div>
          <h3>Verified receipts. Zero fake alerts.</h3>
          <p>Every payment generates a unique verified receipt page at kweek.ng/receipt/TXN-XXXX. One link check tells you if it's real. No more fake Opay screenshots costing you money.</p>
        </div>
        <div class="hero-visual">
          <div class="receipt-mock">
            <div class="r-row"><span class="r-label">Transaction ID</span><span class="r-val">TXN-8847-KQPL</span></div>
            <div class="r-row"><span class="r-label">Amount</span><span class="r-val">₦4,000.00</span></div>
            <div class="r-row"><span class="r-label">Merchant</span><span class="r-val">Temi's Kitchen</span></div>
            <div class="r-row"><span class="r-label">Time</span><span class="r-val">Today, 2:34 PM</span></div>
            <div class="r-verified"><i class="ti ti-circle-check" aria-hidden="true"></i> Payment verified by Kweek</div>
          </div>
        </div>
      </div>
      <div class="feat-card reveal d1">
        <div class="f-icon"><i class="ti ti-clock-check" aria-hidden="true"></i></div>
        <h3>Confirm before they pay</h3>
        <p>Review and accept orders before the customer pays. No refunds for sold-out items. You stay in control of every transaction.</p>
      </div>
      <div class="feat-card reveal d2">
        <div class="f-icon"><i class="ti ti-motorbike" aria-hidden="true"></i></div>
        <h3>Built-in delivery fees</h3>
        <p>Set delivery zones and prices once. Customers pick their area at checkout — fee added automatically. No more WhatsApp negotiation.</p>
      </div>
      <div class="feat-card reveal d1">
        <div class="f-icon"><i class="ti ti-users-group" aria-hidden="true"></i></div>
        <h3>Group order collection</h3>
        <p>One link for an entire office. Each person picks their own items and pays individually. You get one consolidated order.</p>
      </div>
      <div class="feat-card reveal d2">
        <div class="f-icon"><i class="ti ti-credit-card-pay" aria-hidden="true"></i></div>
        <h3>Split payment / installments</h3>
        <p>Perfect for solar panels, furniture, large orders. Set installment milestones — each payment tracked automatically until paid in full.</p>
      </div>
      <div class="feat-card reveal d3">
        <div class="f-icon"><i class="ti ti-building-bank" aria-hidden="true"></i></div>
        <h3>Instant withdrawal</h3>
        <p>Your money is always yours. Withdraw to GTB, Access, Opay, Kuda — any Nigerian bank — in one tap within minutes.</p>
      </div>
    </div>
  </div>
</section>

<!-- STATS BAND -->
<div class="stats-band">
  <div class="stats-band-inner">
    <div class="sband-item reveal"><div class="sband-num">₦2.4B+</div><div class="sband-label">Processed this month</div></div>
    <div class="sband-item reveal d1"><div class="sband-num">18,000+</div><div class="sband-label">Active merchants</div></div>
    <div class="sband-item reveal d2"><div class="sband-num">99.97%</div><div class="sband-label">Payment success rate</div></div>
    <div class="sband-item reveal d3"><div class="sband-num">&lt; 5s</div><div class="sband-label">Average notification time</div></div>
  </div>
</div>

<!-- TESTIMONIALS -->
<section class="testi-section" id="testimonials">
  <div class="testi-inner">
    <div class="section-label reveal"><i class="ti ti-message-dots" aria-hidden="true"></i> What merchants say</div>
    <h2 class="section-title reveal d1">Real merchants.<br>Real results.</h2>
    <div class="testi-grid">
      <div class="testi-card reveal d1">
        <div class="testi-stars"><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i></div>
        <p class="testi-quote">"I used to spend 30 minutes every morning confirming transfers on WhatsApp. Now I just cook and the alerts come. My customers trust me more because they get a proper receipt."</p>
        <div class="testi-author"><div class="testi-av">AT</div><div><div class="testi-name">Adaeze T.</div><div class="testi-role">Food vendor, Abuja</div></div></div>
      </div>
      <div class="testi-card reveal d2">
        <div class="testi-stars"><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i></div>
        <p class="testi-quote">"A customer sent me a fake GTB alert. I checked the Kweek receipt link — nothing was there. That one feature saved me ₦45,000. Worth every kobo I pay monthly."</p>
        <div class="testi-author"><div class="testi-av">BK</div><div><div class="testi-name">Biodun K.</div><div class="testi-role">Fashion vendor, Lagos</div></div></div>
      </div>
      <div class="testi-card reveal d3">
        <div class="testi-stars"><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i><i class="ti ti-star-filled" aria-hidden="true"></i></div>
        <p class="testi-quote">"I sell solar panels at ₦350k. Tracking installments was a nightmare. Kweek's split payment feature changed everything. My bookkeeping now takes 5 minutes a week."</p>
        <div class="testi-author"><div class="testi-av">EM</div><div><div class="testi-name">Emeka M.</div><div class="testi-role">Solar merchant, Port Harcourt</div></div></div>
      </div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section class="pricing-section" id="pricing">
  <div class="pricing-inner">
    <div class="section-label reveal" style="justify-content:center;display:flex;"><i class="ti ti-tag" aria-hidden="true"></i> Pricing</div>
    <h2 class="section-title reveal d1" style="text-align:center;">Pricing that grows with you</h2>
    <p class="section-lead reveal d2" style="text-align:center;margin:0 auto 16px;">Start free. Upgrade when revenue justifies it. Every paid plan saves you money on transaction fees.</p>
    <div class="pricing-toggle reveal d3">
      <button class="ptoggle-btn active" id="monthly-btn">Monthly</button>
      <button class="ptoggle-btn" id="annual-btn">Annual <span style="font-size:11px;color:var(--success);font-weight:700;margin-left:4px;">Save 17%</span></button>
    </div>
    <div class="pricing-grid">
      <div class="p-card reveal d1">
        <div class="p-plan">Free</div>
        <div class="p-price" data-monthly="₦0" data-annual="₦0">₦0 <sub>/ month</sub></div>
        <div class="p-desc">Try it out. One live link, forever free.</div>
        <hr class="p-divider">
        <ul class="p-features">
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>1 payment link</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Basic dashboard</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Instant payment alerts</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Verified receipts</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Withdraw anytime</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>1.5% transaction fee</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>₦200k/month cap</li>
        </ul>
        <button class="p-btn" onclick="location.href='register.php'">Get started free</button>
      </div>
      <div class="p-card featured reveal d2">
        <div class="p-popular">Most popular</div>
        <div class="p-plan">Pro</div>
        <div class="p-price" data-monthly="₦6,500" data-annual="₦5,400">₦6,500 <sub>/ month</sub></div>
        <div class="p-desc">For merchants serious about growing.</div>
        <hr class="p-divider">
        <ul class="p-features">
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Unlimited payment links</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Custom branded slug</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Advanced analytics</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Order confirmation flow</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Delivery fee calculator</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Group order collection</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>0.8% transaction fee</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>₦5M/month cap</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Remove Kweek branding</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>2 team members</li>
        </ul>
        <button class="p-btn featured-btn" onclick="location.href='register.php?plan=pro'">Start 7-day free trial</button>
      </div>
      <div class="p-card reveal d3">
        <div class="p-plan">Business</div>
        <div class="p-price" data-monthly="₦15,000" data-annual="₦12,500">₦15,000 <sub>/ month</sub></div>
        <div class="p-desc">For teams and high-volume operations.</div>
        <hr class="p-divider">
        <ul class="p-features">
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Everything in Pro</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>5 team members</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Split payment / installments</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>API access</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>CSV + Excel export</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>0.5% transaction fee</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Unlimited transactions</li>
          <li class="p-feat"><i class="ti ti-check" aria-hidden="true"></i>Priority support</li>
        </ul>
        <button class="p-btn" onclick="location.href='contact.php'">Contact us</button>
      </div>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<div class="cta-section">
  <div class="cta-inner">
    <div class="section-label" style="justify-content:center;display:flex;margin-bottom:20px;"><i class="ti ti-rocket" aria-hidden="true"></i> Get started today</div>
    <h2 class="section-title reveal">Your first payment link is<br>free. Always.</h2>
    <p class="reveal d1">Join merchants across Nigeria who stopped chasing payments and started building real businesses.</p>
    <div class="email-capture reveal d2">
      <input type="email" placeholder="Enter your email address">
      <a href="register.php" class="btn-nav-primary" style="font-size:15px;padding:12px 24px;border-radius:var(--r-md);">Get started <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
    </div>
    <p style="font-size:12px;color:var(--n600);margin-top:14px;"><i class="ti ti-shield-lock" aria-hidden="true" style="vertical-align:-2px;"></i> No credit card required · Setup in 2 minutes</p>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <a href="index.php" class="logo">Kw<span class="acc">ee</span>k</a>
        <p class="footer-tagline">The payment operating system for Nigerian merchants who sell on WhatsApp.</p>
        <div class="footer-social">
          <a href="#" class="soc-btn" aria-label="Twitter"><i class="ti ti-brand-x" aria-hidden="true"></i></a>
          <a href="#" class="soc-btn" aria-label="Instagram"><i class="ti ti-brand-instagram" aria-hidden="true"></i></a>
          <a href="#" class="soc-btn" aria-label="LinkedIn"><i class="ti ti-brand-linkedin" aria-hidden="true"></i></a>
          <a href="#" class="soc-btn" aria-label="WhatsApp"><i class="ti ti-brand-whatsapp" aria-hidden="true"></i></a>
        </div>
      </div>
      <div class="footer-col"><h5>Product</h5><ul><li><a href="#how">How it works</a></li><li><a href="#features">Features</a></li><li><a href="#pricing">Pricing</a></li><li><a href="#">Security</a></li><li><a href="#">API docs</a></li></ul></div>
      <div class="footer-col"><h5>Company</h5><ul><li><a href="#">About us</a></li><li><a href="#">Blog</a></li><li><a href="#">Careers</a></li><li><a href="contact.php">Contact</a></li></ul></div>
      <div class="footer-col"><h5>Legal</h5><ul><li><a href="#">Terms of service</a></li><li><a href="#">Privacy policy</a></li><li><a href="#">AML/KYC policy</a></li><li><a href="#">Cookie policy</a></li></ul></div>
    </div>
    <div class="footer-bottom">
      <span>© 2026 Kweek Technologies Ltd · RC: 1234567 · Lagos, Nigeria</span>
      <div style="display:flex;gap:20px;flex-wrap:wrap;"><a href="#">Status</a><a href="#">Support</a><a href="#">Nomba Partner</a></div>
    </div>
  </div>
</footer>

<script>
window.addEventListener('load', () => { setTimeout(() => { document.getElementById('preloader').classList.add('hide'); }, 1600); });

const nav = document.getElementById('nav');
window.addEventListener('scroll', () => { nav.classList.toggle('scrolled', window.scrollY > 30); });

const hamburger = document.getElementById('hamburger');
const mobileMenu = document.getElementById('mobile-menu');
let menuOpen = false;
hamburger.addEventListener('click', () => {
  menuOpen = !menuOpen;
  mobileMenu.classList.toggle('open', menuOpen);
  hamburger.innerphp = menuOpen ? '<i class="ti ti-x" aria-hidden="true"></i>' : '<i class="ti ti-menu-2" aria-hidden="true"></i>';
});
mobileMenu.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => { menuOpen = false; mobileMenu.classList.remove('open'); hamburger.innerphp = '<i class="ti ti-menu-2" aria-hidden="true"></i>'; });
});

const reveals = document.querySelectorAll('.reveal');
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
}, { threshold: 0.12 });
reveals.forEach(r => observer.observe(r));

const monthlyBtn = document.getElementById('monthly-btn');
const annualBtn = document.getElementById('annual-btn');
const prices = document.querySelectorAll('.p-price');
monthlyBtn.addEventListener('click', () => {
  monthlyBtn.classList.add('active'); annualBtn.classList.remove('active');
  prices.forEach(p => { p.innerphp = p.dataset.monthly + ' <sub>/ month</sub>'; });
});
annualBtn.addEventListener('click', () => {
  annualBtn.classList.add('active'); monthlyBtn.classList.remove('active');
  prices.forEach(p => { p.innerphp = p.dataset.annual + ' <sub>/ month, billed annually</sub>'; });
});
</script>
</body>
</php>
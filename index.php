<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kweek — Get paid in any currency. Keep every order in one place.</title>
<meta name="description" content="Kweek helps merchants collect payments in naira and foreign currencies, verify payments instantly, track every order, and manage customers from one dashboard.">
<meta name="keywords" content="Kweek, online business management, payment links Nigeria, foreign currency payments, order tracking, Nigerian merchant dashboard, fintech Nigeria">
<meta name="author" content="Kweek Technologies Ltd">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://kweek.ng/">

<meta property="og:type" content="website">
<meta property="og:url" content="https://kweek.ng/">
<meta property="og:title" content="Kweek — Get paid in any currency. Keep every order in one place.">
<meta property="og:description" content="Collect payments in naira and foreign currencies, verify them instantly, and track every order from one dashboard.">
<meta property="og:image" content="https://kweek.ng/assets/img/kweek-og-banner.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="en_NG">
<meta property="og:site_name" content="Kweek">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Kweek — Get paid in any currency. Keep every order in one place.">
<meta name="twitter:description" content="Collect payments in naira and foreign currencies, verify them instantly, and track every order from one dashboard.">
<meta name="twitter:image" content="https://kweek.ng/assets/img/kweek-og-banner.png">

<link rel="icon" type="image/x-icon" href="/assets/img/kweek-icon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/kweek-icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/img/img/kweek-icon-16.png">
<link rel="apple-touch-icon" sizes="192x192" href="/assets/img/kweek-icon-192.png">
<link rel="manifest" href="/assets/site.webmanifest">
<meta name="theme-color" content="#4F43D4">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --p:#4F43D4;--pd:#3D33B8;--p50:#F2F0FC;--p100:#E5E1F9;
  --paper:#FAF9F6;--ink:#15131F;--g7:#3F3C4A;--g5:#6B6879;--line:#E6E3EC;--line2:#EFEDF3;
  --ok:#1E9E5A;--okbg:#E9F9F0;--warn:#B4750E;--warnbg:#FDF3E4;
  --font:'Space Grotesk',sans-serif;--mono:'Space Mono',ui-monospace,monospace;--r:10px;--rl:16px;
}
html{scroll-behavior:smooth;scroll-padding-top:84px}
body{font-family:var(--font);background:var(--paper);color:var(--ink);line-height:1.6;-webkit-font-smoothing:antialiased;overflow-x:hidden}
body.lock{overflow:hidden}
a{color:inherit}img,svg{display:block}
:focus-visible{outline:2px solid var(--p);outline-offset:3px;border-radius:4px}
.wrap{max-width:1160px;margin:0 auto;padding:0 28px}
section{padding:112px 0}
h1,h2,h3{line-height:1.05;letter-spacing:-.04em;font-weight:700}
h2{font-size:clamp(32px,4.4vw,56px)}
h2 .m{color:#9A97A6}
.label{font:700 12px var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--p);display:flex;align-items:center;gap:8px;margin-bottom:20px}
.label::before{content:'';width:8px;height:8px;background:var(--p)}
.center{text-align:center}.center .label{justify-content:center}
.lead{font-size:18px;color:var(--g5);max-width:560px;margin-top:18px}.center .lead{margin-inline:auto}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font:600 15px var(--font);padding:13px 24px;border-radius:var(--r);border:1.5px solid transparent;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s,color .15s;white-space:nowrap}
.btn-p{background:var(--p);color:#fff}.btn-p:hover{background:var(--pd)}
.btn-k{background:var(--ink);color:#fff}.btn-k:hover{background:#2c2840}
.btn-o{background:transparent;border-color:#CFCBD8;color:var(--ink)}.btn-o:hover{border-color:var(--ink)}
.btn-sm{padding:9px 18px;font-size:14px}

/* nav */
nav{position:fixed;inset:0 0 auto;z-index:50;background:rgba(250,249,246,.88);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.nav-in{height:68px;display:flex;align-items:center;justify-content:space-between}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-mark{width:32px;height:32px;border-radius:8px;background:var(--p);display:flex;align-items:center;justify-content:center}
.logo-mark svg{width:19px;height:19px}
.logo-word{font-weight:700;font-size:20px;letter-spacing:-.5px}
.links{display:flex;gap:34px;list-style:none}
.links a{font-size:14.5px;font-weight:500;color:var(--g7);text-decoration:none}.links a:hover{color:var(--p)}
.nav-cta{display:flex;gap:8px}
.ham{display:none;background:none;border:0;font-size:22px;padding:8px;cursor:pointer;color:var(--ink)}
.mnav{position:fixed;top:68px;left:0;right:0;bottom:0;background:var(--paper);z-index:49;padding:8px 28px 32px;display:flex;flex-direction:column;overflow-y:auto;opacity:0;visibility:hidden;transform:translateY(-10px);transition:opacity .25s,transform .3s,visibility .3s}
.mnav.open{opacity:1;visibility:visible;transform:none}
.mnav a{padding:18px 0;border-bottom:1px solid var(--line);text-decoration:none;font-weight:600;font-size:21px;letter-spacing:-.02em}
.mnav a.btn{font-size:16px;padding:14px 24px;margin-top:14px;border:1.5px solid transparent;letter-spacing:0}
.mnav a.btn-o{border-color:#CFCBD8}
.mnav a.btn-k{margin-top:10px}

/* hero */
.hero{padding:150px 0 96px;background:radial-gradient(700px 420px at 82% 18%,rgba(79,67,212,.10),transparent 70%)}
.hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:64px;align-items:center}
.hero h1{font-size:clamp(38px,6vw,80px);letter-spacing:-.055em;line-height:.98}
.hero h1 span{color:var(--p)}
.hero .sub{font-size:19px;color:var(--g7);margin:26px 0 34px;max-width:520px}
.hero-act{display:flex;gap:12px;flex-wrap:wrap}
.hero-note{margin-top:22px;font:400 13px var(--mono);color:var(--g5)}

/* paper receipt */
.rwrap{position:relative;max-width:470px;margin-left:auto;transform:rotate(1.6deg)}
.slot{height:18px;background:linear-gradient(#2c2842,#15131F);border-radius:9px;position:relative;z-index:3;box-shadow:0 10px 20px rgba(21,19,31,.35)}
.rpaper{filter:drop-shadow(0 30px 36px rgba(21,19,31,.16));margin:-9px 16px 0;position:relative;z-index:1}
.receipt{background:#fff;position:relative;padding:40px 32px 42px;font:400 15px/2 var(--mono);color:var(--ink)}
.receipt::after{content:'';position:absolute;left:0;right:0;bottom:-10px;height:10px;background:linear-gradient(135deg,#fff 50%,transparent 50%) 0 0/16px 16px,linear-gradient(225deg,#fff 50%,transparent 50%) 0 0/16px 16px}
.brand{text-align:center;font-weight:700;letter-spacing:.34em;font-size:18px}
.meta{display:flex;justify-content:space-between;color:var(--g5);font-size:13px;border-bottom:1px dashed #C9C5D3;padding:10px 0 14px;margin-bottom:10px}
.ln{display:flex;justify-content:space-between}
.tot{display:flex;justify-content:space-between;border-top:1px dashed #C9C5D3;margin-top:12px;padding-top:12px;font-weight:700;font-size:18px}
.ln.ok{color:var(--ok);margin-top:6px}
.bar{height:36px;margin-top:16px;background:repeating-linear-gradient(90deg,#15131F 0 2px,transparent 2px 5px,#15131F 5px 6px,transparent 6px 10px)}
.foot{text-align:center;color:var(--g5);font-size:11.5px;margin-top:12px;letter-spacing:.12em}
.stamp{position:absolute;right:22px;top:47%;border:4px solid var(--p);color:var(--p);font:700 28px var(--mono);letter-spacing:.14em;padding:0 14px;border-radius:8px;transform:rotate(-12deg);background:rgba(255,255,255,.75);opacity:0}
.r{opacity:0}
.play .receipt{animation:print 1.6s cubic-bezier(.45,.05,.3,1) both}
.play .r{animation:line .5s ease both;animation-delay:calc(.4s + var(--i)*.2s)}
.play .stamp{animation:stampIn .6s cubic-bezier(.2,1.5,.4,1) 2.4s both}
@keyframes print{from{clip-path:inset(0 0 100% 0);transform:translateY(-14px)}to{clip-path:inset(0 0 -24px 0);transform:none}}
@keyframes line{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@keyframes stampIn{0%{opacity:0;transform:scale(2.6) rotate(-30deg)}100%{opacity:.88;transform:scale(1) rotate(-12deg)}}
@keyframes floatY{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
@keyframes up{from{opacity:0;transform:translateY(26px)}to{opacity:1;transform:none}}
@keyframes tabin{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(79,67,212,.45)}100%{box-shadow:0 0 0 14px rgba(79,67,212,0)}}

/* stats */
.stats{background:var(--ink);color:#fff;position:relative;overflow:hidden;padding:68px 0 72px}
.stats::before{content:'';position:absolute;left:50%;top:-160px;width:760px;height:380px;transform:translateX(-50%);background:radial-gradient(closest-side,rgba(79,67,212,.5),transparent);pointer-events:none}
.stats .wrap{position:relative}
.stats-t{display:flex;align-items:center;gap:20px;font:700 12px var(--mono);letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.62)}
.stats-t::before,.stats-t::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.14)}
.st{display:grid;grid-template-columns:repeat(4,1fr);margin-top:44px}
.st>div{padding:6px 28px;border-left:1px solid rgba(255,255,255,.14)}
.st>div:first-child{border-left:0;padding-left:0}
.st b{display:block;font-size:clamp(34px,4.4vw,58px);letter-spacing:-.05em;line-height:1.05}
.st span{display:block;margin-top:8px;font-size:14px;color:rgba(255,255,255,.55)}
@media(max-width:860px){
  .stats{padding:52px 0}
  .st{grid-template-columns:1fr 1fr;row-gap:30px}
  .st>div{padding:0 0 0 20px}
  .st>div:nth-child(odd){border-left:0;padding-left:0}
}

/* tabs */
.tabs{display:flex;gap:6px;margin:40px 0 28px;border-bottom:1px solid var(--line)}
.tabs button{font:600 15px var(--font);background:none;border:0;border-bottom:2px solid transparent;padding:12px 22px;margin-bottom:-1px;color:var(--g5);cursor:pointer}
.tabs button.on{color:var(--ink);border-color:var(--p)}
.tp{display:none;grid-template-columns:.8fr 1.2fr;gap:56px;align-items:center}
.tp.on{display:grid}
.tp h3{font-size:28px;margin-bottom:12px}
.tp p{color:var(--g7);font-size:17px}
.frame{background:#fff;border:1px solid var(--line);border-radius:var(--rl);overflow:hidden;box-shadow:0 24px 60px -30px rgba(21,19,31,.3)}
.frame img{width:100%}

/* gap */
.gap-grid{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:start}
.gap-copy p{font-size:18px;color:var(--g7);margin-bottom:20px}
.gap-copy p strong{color:var(--ink)}

/* why */
.why{background:#fff;border-block:1px solid var(--line)}
.why-g{display:grid;grid-template-columns:repeat(4,1fr);margin-top:64px;border-top:1px solid var(--line)}
.why-g div{padding:30px 28px 6px 0}
.why-g div+div{padding-left:28px;border-left:1px solid var(--line)}
.why-g h3{font-size:22px;letter-spacing:-.02em;margin-bottom:10px}
.why-g p{color:var(--g5);font-size:15px}

/* ui rows */
.row2{display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center}
.row2+.row2{margin-top:120px}
.row2.rev .ui{order:-1}
.row2 h2{font-size:clamp(30px,3.6vw,46px);margin-bottom:18px}
.row2 p{font-size:17px;color:var(--g7);max-width:480px;margin-bottom:14px}
.row2 p b{color:var(--ink)}
.link{font-weight:600;color:var(--p);text-decoration:none;display:inline-block;margin-top:10px}
.link::after{content:' →'}
.ui{background:linear-gradient(160deg,var(--p50),#fff);border:1px solid var(--line);border-radius:var(--rl);padding:44px;display:flex;justify-content:center}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;width:100%;max-width:380px;font-size:14px;box-shadow:0 20px 50px -24px rgba(21,19,31,.25)}
.card h4{font-size:17px;letter-spacing:-.02em}.card small{color:var(--g5)}
.fld{border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-top:10px;display:flex;justify-content:space-between;color:var(--g7)}
.fld span:first-child{color:var(--g5)}
.cur{display:flex;gap:6px;margin:16px 0 4px}
.cur span{flex:1;text-align:center;padding:8px 0;border:1px solid var(--line);border-radius:8px;font:700 12px var(--mono);color:var(--g7)}
.cur .on{border-color:var(--p);background:var(--p50);color:var(--p)}
.card .btn{width:100%;margin-top:14px}
.sec{text-align:center;font-size:12px;color:var(--g5);margin-top:12px}
.sub-row{display:flex;justify-content:space-between;align-items:center;padding:13px 0;border-bottom:1px solid var(--line2)}
.sub-row b{display:block;font-size:15px}.sub-row small{color:var(--g5)}
.tag{font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px}
.tag.paid{background:var(--okbg);color:var(--ok)}.tag.un{background:var(--warnbg);color:var(--warn)}
.mrr{display:flex;justify-content:space-between;align-items:baseline;padding-top:16px;font-weight:700}
.mrr span:last-child{font-size:22px;letter-spacing:-.03em}
.mrr em{font:400 12px var(--mono);color:var(--ok);font-style:normal;margin-left:6px}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.chips span{font:400 12px var(--mono);background:var(--p50);color:var(--pd);padding:6px 10px;border-radius:6px}
.chips span::before{content:'✓ ';font-weight:700}
.sub-h{font:700 11px var(--mono);letter-spacing:.08em;color:var(--g5);text-transform:uppercase;margin-top:16px}

/* grid */
.gridf{display:grid;grid-template-columns:repeat(3,1fr);margin-top:64px;border:1px solid var(--line);border-radius:var(--rl);overflow:hidden;background:#fff}
.gridf div{padding:32px 30px;border-right:1px solid var(--line);border-bottom:1px solid var(--line)}
.gridf div:nth-child(3n){border-right:0}.gridf div:nth-last-child(-n+3){border-bottom:0}
.gridf i{width:48px;height:48px;border-radius:13px;background:var(--p50);color:var(--p);display:flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:18px;transition:transform .3s,background .3s,color .3s}
.gridf h3{font-size:19px;letter-spacing:-.02em;margin-bottom:8px}
.gridf p{font-size:14.5px;color:var(--g5)}

/* reviews */
.revs{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:56px}
.rv{background:#fff;border:1px solid var(--line);border-radius:var(--rl);padding:28px}
.rv p{font-size:16.5px;color:var(--g7);margin-bottom:24px}
.rv b{display:block;font-size:14px}.rv small{color:var(--g5)}

/* pricing */
.seg{display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:10px;padding:4px;margin:30px 0 48px}
.seg button{font:600 14px var(--font);border:0;background:none;padding:9px 20px;border-radius:7px;cursor:pointer;color:var(--g5)}
.seg button.on{background:var(--ink);color:#fff}
.seg em{font-style:normal;font-size:11px;background:var(--okbg);color:var(--ok);padding:2px 7px;border-radius:5px;margin-left:6px}
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:start;text-align:left}
.plan{background:#fff;border:1px solid var(--line);border-radius:var(--rl);padding:32px 28px;position:relative}
.plan.pop{border:2px solid var(--p);box-shadow:0 24px 54px -26px rgba(79,67,212,.5)}
.pop-t{position:absolute;top:-13px;left:28px;background:var(--p);color:#fff;font:700 11px var(--mono);letter-spacing:.06em;text-transform:uppercase;padding:4px 12px;border-radius:4px}
.plan h3{font-size:22px;letter-spacing:-.02em}
.plan .d{font-size:14px;color:var(--g5);min-height:44px;margin:4px 0 20px}
.amt{font-size:44px;font-weight:700;letter-spacing:-.05em;line-height:1}
.amt small{font-size:15px;font-weight:500;color:var(--g5);letter-spacing:0}
.note{font:400 12px var(--mono);color:var(--p);min-height:20px;margin:10px 0 4px}
.fee{font-size:13px;color:var(--g5);margin-bottom:22px}
.plan .btn{width:100%}
.plan ul{list-style:none;margin-top:24px;padding-top:22px;border-top:1px solid var(--line2);display:flex;flex-direction:column;gap:11px}
.plan li{font-size:14px;color:var(--g7);display:flex;gap:9px}
.plan li::before{content:'✓';color:var(--p);font-weight:700}
.fine{margin-top:26px;font:400 12.5px var(--mono);color:var(--g5)}

/* faq */
.faq{max-width:800px;margin:56px auto 0}
.fq{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:12px;transition:border-color .25s,box-shadow .3s,transform .25s}
.fq:hover{border-color:#cfcbe0;transform:translateY(-1px)}
.fq.open{border-color:var(--p);box-shadow:0 16px 38px -22px rgba(79,67,212,.5)}
.fq button{width:100%;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 24px;background:none;border:0;font:600 17px var(--font);letter-spacing:-.01em;color:var(--ink);text-align:left;cursor:pointer}
.fq .ic{flex:none;width:32px;height:32px;border-radius:50%;background:var(--p50);color:var(--p);display:flex;align-items:center;justify-content:center;font-size:16px;transition:transform .4s cubic-bezier(.2,.7,.2,1),background .25s,color .25s}
.fq.open .ic{transform:rotate(180deg);background:var(--p);color:#fff}
.fa{display:grid;grid-template-rows:0fr;transition:grid-template-rows .45s cubic-bezier(.2,.7,.2,1)}
.fq.open .fa{grid-template-rows:1fr}
.fa>div{overflow:hidden}
.fa p{padding:0 24px 24px;color:var(--g7);max-width:680px;opacity:0;transform:translateY(-8px);transition:opacity .3s,transform .45s}
.fq.open .fa p{opacity:1;transform:none;transition-delay:.1s}

/* dark close */
.dark{background:var(--ink);color:#fff}
.cta{text-align:center;padding:120px 28px 104px;position:relative}
.cta::before{content:'';position:absolute;left:50%;top:-40px;width:760px;height:420px;transform:translateX(-50%);background:radial-gradient(closest-side,rgba(79,67,212,.5),transparent);pointer-events:none}
.cta>*{position:relative}
.cta h2{color:#fff;font-size:clamp(34px,5vw,64px);max-width:820px;margin:0 auto}
.cta p{color:rgba(255,255,255,.62);font-size:18px;margin:20px auto 34px;max-width:520px}
.cta small{display:block;margin-top:18px;font:400 12.5px var(--mono);color:rgba(255,255,255,.45)}
footer{border-top:1px solid rgba(255,255,255,.1);padding:56px 0 28px;color:rgba(255,255,255,.55)}
footer .logo-word{color:#fff}
.fg{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:36px;margin-bottom:40px}
.fg p{font-size:14px;max-width:260px;margin-top:14px}
.fg h5{font:700 12px var(--mono);letter-spacing:.08em;text-transform:uppercase;color:#fff;margin-bottom:16px}
.fg ul{list-style:none;display:flex;flex-direction:column;gap:10px}
.fg a{font-size:14px;text-decoration:none}.fg a:hover{color:#fff}
.fb{border-top:1px solid rgba(255,255,255,.1);padding-top:22px;font:400 12.5px var(--mono)}

/* polish */
.btn{transition:background .15s,border-color .15s,color .15s,transform .2s,box-shadow .2s}
.btn:hover{transform:translateY(-2px)}
.btn-p:hover{box-shadow:0 12px 24px -10px rgba(79,67,212,.6)}
nav{transition:background .2s}
body.lock nav{background:var(--paper);backdrop-filter:none;-webkit-backdrop-filter:none}
.hero{position:relative;overflow:hidden}
.hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(#cdc8e0 1px,transparent 1px);background-size:22px 22px;-webkit-mask-image:linear-gradient(to bottom,#000 20%,transparent 85%);mask-image:linear-gradient(to bottom,#000 20%,transparent 85%);opacity:.7;pointer-events:none}
.hero .wrap{position:relative;z-index:1}
.hero h1{animation:up .8s cubic-bezier(.2,.7,.2,1) .05s both}
.hero .sub{animation:up .8s cubic-bezier(.2,.7,.2,1) .2s both}
.hero-act{animation:up .8s cubic-bezier(.2,.7,.2,1) .32s both}
.hero-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:26px;animation:up .8s cubic-bezier(.2,.7,.2,1) .44s both}
.hero-chips span{display:inline-flex;align-items:center;gap:7px;background:#fff;border:1px solid var(--line);border-radius:30px;padding:8px 14px;font-size:13px;font-weight:500;color:var(--g7)}
.hero-chips i{color:var(--p);font-size:16px}
.fl{position:absolute;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 14px;font:600 13px var(--font);display:flex;align-items:center;gap:8px;box-shadow:0 16px 36px -14px rgba(21,19,31,.3);animation:floatY 4.4s ease-in-out infinite;z-index:4;white-space:nowrap}
.fl i{color:var(--ok);font-size:16px}
.h1b{top:70px;left:-56px}.h2b{bottom:60px;right:-34px;animation-delay:-2.2s}
.u1{top:20px;right:20px}.u2{bottom:22px;left:20px;animation-delay:-2.2s}
.ui{position:relative;overflow:hidden}
.ui::before{content:'';position:absolute;inset:0;background-image:radial-gradient(#cdc8e0 1px,transparent 1px);background-size:20px 20px;opacity:.55;-webkit-mask-image:radial-gradient(closest-side,transparent 40%,#000);mask-image:radial-gradient(closest-side,transparent 40%,#000);pointer-events:none}
.card{position:relative;z-index:1;transition:transform .3s,box-shadow .3s}
.card:hover{transform:translateY(-4px);box-shadow:0 28px 56px -24px rgba(79,67,212,.4)}
.card .btn-p{animation:pulse 2.4s ease-out infinite}
.tp.on{animation:tabin .5s cubic-bezier(.2,.7,.2,1)}
.frame{transition:transform .35s,box-shadow .35s}
.frame:hover{transform:translateY(-4px)}
.why-g i{width:50px;height:50px;border-radius:14px;background:var(--p50);color:var(--p);display:flex;align-items:center;justify-content:center;font-size:25px;margin-bottom:20px;transition:transform .3s,background .3s,color .3s}
.why-g div:hover i,.gridf div:hover i{background:var(--p);color:#fff;transform:rotate(-6deg) scale(1.08)}
.gridf div{transition:background .25s}.gridf div:hover{background:#FBFAFF}
.gridf div:nth-child(2){background:var(--p50)}
.plan,.rv{transition:transform .3s,box-shadow .3s,border-color .3s}
.plan:hover,.rv:hover{transform:translateY(-6px);box-shadow:0 26px 50px -28px rgba(21,19,31,.3)}
.plan.pop::before{content:'';position:absolute;inset:-1px;border-radius:inherit;box-shadow:0 0 60px rgba(79,67,212,.22);pointer-events:none}
.js .reveal{opacity:0;transform:translateY(28px);transition:opacity .8s cubic-bezier(.2,.7,.2,1),transform .8s cubic-bezier(.2,.7,.2,1)}
.js .reveal.in{opacity:1;transform:none}
.js .ui :is(.sub-row,.fld,.chips span,.cur span,.mrr){opacity:0;transform:translateY(12px);transition:opacity .5s,transform .5s}
.js .ui.seen :is(.sub-row,.fld,.chips span,.cur span,.mrr){opacity:1;transform:none}
.js .ui.seen :is(.sub-row,.fld,.chips span,.cur span,.mrr):nth-child(2){transition-delay:.25s}
.js .ui.seen :is(.sub-row,.fld,.chips span,.cur span,.mrr):nth-child(3){transition-delay:.4s}
.js .ui.seen :is(.sub-row,.fld,.chips span,.cur span,.mrr):nth-child(4){transition-delay:.55s}
.js .ui.seen :is(.sub-row,.fld,.chips span,.cur span,.mrr):nth-child(5){transition-delay:.7s}
@media(max-width:560px){.fl{display:none}.fq button{font-size:16px;padding:18px 18px}.fa p{padding:0 18px 20px}}
@media(prefers-reduced-motion:reduce){.r{opacity:1!important}.stamp{opacity:.88!important}.js .ui :is(.sub-row,.fld,.chips span,.cur span,.mrr){opacity:1!important;transform:none!important}.js .reveal{opacity:1!important;transform:none!important}}
/* overflow fixes */
html{overflow-x:hidden;max-width:100%}
body{overflow-x:hidden;max-width:100%;position:relative}
@supports(overflow:clip){html,body{overflow-x:clip}}
.dark,.why,.hero,.stats{overflow:hidden}
.dark{overflow:clip}
.cta::before{max-width:100vw}
nav{width:100%;max-width:100%}
.nav-in{min-width:0}
.nav-in .logo{min-width:0}
.ham{flex:none;margin-right:-8px}
.mnav{width:100%;max-width:100%;left:0;right:0}
@media(max-width:860px){.nav-in{height:68px}.nav-cta,.links{display:none!important}.ham{display:flex!important;align-items:center;justify-content:center}}
@media(max-width:1000px){
  .gridf{grid-template-columns:1fr 1fr}
  .gridf div{border-right:1px solid var(--line)!important;border-bottom:1px solid var(--line)!important}
  .gridf div:nth-child(2n){border-right:0!important}
  .gridf div:last-child{border-bottom:0!important;grid-column:1/-1}
  .why-g{grid-template-columns:1fr 1fr}
  .why-g div{padding:28px 24px;border-left:0!important;border-bottom:1px solid var(--line)}
}
@media(max-width:860px){
  section{padding:76px 0}
  .links,.nav-cta{display:none}.ham{display:block}
  .hero{padding:112px 0 72px}
  .hero-grid,.gap-grid,.row2,.tp.on,.revs,.plans{grid-template-columns:1fr;gap:40px}
  .rwrap{display:none}
  .row2.rev .ui{order:0}.row2+.row2{margin-top:80px}
  .ui{padding:22px}
  .fg{grid-template-columns:1fr 1fr}
  .plans{gap:30px}
}
@media(max-width:560px){
  .gridf,.why-g{grid-template-columns:1fr}
  .gridf div{border-right:0!important;border-bottom:1px solid var(--line)!important}
  .gridf div:last-child{border-bottom:0!important}
  .hero-act .btn{flex:1}
  .tabs button{padding:12px 12px;font-size:14px}
  .fg{grid-template-columns:1fr}
}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
</head>
<body>

<nav>
  <div class="wrap nav-in">
    <a href="index.php" class="logo">
      <span class="logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#fff"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></span>
      <span class="logo-word">kweek</span>
    </a>
    <ul class="links">
      <li><a href="#features">Features</a></li><li><a href="#how">How it works</a></li>
      <li><a href="#pricing">Pricing</a></li><li><a href="#reviews">Reviews</a></li><li><a href="#faq">FAQ</a></li>
    </ul>
    <div class="nav-cta">
      <a href="login.php" class="btn btn-o btn-sm">Sign in</a>
      <a href="register.php" class="btn btn-k btn-sm">Get started</a>
    </div>
    <button class="ham" id="ham" aria-label="Open menu" aria-expanded="false"><i class="ti ti-menu-2"></i></button>
  </div>
</nav>
<div class="mnav" id="mnav">
  <a href="#features">Features</a><a href="#how">How it works</a><a href="#pricing">Pricing</a><a href="#reviews">Reviews</a><a href="#faq">FAQ</a>
  <a href="login.php" class="btn btn-o">Sign in</a><a href="register.php" class="btn btn-k">Get started</a>
</div>

<header class="hero">
  <div class="wrap hero-grid">
    <div>
      <h1>Payments built for businesses that <span>sell in chat.</span></h1>
      <p class="sub">Collect payments in naira and foreign currencies, verify every one instantly, and track each order. We handle the chaos, so you can focus on selling.</p>
      <div class="hero-act">
        <a href="register.php" class="btn btn-p">Start free</a>
        <a href="#pricing" class="btn btn-o">See pricing</a>
      </div>
      <div class="hero-chips"><span><i class="ti ti-world"></i> Foreign currencies</span><span><i class="ti ti-shield-check"></i> Instant verification</span><span><i class="ti ti-building-store"></i> No CAC required</span></div>
    </div>
    <div class="rwrap play" aria-label="Example Kweek receipt">
      <span class="fl h1b"><i class="ti ti-circle-check"></i> Payment verified</span>
      <span class="fl h2b"><i class="ti ti-world"></i> Paid in USD</span>
      <div class="slot"></div>
      <div class="rpaper"><div class="receipt">
        <div class="brand r" style="--i:0">KWEEK</div>
        <div class="meta r" style="--i:1"><span>Order #K-4821</span><span>May 8 · 14:22</span></div>
        <div class="ln r" style="--i:2"><span>Party pack × 1</span><span>$95.00</span></div>
        <div class="ln r" style="--i:3"><span>Delivery · Ikeja</span><span>$9.00</span></div>
        <div class="tot r" style="--i:4"><span>TOTAL</span><span>$104.00</span></div>
        <div class="ln ok r" style="--i:5"><span>Visa ···· 4242</span><span>✓ verified</span></div>
        <div class="bar r" style="--i:6"></div>
        <div class="foot r" style="--i:7">KWEEK · TEMI'S KITCHEN</div>
        <div class="stamp">PAID</div>
      </div></div>
    </div>
  </div>
</header>

<div class="stats">
  <div class="wrap">
    <div class="stats-t">Powering Nigerian merchants</div>
    <div class="st">
      <div><b data-to="2.4" data-dec="1" data-pre="₦" data-suf="B+">₦1M+</b><span>Processed this month</span></div>
      <div><b data-to="18000" data-dec="0" data-suf="+">1,000+</b><span>Active merchants</span></div>
      <div><b data-to="99.97" data-dec="2" data-suf="%">99.97%</b><span>Payment success rate</span></div>
      <div><b data-to="5" data-dec="0" data-pre="&lt;" data-suf="s">&lt;5s</b><span>Average notification time</span></div>
    </div>
  </div>
</div>

<section id="how">
  <div class="wrap">
    <div class="label">How it works</div>
    <h2>Start collecting payments <span class="m">in minutes.</span></h2>
    <div class="tabs" role="tablist">
      <button class="on" data-t="0">Create</button><button data-t="1">Collect</button><button data-t="2">Track</button>
    </div>
    <div class="tp on">
      <div><h3>Create a payment link</h3><p>Add the items, price, currency and delivery fee. Your link is ready in under a minute, and customers need no app to pay.</p></div>
      <div class="frame"><img src="assets/img/kweek-payment-link-crop.png" alt="Kweek payment link checkout page" loading="lazy"></div>
    </div>
    <div class="tp">
      <div><h3>Share it, get paid</h3><p>Drop the link into the chat you're already having. Kweek verifies the payment the moment it lands and updates your balance.</p></div>
      <div class="frame"><img src="assets/img/kweek-dashboard-crop.png" alt="Kweek dashboard with wallet balance" loading="lazy"></div>
    </div>
    <div class="tp">
      <div><h3>Every order, already organised</h3><p>Orders are recorded and tagged paid or unpaid automatically, with delivery details attached. No manual entry.</p></div>
      <div class="frame"><img src="assets/img/kweek-orders-crop.png" alt="Kweek orders page" loading="lazy"></div>
    </div>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap gap-grid">
    <div>
      <div class="label">The gap</div>
      <h2>You sell in the chat. <span class="m">Your payment setup wasn't built for it.</span></h2>
    </div>
    <div class="gap-copy">
      <p>You send your account number again and again. You scroll old threads to find an order, chase customers for screenshots, and lose track of who has paid. Delivery details sit buried in a conversation from three days ago.</p>
      <p>And when a customer abroad wants to pay in dollars or pounds, a naira-only setup turns them away.</p>
      <p><strong>Kweek replaces all of it.</strong></p>
    </div>
  </div>
</section>

<section class="why" id="features">
  <div class="wrap">
    <div class="label">Why Kweek works</div>
    <h2>Built for the merchant who can't keep <span class="m">refreshing a bank app.</span></h2>
    <div class="why-g">
      <div><i class="ti ti-bolt"></i><h3>Fast</h3><p>Live in two minutes. Payments are verified and you're notified in seconds.</p></div>
      <div><i class="ti ti-shield-lock"></i><h3>Secure</h3><p>Every transaction is checked server-side and produces a receipt that can't be faked.</p></div>
      <div><i class="ti ti-layout-dashboard"></i><h3>All-in-one</h3><p>Payment links, orders, customers and analytics in one dashboard. No chat scrolling.</p></div>
      <div><i class="ti ti-world"></i><h3>Global</h3><p>Customers pay in naira or a foreign currency from the same link.</p></div>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="row2">
      <div>
        <h2>One link for every customer, anywhere.</h2>
        <p>Build a payment link for any order and drop it into the chat. Customers pay by card or bank transfer, in naira or a foreign currency.</p>
        <p><b>Foreign currencies supported.</b> A buyer abroad is no longer a lost sale.</p>
        <a href="register.php" class="link">Create a payment link</a>
      </div>
      <div class="ui"><span class="fl u1"><i class="ti ti-world"></i> Paid in USD</span><span class="fl u2"><i class="ti ti-send"></i> Link shared</span>
        <div class="card" aria-label="Example payment link">
          <h4>Temi's Kitchen</h4><small>Party pack · Order #K-4821</small>
          <div class="cur"><span>NGN</span><span class="on">USD</span><span>GBP</span><span>EUR</span></div>
          <div class="fld"><span>Email</span><span>ada@example.com</span></div>
          <div class="fld"><span>Card</span><span>•••• 4242</span></div>
          <span class="btn btn-p">Pay $104.00</span>
          <div class="sec"><i class="ti ti-lock"></i> Secured by Kweek</div>
        </div>
      </div>
    </div>

    <div class="row2 rev">
      <div>
        <h2>Orders that track themselves.</h2>
        <p>Every order lands in one dashboard, tagged paid or unpaid automatically, with delivery details attached.</p>
        <p>Look up any customer's history and repeat orders without digging through old chats.</p>
        <a href="register.php" class="link">See the dashboard</a>
      </div>
      <div class="ui"><span class="fl u1"><i class="ti ti-bell-ringing"></i> New order</span><span class="fl u2"><i class="ti ti-circle-check"></i> Marked paid</span>
        <div class="card" aria-label="Example orders overview">
          <h4>Orders</h4><small>This week</small>
          <div class="sub-row" style="margin-top:8px"><div><b>Party pack, 20 plates</b><small>Femi O. · Ikeja</small></div><span class="tag un">Unpaid</span></div>
          <div class="sub-row"><div><b>Small chops tray</b><small>Aisha B. · Yaba</small></div><span class="tag paid">Paid</span></div>
          <div class="sub-row"><div><b>Family combo</b><small>Uche N. · Lekki</small></div><span class="tag paid">Paid</span></div>
          <div class="mrr"><span>Collected</span><span>₦86,500<em>↑ 12%</em></span></div>
        </div>
      </div>
    </div>

    <div class="row2">
      <div>
        <h2>Receipts that can't be faked.</h2>
        <p>Every payment is verified the moment it lands and generates a receipt link. No more wondering whether a bank alert screenshot is real.</p>
        <p>Withdraw straight to your Nigerian bank account whenever you need to.</p>
      </div>
      <div class="ui"><span class="fl u1"><i class="ti ti-shield-check"></i> Verified</span>
        <div class="card" aria-label="Example verified receipt">
          <h4>Receipt</h4><small>TXN-8847-KQPL</small>
          <div class="sub-row" style="margin-top:8px"><span>Amount</span><b>₦4,000.00</b></div>
          <div class="sub-row"><span>Merchant</span><b>Temi's Kitchen</b></div>
          <div class="sub-h">Automatically handled</div>
          <div class="chips"><span>payment_verified</span><span>order_recorded</span><span>customer_saved</span><span>receipt_issued</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap">
    <div class="center">
      <div class="label">Everything in one platform</div>
      <h2>Stop juggling <span class="m">five apps and a chat inbox.</span></h2>
      <p class="lead">One dashboard for payment links, foreign currency payments, orders, customers and analytics.</p>
    </div>
    <div class="gridf">
      <div><i class="ti ti-link"></i><h3>Payment links</h3><p>Share a link for any order straight into the chat.</p></div>
      <div><i class="ti ti-world"></i><h3>Foreign currencies</h3><p>Accept payments from customers abroad, not only in naira.</p></div>
      <div><i class="ti ti-shield-check"></i><h3>Instant verification</h3><p>Every payment checked and receipted the moment it lands.</p></div>
      <div><i class="ti ti-list-check"></i><h3>Order tracking</h3><p>Paid and unpaid tagged automatically, with delivery details.</p></div>
      <div><i class="ti ti-users-group"></i><h3>Customer profiles</h3><p>Lifetime spend and repeat-order history for every customer.</p></div>
      <div><i class="ti ti-chart-line"></i><h3>Analytics</h3><p>Revenue trend, order volume and average order value, exportable anytime.</p></div>
      <div><i class="ti ti-building-bank"></i><h3>Withdrawals</h3><p>Move money to your Nigerian bank account from the dashboard.</p></div>
      <div><i class="ti ti-api"></i><h3>APIs &amp; webhooks</h3><p>Connect Kweek to your own systems on the Business plan.</p></div>
      <div><i class="ti ti-users"></i><h3>Team access</h3><p>Give your team access to orders and payments on the Business plan.</p></div>
    </div>
  </div>
</section>

<section id="reviews" class="why">
  <div class="wrap">
    <div class="label">What merchants say</div>
    <h2>Real merchants. <span class="m">Real chaos, solved.</span></h2>
    <div class="revs">
      <div class="rv"><p>"My biggest issue was finding old orders. Now every order is right there in my dashboard, tagged and searchable."</p><b>Adaeze T.</b><small>Food vendor, Abuja</small></div>
      <div class="rv"><p>"A customer sent a fake bank alert once. Now I just check the verified receipt link. That alone paid for itself."</p><b>Emeka M.</b><small>Solar merchant, Port Harcourt</small></div>
      <div class="rv"><p>"Mixing up paid and unpaid orders wasted my time every single day. Kweek tags every order automatically."</p><b>Biodun K.</b><small>Fashion vendor, Lagos</small></div>
    </div>
  </div>
</section>

<section id="pricing">
  <div class="wrap center">
    <div class="label">Pricing</div>
    <h2>Pricing that grows <span class="m">with you.</span></h2>
    <p class="lead">Every plan includes the full dashboard. The difference is your transaction fee and monthly volume.</p>
    <div class="seg" role="group" aria-label="Billing period">
      <button id="bm" class="on" onclick="bill('month')">Monthly</button>
      <button id="by" onclick="bill('year')">Yearly<em>Save ~17%</em></button>
    </div>
    <div class="plans">
      <div class="plan">
        <h3>Starter</h3><div class="d">For new online businesses just getting off the ground.</div>
        <div class="amt"><span data-month="₦5,000" data-year="₦50,000">₦5,000</span><small data-month="/month" data-year="/year">/month</small></div>
        <div class="note" data-note data-year="Billed ₦50,000 once a year"></div>
        <div class="fee">2.3% fee · ₦750,000 monthly cap · ₦100 withdrawals</div>
        <a href="register.php?plan=starter" class="btn btn-o">Start free</a>
        <ul><li>Up to 10 active payment links</li><li>2.3% transaction fee</li><li>₦750,000 monthly volume cap</li><li>₦100 withdrawal fee</li><li>No "Powered by Kweek" branding</li></ul>
      </div>
      <div class="plan pop">
        <div class="pop-t">Most popular</div>
        <h3>Pro</h3><div class="d">For growing merchants processing higher volume.</div>
        <div class="amt"><span data-month="₦10,000" data-year="₦100,000">₦10,000</span><small data-month="/month" data-year="/year">/month</small></div>
        <div class="note" data-note data-year="Billed ₦100,000 once a year"></div>
        <div class="fee">1.8% fee · ₦5,000,000 monthly cap · Free withdrawals</div>
        <a href="register.php?plan=pro" class="btn btn-p">Start free</a>
        <ul><li>50 payment links</li><li>1.8% transaction fee</li><li>₦5,000,000 monthly volume cap</li><li>Free withdrawals</li><li>No "Powered by Kweek" branding</li><li>Priority support</li><li>Analytics</li></ul>
      </div>
      <div class="plan">
        <h3>Business</h3><div class="d">For high-volume businesses that need room to scale.</div>
        <div class="amt"><span data-month="₦15,000" data-year="₦150,000">₦15,000</span><small data-month="/month" data-year="/year">/month</small></div>
        <div class="note" data-note data-year="Billed ₦150,000 once a year"></div>
        <div class="fee">1.6% fee · Unlimited volume · Free withdrawals</div>
        <a href="register.php?plan=business" class="btn btn-o">Start free</a>
        <ul><li>Unlimited payment links</li><li>1.6% transaction fee</li><li>Unlimited monthly volume</li><li>Free withdrawals</li><li>No "Powered by Kweek" branding</li><li>Priority support</li><li>APIs and webhooks</li><li>Advanced analytics</li><li>Team access</li></ul>
      </div>
    </div>
    <p class="fine">Start free on any plan. Upgrade or change plans anytime from your dashboard.</p>
  </div>
</section>

<section id="faq" style="padding-top:0">
  <div class="wrap">
    <h2 class="center">FAQs</h2>
    <div class="faq">
      <div class="fq open"><button type="button" aria-expanded="true">Can customers pay in foreign currencies?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>Yes. Kweek lets you accept payments in foreign currencies as well as naira, so customers abroad can pay from the same payment link.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">Is Kweek free?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>You can start free and try the dashboard before committing to a plan. Paid plans unlock higher processing limits and lower transaction fees as your business grows.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">Do I need CAC to sign up?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>No. Kweek is built for merchants who are just starting out. You can sign up and start collecting payments without a registered business.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">How do withdrawals work?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>Withdraw straight to your Nigerian bank account from your dashboard. Starter carries a small withdrawal fee; Pro and Business withdrawals are free.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">What payment methods are supported?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>Customers can pay by bank transfer or card directly from the payment link. No app download is needed on their end.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">Can I use Kweek without a website?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>Yes. Kweek runs entirely through chat. Payment links, order tracking and receipts all work without a storefront or website.</p></div></div></div>
      <div class="fq"><button type="button" aria-expanded="false">How long does setup take?<span class="ic"><i class="ti ti-chevron-down"></i></span></button><div class="fa"><div><p>Most merchants are live and sharing their first payment link within two minutes of signing up.</p></div></div></div>
    </div>
  </div>
</section>

<div class="dark">
  <div class="wrap cta">
    <h2>Your payment dashboard is ready.</h2>
    <p>Built for merchants who sell, get paid and stay organised, in any currency.</p>
    <a href="register.php" class="btn btn-p">Start using Kweek</a>
    <small>No credit card required. No CAC required.</small>
  </div>
  <footer>
    <div class="wrap">
      <div class="fg">
        <div>
          <a href="index.php" class="logo"><span class="logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#fff"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></span><span class="logo-word">kweek</span></a>
          <p>The all-in-one dashboard for merchants who sell, get paid and stay organised.</p>
        </div>
        <div><h5>Features</h5><ul><li><a href="#features">Payment links</a></li><li><a href="#features">Foreign currencies</a></li><li><a href="#features">Order tracking</a></li><li><a href="#features">Analytics</a></li></ul></div>
        <div><h5>Resources</h5><ul><li><a href="#how">How it works</a></li><li><a href="#pricing">Pricing</a></li><li><a href="#faq">FAQ</a></li></ul></div>
        <div><h5>Company</h5><ul><li><a href="terms.php">Terms</a></li><li><a href="privacy-policy.php">Privacy</a></li></ul></div>
        <div><h5>Support</h5><ul><li><a href="contact.php">Contact</a></li><li><a href="login.php">Sign in</a></li></ul></div>
      </div>
      <div class="fb">© 2026 Kweek Technologies Ltd · Lagos, Nigeria</div>
    </div>
  </footer>
</div>

<script src="/assets/js/kw-forms.js"></script>
<script>
const ham=document.getElementById('ham'),mnav=document.getElementById('mnav');
function menu(o){mnav.classList.toggle('open',o);document.body.classList.toggle('lock',o);ham.setAttribute('aria-expanded',o);ham.firstElementChild.className=o?'ti ti-x':'ti ti-menu-2'}
ham.addEventListener('click',()=>menu(!mnav.classList.contains('open')));
mnav.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>menu(false)));
const tb=document.querySelectorAll('.tabs button'),tp=document.querySelectorAll('.tp');
tb.forEach(b=>b.addEventListener('click',()=>{tb.forEach((x,i)=>{x.classList.toggle('on',x===b);tp[i].classList.toggle('on',x===b)})}));
function bill(m){
  document.getElementById('bm').classList.toggle('on',m==='month');
  document.getElementById('by').classList.toggle('on',m==='year');
  document.querySelectorAll('[data-month]').forEach(e=>e.textContent=e.dataset[m]);
  document.querySelectorAll('[data-note]').forEach(e=>e.textContent=m==='year'?e.dataset.year+' · save ~17%':'');
}
document.documentElement.classList.add('js');
document.addEventListener('keydown',e=>{if(e.key==='Escape')menu(false)});
matchMedia('(min-width:861px)').addEventListener('change',e=>{if(e.matches)menu(false)});
/* faq: one open at a time */
const fqs=document.querySelectorAll('.fq');
fqs.forEach(f=>f.querySelector('button').addEventListener('click',()=>{
  const was=f.classList.contains('open');
  fqs.forEach(x=>{x.classList.remove('open');x.querySelector('button').setAttribute('aria-expanded','false')});
  if(!was){f.classList.add('open');f.querySelector('button').setAttribute('aria-expanded','true')}
}));
/* receipt replay */
const rw=document.querySelector('.rwrap');
if(rw&&!matchMedia('(prefers-reduced-motion:reduce)').matches){setInterval(()=>{rw.classList.remove('play');void rw.offsetWidth;rw.classList.add('play')},11000)}
/* scroll reveal + count up */
const cnt=(el)=>{
  const to=+el.dataset.to,dec=+el.dataset.dec,pre=el.dataset.pre||'',suf=el.dataset.suf||'',t0=performance.now();
  const fmt=v=>pre+v.toLocaleString('en-US',{minimumFractionDigits:dec,maximumFractionDigits:dec})+suf;
  (function tick(n){const p=Math.min((n-t0)/1400,1),e=1-Math.pow(1-p,3);el.textContent=fmt(to*e);if(p<1)requestAnimationFrame(tick)})(t0);
};
const sel='section h2,.label,.lead,.tabs,.gap-copy,.why-g>div,.row2>div,.gridf>div,.rv,.plan,.seg,.fq,.fine,.st>div,.cta>*';
const groups=new Map();
document.querySelectorAll(sel).forEach(el=>{
  const k=el.parentElement,i=groups.get(k)||0;groups.set(k,i+1);
  el.classList.add('reveal');el.style.transitionDelay=Math.min(i,6)*70+'ms';el.dataset.d=Math.min(i,6)*70;
});
const io=new IntersectionObserver(es=>es.forEach(en=>{
  if(!en.isIntersecting)return;
  const el=en.target;io.unobserve(el);
  el.classList.add('in','seen');
  const b=el.matches('.st>div')?el.querySelector('b'):null;if(b)cnt(b);
  setTimeout(()=>{el.classList.remove('reveal','in');el.style.transitionDelay=''},900+(+el.dataset.d||0));
}),{threshold:.12,rootMargin:'0px 0px -40px 0px'});
document.querySelectorAll('.reveal,.ui').forEach(el=>io.observe(el));
</script>
</body>
</html>
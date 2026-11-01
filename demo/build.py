import json, re
root='/home/claude/kca-esign'
css=open(f'{root}/public/assets/app.css').read()
js=open(f'{root}/demo/app.js').read()
be=open(f'{root}/demo/demo-backend.js').read().replace('__PORTAL__', json.dumps(json.load(open(f'{root}/data/mock_portal.json')),ensure_ascii=False))

# --- css: move literals to theme tokens ---
css=css.replace("color:var(--navy)","color:var(--head)")
css=css.replace("border:1px solid var(--navy)","border:1px solid var(--head)")
css=re.sub(r"^:root\{[^\n]*\n","",css,count=1)
css=css.replace("background:#fff","background:var(--card)").replace("#9AA5B8","var(--edge)").replace("#EEF1F6","var(--ro)").replace("#EEF3FB","var(--hover)").replace("#F7F9FD","var(--hover)").replace("#EAF1FB","var(--info)").replace("#6B7688","var(--mute)")
css=css.replace(".logo{width:38px;height:38px;border-radius:4px;background:var(--gold);color:var(--head)",".logo{width:38px;height:38px;border-radius:4px;background:var(--gold);color:var(--navy)")
css=css.replace(".top-in{max-width:1100px;margin:0 auto;padding:10px 20px;",".top-in{max-width:1100px;margin:0 auto;padding-block:10px;padding-inline:max(16px,env(safe-area-inset-left,0px)) max(16px,env(safe-area-inset-right,0px));")
css=css.replace("main{max-width:1100px;margin:0 auto;padding:24px 20px 64px}","main{max-width:1100px;margin:0 auto;padding-block:24px 64px;padding-inline:max(16px,env(safe-area-inset-left,0px)) max(16px,env(safe-area-inset-right,0px))}")
css=css.replace("position:sticky;top:0;z-index:5","position:sticky;top:env(safe-area-inset-top,0px);z-index:5")
css=css.replace("body{margin:0;background:var(--bg);color:var(--ink);","body{margin:0;background:var(--bg);color:var(--ink);min-width:0;")
css=css.replace(".btn.gold{background:var(--gold);border-color:var(--gold)}",".btn.gold{background:var(--gold);border-color:var(--gold);color:var(--navy)}")
css=css.replace(".err{",".err{border:1px solid var(--er);").replace(".card.red{border-left:4px solid var(--er)}",".card.red{border-left:4px solid var(--er)}")
css=css.replace("#toast{position:fixed;left:50%;bottom:24px;","#toast{position:fixed;left:50%;bottom:calc(24px + env(safe-area-inset-bottom,0px));")
css=css.replace("background:var(--navy);color:#fff;padding:14px 20px","background:var(--toast);color:#fff;padding:14px 20px")
css=css.replace(".row.pick{",".row.pick{color:var(--ink);")
css=css.replace("@media print{.top,#toast,.btn{display:none}}","")

tokens="""
/* Layout: KCA portal look. Navy header band with a gold rule, white work cards on a pale field, one gold primary action per screen. */
:root{--navy:#0B2A5B;--gold:#F2A900;--head:#0B2A5B;--bg:#F3F5F9;--card:#FFFFFF;--ink:#1B2433;--mute:#3A4558;--line:#D5DBE6;--edge:#9AA5B8;--ro:#EEF1F6;--hover:#EEF3FB;--info:#EAF1FB;--toast:#0B2A5B;
--ok-bg:#E6F4EA;--ok:#14532D;--wn-bg:#FFF4D6;--wn:#5C3D00;--er-bg:#FDE8E8;--er:#8A1C1C;--font:"Open Sans",Arial,Helvetica,sans-serif}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--head:#A9C4F2;--bg:#0E141F;--card:#172033;--ink:#E7ECF5;--mute:#A6B2C6;--line:#2B3850;--edge:#5B6B87;--ro:#1F2A40;--hover:#1D2A44;--info:#1A2B4A;--toast:#24365A;--ok-bg:#12331F;--ok:#8FDDA8;--wn-bg:#3A2F10;--wn:#F5D58A;--er-bg:#3D1A1C;--er:#F5A3A6;color-scheme:dark}}
:root[data-theme="dark"]{--head:#A9C4F2;--bg:#0E141F;--card:#172033;--ink:#E7ECF5;--mute:#A6B2C6;--line:#2B3850;--edge:#5B6B87;--ro:#1F2A40;--hover:#1D2A44;--info:#1A2B4A;--toast:#24365A;--ok-bg:#12331F;--ok:#8FDDA8;--wn-bg:#3A2F10;--wn:#F5D58A;--er-bg:#3D1A1C;--er:#F5A3A6;color-scheme:dark}
body::before{content:"";position:fixed;inset:0 0 auto;height:env(safe-area-inset-top,0px);background:var(--navy);z-index:10}
body{font-family:var(--font)}
"""
demo_css="""
[hidden]{display:none !important}
.kiosk{font-size:12px;color:#D9E2F2;opacity:.85;margin-left:auto}
.who-card{display:flex;gap:16px;align-items:center}
.mug{width:84px;height:104px;object-fit:cover;border-radius:6px;border:2px solid var(--gold);flex:none;background:var(--ro)}
.opt{display:flex;gap:8px;align-items:center;padding:10px 0;font-weight:600;min-height:44px}
.opt input{width:22px;height:22px;flex:none}
.tl{border-left:3px solid var(--gold);padding:2px 0 2px 10px;margin:8px 0}
.card.inc{border-left:4px solid var(--er)}
.sigprev{background:#fff;border:1px solid var(--line);border-radius:4px;padding:6px;margin:6px 0;height:90px;display:flex;align-items:center;justify-content:center;color:#5A6577}
.sigprev img{max-height:78px;max-width:100%}
.fp{background:var(--ro);border-radius:4px;padding:8px 10px;margin-top:8px}
.keyuse{margin-top:6px;border-top:1px solid var(--line);padding-top:6px}
.paper .tag{font-size:10px;color:#5A6577;font-weight:600}.paper .tag.red{color:#8A1C1C}
.paper .sec{border-top:1px solid #D5DBE6;padding-top:10px;display:flex;flex-direction:column;gap:4px}
.paper .ab{font-size:11px}.paper .inc{border-left:3px solid #8A1C1C;padding-left:8px;margin-top:6px}
.auth{display:flex;gap:16px;align-items:center;justify-content:space-between;border-top:1px dashed #0B2A5B;padding-top:10px;flex-wrap:wrap}
.authsig,.authpat{display:flex;flex-direction:column;gap:4px}.dirsig{height:46px;width:auto;max-width:180px}
#demobar{background:#FFE9A8;color:#3A2A00;font-size:13px}
#demobar .dbin{max-width:1100px;margin:0 auto;padding-block:8px;padding-inline:max(16px,env(safe-area-inset-left,0px)) max(16px,env(safe-area-inset-right,0px));display:flex;gap:8px 14px;align-items:center;flex-wrap:wrap}
#demobar b{background:#3A2A00;color:#FFE9A8;border-radius:3px;padding:1px 7px;letter-spacing:.08em;font-size:12px}
.dq{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.dbtn{border:1px solid #3A2A00;background:transparent;color:#3A2A00;border-radius:4px;padding:4px 10px;min-height:32px;cursor:pointer;font-weight:600}
.dbtn:hover{background:#FFF4D6}
.dmsg{flex-basis:100%;font-weight:600}
.ov{position:fixed;inset:0;background:rgba(8,16,34,.66);z-index:50;overflow:auto;padding-block:max(16px,env(safe-area-inset-top,0px)) max(16px,env(safe-area-inset-bottom,0px));padding-inline:max(12px,env(safe-area-inset-left,0px)) max(12px,env(safe-area-inset-right,0px))}
.ovbox{background:var(--bg);color:var(--ink);max-width:860px;margin:0 auto;border-radius:6px;padding:16px;display:flex;flex-direction:column;gap:14px;min-width:0}
.ovbox.sm{max-width:440px;margin-top:20vh}
.ovbar{display:flex;justify-content:space-between;align-items:center;gap:12px}
.note{font-size:13px;color:var(--mute);margin:0}
.mail{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:14px;font-size:13px;display:flex;flex-direction:column;gap:6px;min-width:0}
.mail small{display:inline-block;width:56px;color:var(--mute);font-weight:700}
.mail pre{white-space:pre-wrap;word-break:break-word;font:inherit;margin:8px 0;padding:10px 0;border-block:1px solid var(--line)}
.att{display:inline-block;background:var(--info);color:var(--head);border-radius:4px;padding:5px 10px;font-weight:600;word-break:break-all}
.paper{background:#fff;color:#1B2433;border:1px solid #D5DBE6;font-size:12px;min-width:0}
.phead{background:#0B2A5B;color:#fff;border-bottom:4px solid #F2A900;padding:14px 18px;display:flex;gap:12px;align-items:center}
.phead>div:nth-child(2){flex:1}.phead div div{font-size:11px;color:#D9E2F2}
.plogo{background:#F2A900;color:#0B2A5B;font-weight:700;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:10px;flex:none}
.pr{font-size:12px}.pbody{padding:18px;display:flex;flex-direction:column;gap:12px}
.paper h3{margin:0;font-size:18px;color:#0B2A5B}.pm{margin:0;color:#5A6577}
.pgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 24px}
.paper small{display:block;font-size:10px;font-weight:700;color:#5A6577;letter-spacing:.05em}
.pbox{border:1px solid #D5DBE6;height:110px;display:flex;align-items:center;justify-content:center;padding:6px}
.pbox .psig{max-height:98px}
.tw{overflow-x:auto}.paper table{border-collapse:collapse;width:100%;min-width:560px}
.paper th{background:#0B2A5B;color:#fff;text-align:left;font-size:10px;padding:6px}
.paper td{border-bottom:1px solid #D5DBE6;padding:4px 6px;font-size:11px;vertical-align:middle}
.paper td .psig{height:34px;width:auto;max-width:120px;display:block}
.seal{background:#F3F5F9;border:1px solid #0B2A5B;border-left:5px solid #F2A900;padding:12px 14px;display:flex;flex-direction:column;gap:6px;min-width:0}
.seal .code{font-size:16px;font-weight:700;word-break:break-all}.sealg{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;word-break:break-all}
.sheet-note{margin:0}
@media (prefers-reduced-motion:reduce){#toast{transition:none}}
"""
head="""<title>KCA e-Sign Sheet</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
"""
theme=open(f'{root}/demo/theme.css').read()
import base64
logo="data:image/png;base64,"+base64.b64encode(open(f'{root}/demo/kca-logo-300.png','rb').read()).decode()
out=head+"<style>"+tokens+css+demo_css+theme+"</style>\n<div id=\"demo-slot\"></div>\n<div id=\"app\"></div>\n<div id=\"toast\" role=\"status\" aria-live=\"polite\"></div>\n<script>window.KCA_LOGO=\""+logo+"\";</script>\n<script>\n"+be+"\n</script>\n<script>\n"+js+"\n</script>\n"
open(f'{root}/demo/kca-esign-demo.html','w').write(out)
print(len(out))

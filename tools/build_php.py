"""Split solution-builder.html into the PHP app's assets and patch the JS for server mode."""
import re, sys, pathlib
here = pathlib.Path(__file__).parent
src = (here.parent / 'standalone' / 'solution-builder.html').read_text()
out = here.parent / 'assets'

css = re.search(r'<style>\n(.*?)</style>', src, re.S).group(1)
js = re.search(r'<script>\n(.*?)</script>\s*$', src, re.S).group(1)

def rep(a, b, n=1):
    global js
    c = js.count(a)
    assert c == n, (c, a[:90])
    js = js.replace(a, b)

# 1. State: from the server when SSB_SERVER is present
rep('''let S = null;
try { S = JSON.parse(localStorage.getItem(STORE) || "null"); } catch (e) { S = null; }
if (!S || !S.customer) {''', '''const SRV = window.SSB_SERVER || null;
let S = null;
if (SRV) {
  if (SRV.state && SRV.state.customer) S = deepFill(SRV.state, defaults());
  else {
    const sd = SRV.seed || {}, c = sd.carry || {};
    S = sd.mode === "example" ? example() : defaults();
    if (c.brand) S.brand = Object.assign(defaults().brand, c.brand);
    if (c.docFormat) S.docFormat = c.docFormat;
    if (c.company) S.com.company = c.company;
    if (sd.name) { S.customer.name = sd.name; S.isExample = false; }
    if (sd.project) S.customer.project = sd.project;
    SRV.firstSave = true;
  }
}
else {
try { S = JSON.parse(localStorage.getItem(STORE) || "null"); } catch (e) { S = null; }
if (!S || !S.customer) {''')
rep('''else deepFill(S, defaults());
let step = 0;
try { step = Number(localStorage.getItem(STORE + "-step") || 0) || 0; } catch (e) {}
function save() { try {''', '''else deepFill(S, defaults());
}
let step = 0;
if (SRV) step = Math.max(0, Math.min(7, Number(SRV.step) || 0));
else { try { step = Number(localStorage.getItem(STORE + "-step") || 0) || 0; } catch (e) {} }

/* ---------- Server mode: autosave to the PHP API (MySQL) ---------- */
let srvTimer = null, srvBusy = false, srvDirty = false, srvBlocked = false;
function setSaveSt(t, kind) { const el = document.getElementById("saveSt"); if (!el) return; el.textContent = t; el.dataset.kind = kind || ""; }
async function srvApi(action, body) {
  const res = await fetch("api.php?action=" + encodeURIComponent(action), { method: "POST", credentials: "same-origin",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": SRV.csrf }, body: JSON.stringify(body) });
  let j = null; try { j = await res.json(); } catch (e) {}
  if (!res.ok || !j || !j.ok) { const err = new Error((j && j.error) || ("Server error " + res.status)); err.status = res.status; err.data = j; throw err; }
  return j;
}
function srvMeta() {
  try { const b = buildBom(); return { customer: S.customer.name || "", project: S.customer.project || "", provider: P().short, version: String(S.customer.version || ""),
    monthly: +(+b.total).toFixed(2), once: +(+b.onceTotal).toFixed(2), days: +b.days || 0 }; }
  catch (e) { return { customer: S.customer.name || "", project: S.customer.project || "" }; }
}
async function srvFlush() {
  if (srvBlocked) return;
  if (srvBusy) { clearTimeout(srvTimer); srvTimer = setTimeout(srvFlush, 400); return; }
  if (!srvDirty) return;
  srvBusy = true; srvDirty = false;
  const meta = srvMeta();
  try {
    const r = await srvApi("save", { id: SRV.id, rev: SRV.rev, step, state: S, meta });
    SRV.rev = r.rev;
    const t = meta.project || meta.customer || "Untitled project", h1 = document.getElementById("projTitle");
    if (h1 && h1.textContent !== t) h1.textContent = t;
    document.title = t + " · Solution Summary Builder";
    if (!srvDirty) setSaveSt("All changes saved", "ok");
  } catch (e) {
    srvDirty = true;
    if (e.status === 409) { srvBlocked = true; setSaveSt("Not saved: this project was saved from another window. Reload the page to get the latest version.", "err"); }
    else if (e.status === 401 || e.status === 403) { setSaveSt("Not saved: you were signed out. Sign in again in another tab; this page keeps trying.", "err"); clearTimeout(srvTimer); srvTimer = setTimeout(srvFlush, 15000); }
    else { setSaveSt("Not saved: " + e.message + " Retrying...", "err"); clearTimeout(srvTimer); srvTimer = setTimeout(srvFlush, 5000); }
  } finally { srvBusy = false; }
}
if (SRV) {
  window.addEventListener("beforeunload", e => { if (srvDirty || srvBusy) { e.preventDefault(); e.returnValue = ""; } });
  if (SRV.firstSave) setTimeout(() => save(), 0);
}
function save() {
  if (SRV) { if (srvBlocked) return; srvDirty = true; setSaveSt("Saving...", ""); clearTimeout(srvTimer); srvTimer = setTimeout(srvFlush, 700); return; }
  try {''')

# 2. Downloads: a normal browser download outside claude.ai
rep('''function downloads() { if (!dlP) dlP = window.claude?.use ? window.claude.use("downloads").catch(() => null) : Promise.resolve(null); return dlP; }''',
'''const browserDownloads = { save: async ({ filename, data }) => {
  const types = { pdf: "application/pdf", docx: "application/vnd.openxmlformats-officedocument.wordprocessingml.document", xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" };
  const blob = data instanceof Blob ? data : new Blob([data], { type: types[String(filename).split(".").pop()] || "application/octet-stream" });
  const url = URL.createObjectURL(blob), a = document.createElement("a");
  a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000); return { status: "saved" }; } };
function downloads() { if (!dlP) dlP = (window.claude?.use && !SRV ? window.claude.use("downloads").catch(() => null) : Promise.resolve(null)).then(d => d || browserDownloads); return dlP; }''')

# 3. BOM template: stored in MySQL (own or company default)
rep('''function tplInfo() { if (tplMetaMem)''', '''function tplInfo() { if (SRV) return SRV.tpl || null; if (tplMetaMem)''')
rep('''function tplBuffer() {
  if (tplCache) return tplCache;''', '''function tplBuffer() {
  if (tplCache) return tplCache;
  if (SRV) { if (SRV.tplData) { const bin = atob(SRV.tplData); const u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i); tplCache = u.buffer; } return tplCache; }''')
rep('''    try { localStorage.setItem(TPL_KEY, btoa(s)); localStorage.setItem(TPL_KEY + "-meta", JSON.stringify(meta)); tplMetaMem = null; }
    catch (e) { tplMetaMem = meta; }''', '''    if (SRV) {
      msg("Saving template to the database...", "var(--muted)");
      const scope = document.getElementById("tplCompany")?.checked ? "company" : "user";
      try { const r = await srvApi("template_put", { name: file.name, size: file.size, sheets: names, scope, data: btoa(s) }); SRV.tpl = r.tpl; SRV.tplData = btoa(s); }
      catch (e) { tplCache = null; msg("Could not save the template: " + e.message, "var(--signal)"); return; }
    } else {
    try { localStorage.setItem(TPL_KEY, btoa(s)); localStorage.setItem(TPL_KEY + "-meta", JSON.stringify(meta)); tplMetaMem = null; }
    catch (e) { tplMetaMem = meta; }
    }''')
rep('''function clearTemplate() { tplCache = null;''', '''async function clearTemplate() {
  if (SRV) {
    try { const r = await srvApi("template_delete", { scope: SRV.tpl && SRV.tpl.scope === "company" ? "company" : "user" }); SRV.tpl = r.tpl; SRV.tplData = r.data; tplCache = null; }
    catch (e) { const st = document.getElementById("tplSt"); if (st) { st.style.color = "var(--signal)"; st.textContent = "Could not remove: " + e.message; } return; }
    render(); return;
  }
  tplCache = null;''')
rep('''${tplInfo().sheets.length} tabs. <span class="svc">Save for Google Sheets fills a copy of it.</span></span>`''',
    '''${tplInfo().sheets.length} tabs${SRV ? (tplInfo().scope === "company" ? " · company default" : " · your own") : ""}. <span class="svc">Save for Google Sheets fills a copy of it.</span></span>`''')
rep('''${tplInfo() ? ' <button class="btn small ghost" type="button" id="tplClear">Remove</button>' : ""}''',
    '''${tplInfo() && (!SRV || tplInfo().scope !== "company" || SRV.user.admin) ? ' <button class="btn small ghost" type="button" id="tplClear">Remove</button>' : ""}${SRV && SRV.user.admin ? '<label class="svc tplco"><input type="checkbox" id="tplCompany"> Upload as company default for everyone</label>' : ""}''')
rep('''Kept in this browser only.</p>''', '''${SRV ? "Saved with this project." : "Kept in this browser only."}</p>''')

# 4. Load example / Start blank replace a saved project: confirm first
rep('''else if (b.id === "btnExample" || b.id === "btnClear") {''',
    '''else if (b.id === "btnExample" || b.id === "btnClear") { if (SRV && !confirm(b.id === "btnExample" ? "Replace everything in this project with the worked example?" : "Clear every input in this project and start blank?")) return;''')

(out / 'builder.css').write_text(css)
(out / 'builder.js').write_text('/* Solution Summary Builder — generated from solution-builder.html by build_php.py */\n' + js)
print('css', len(css), 'js', len(js))

# GitHub Pages: one static page (no PHP, no database; saves in the browser)
body = re.search(r'(<div class="wrap">.*?</div>\n</div>)\n', src, re.S).group(1)
cdn = "\n".join(re.findall(r'<script src="https://[^"]+"></script>', src))
page = f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Solution Summary Builder</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
{css}</style>
</head>
<body>
{body}
{cdn}
<script>
{js}</script>
</body>
</html>
'''
docs = here.parent / 'docs'
docs.mkdir(exist_ok=True)
(docs / 'index.html').write_text(page)
(docs / '.nojekyll').write_text('')
print('docs/index.html', len(page))

#!/usr/bin/env python3
"""Measured coverage of docs/SPEC.md by PROMPT.md (and consistency of the whole Claude Code kit).

Independent of tools/build_register.py (it re-reads the spec with its own parser and imports nothing
from the generator). Positive checks must all pass; then every negative test (a deliberate defect in a
temporary copy of the kit) must be detected. Writes docs/COVERAGE_REPORT.md.

Usage: python3 tools/coverage_check.py [kit_root] [--no-negative]
Exit code 0 only if all positive checks pass and all negative tests are detected.
"""
import json, random, re, shutil, sys, tempfile, pathlib
from collections import Counter

ARGS = [a for a in sys.argv[1:] if not a.startswith("--")]
ROOT = pathlib.Path(ARGS[0] if ARGS else pathlib.Path(__file__).resolve().parent.parent).resolve()
RUN_NEGATIVE = "--no-negative" not in sys.argv

REG_RE = re.compile(r"^- (REQ-(\d{4})) \[§([^|\]]+)\|([A-Z]+)\|([A-Z0-9]+)\|L(\d+)\|([★⌨]*)\] (.*)$")
NUM_RE = re.compile(r"[0-9٠-٩]+(?:[.,][0-9٠-٩]+)*")
TICK_RE = re.compile(r"`([^`]+)`")
ENTITY_RE = re.compile(r"\b[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+\b")
URL_RE = re.compile(r"https?://[^\s)>\]]+")
MAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.]+")
CITE_RE = re.compile(r"\((REQ-\d{4}(?:\s*[،,]\s*REQ-\d{4})*)\s*·\s*§([^\s)]+)\)")
ALLOWED_PHASES = {"META", "CONTEXT", "MVP", "MIXED", "P2", "P3", "FOUNDATION", "PLAN", "OWNER",
                  "DECISIONS", "BINDING", "SOURCES"}
BUILD_PHASES = {"MVP", "MIXED", "P2", "P3", "FOUNDATION"}


# ---------------------------------------------------------------- spec reading (independent parser)
def spec_model(spec_text):
    """Return (headings, content) where headings=[(line,key,title)], content=[(line,text,key)]."""
    headings, content = [], []
    fence = False
    top = sub = cur = None
    for idx, raw in enumerate(spec_text.splitlines(), 1):
        t = raw.strip()
        if t[:3] == "```":
            fence = not fence
            continue
        if fence:
            if t:
                content.append((idx, t, cur))
            continue
        h = re.match(r"^(#+)\s+(.+)$", t)
        if h:
            lvl, title = len(h.group(1)), h.group(2).strip()
            if lvl == 1:
                key = "0"
            elif lvl == 2:
                key = re.match(r"^(\d+)\.", title).group(1); top = key
            elif lvl == 3:
                m = re.match(r"^(\d+\.\d+)\s", title)
                if m:
                    key = m.group(1)
                elif title.startswith("المرحلة"):
                    key = f"{top}.P" + re.search(r"\d", title).group(0)
                else:
                    key = f"{top}.R"
                sub = key
            else:
                key = sub + title.split(")")[0]
            cur = key
            headings.append((idx, key, title))
            continue
        if not t or t == "---" or t == ">" or set(t) <= set("|-: "):
            continue
        content.append((idx, t, cur))
    return headings, content


def kit_model(root):
    prompt = (root / "PROMPT.md").read_text(encoding="utf-8")
    a, rest = prompt.split("<!-- GENERATED:BEGIN -->", 1)
    gen, _ = rest.split("<!-- GENERATED:END -->", 1)
    b, c = gen.split("## الجزء ج", 1)
    reg, bad = [], []
    for line in c.splitlines():
        if line.startswith("- REQ-"):
            m = REG_RE.match(line)
            (reg.append(m) if m else bad.append(line))
    regs = [dict(id=m.group(1), n=int(m.group(2)), sec=m.group(3), kind=m.group(4), phase=m.group(5),
                 line=int(m.group(6)), flags=m.group(7), text=m.group(8)) for m in reg]
    secrows = {}
    for line in b.splitlines():
        m = re.match(r"^\| §(\S+) \| (.*) \| (\S+) \| (\S+) \| (\d+) \|$", line)
        if m:
            secrows[m.group(1)] = dict(title=m.group(2), phase=m.group(3), rng=m.group(4), count=int(m.group(5)))
    return dict(a=a, b=b, c=c, regs=regs, bad=bad, secrows=secrows)


def multiset(texts, rx):
    cnt = Counter()
    for t in texts:
        cnt.update(rx.findall(t))
    return cnt


# ---------------------------------------------------------------- positive checks
def run_checks(root):
    """Return list of (check_id, title, passed, detail)."""
    R = []
    def check(cid, title, ok, detail):
        R.append((cid, title, bool(ok), detail))

    spec = (root / "docs/SPEC.md").read_text(encoding="utf-8")
    heads, content = spec_model(spec)
    try:
        K = kit_model(root)
    except Exception as e:  # malformed prompt
        check("M0", "PROMPT.md structure", False, f"cannot parse: {e}")
        return R
    regs = K["regs"]
    by_line = {}
    for r in regs:
        by_line.setdefault(r["line"], []).append(r)

    # M1 — every spec content line appears exactly once, verbatim, at the right line, in order
    missing = [(l, t) for l, t, _ in content if l not in by_line]
    dup = [l for l, v in by_line.items() if len(v) > 1]
    spec_lines = {l: t for l, t, _ in content}
    extra = [r["id"] for r in regs if r["line"] not in spec_lines]
    textdiff = [r["id"] for r in regs if r["line"] in spec_lines and r["text"] != spec_lines[r["line"]]]
    seq_ok = [r["n"] for r in regs] == list(range(1, len(regs) + 1)) and \
        [r["line"] for r in regs] == sorted(r["line"] for r in regs)
    check("M1", "كل سطر محتوى في الوثيقة له متطلب واحد بنصه الحرفي",
          not missing and not dup and not extra and not textdiff and not K["bad"] and seq_ok and len(regs) == len(content),
          f"أسطر الوثيقة={len(content)} · المتطلبات={len(regs)} · مفقود={len(missing)} · مكرر={len(dup)} · "
          f"زائد={len(extra)} · نص مختلف={len(textdiff)} · أسطر سجل مشوّهة={len(K['bad'])} · الترقيم متسلسل={seq_ok}"
          + (f" · أمثلة: {missing[:2] or extra[:2] or textdiff[:2] or K['bad'][:1]}" if (missing or extra or textdiff or K['bad']) else ""))

    # M2 — character volume
    cs, cr = sum(len(t) for _, t, _ in content), sum(len(r["text"]) for r in regs)
    check("M2", "حجم النص (عدد الأحرف) متطابق", cs == cr, f"الوثيقة={cs} · السجل={cr}")

    # M3 — numbers (multiset)
    ns, nr = multiset([t for _, t, _ in content], NUM_RE), multiset([r["text"] for r in regs], NUM_RE)
    check("M3", "كل رقم في الوثيقة موجود في السجل بالعدد نفسه", ns == nr,
          f"أرقام الوثيقة={sum(ns.values())} (مختلفة={len(ns)}) · في السجل={sum(nr.values())} · "
          f"ناقص={dict(ns - nr) or '—'} · زائد={dict(nr - ns) or '—'}")

    # M4 — identifiers, entities, URLs, e-mails
    sub = []
    for name, rx in (("backtick", TICK_RE), ("entity", ENTITY_RE), ("url", URL_RE), ("email", MAIL_RE)):
        a, b = multiset([t for _, t, _ in content], rx), multiset([r["text"] for r in regs], rx)
        sub.append((name, sum(a.values()), a == b, dict(a - b)))
    check("M4", "المعرّفات والكيانات والروابط والبريد كلها موجودة",
          all(x[2] for x in sub), " · ".join(f"{n}={c}{'' if ok else ' ناقص:' + str(d)}" for n, c, ok, d in sub))

    # M5 — sections: every heading in Part B with same title; each REQ tagged with its true section;
    #      ranges and counts in Part B are exact
    heading_keys = [k for _, k, _ in heads]
    miss_sec = [k for k in heading_keys if k not in K["secrows"]]
    title_bad = [k for _, k, t in heads if k in K["secrows"] and K["secrows"][k]["title"] != t.replace("|", "/")]
    extra_sec = [k for k in K["secrows"] if k not in heading_keys]
    true_sec = {l: k for l, _, k in content}
    wrong_tag = [r["id"] for r in regs if true_sec.get(r["line"]) != r["sec"]]
    rng_bad = []
    for k in heading_keys:
        lst = [r for r in regs if r["sec"] == k]
        row = K["secrows"].get(k)
        if not row:
            continue
        exp = f"{lst[0]['id']}…{lst[-1]['id']}" if lst else "—"
        if row["rng"] != exp or row["count"] != len(lst):
            rng_bad.append(k)
    check("M5", "كل الأقسام في الخريطة، وكل متطلب في قسمه الصحيح، والنطاقات دقيقة",
          not miss_sec and not title_bad and not extra_sec and not wrong_tag and not rng_bad,
          f"عناوين الوثيقة={len(heading_keys)} · صفوف الخريطة={len(K['secrows'])} · ناقص={miss_sec or '—'} · "
          f"عنوان مختلف={title_bad or '—'} · زائد={extra_sec or '—'} · وسم قسم خاطئ={len(wrong_tag)} · نطاق خاطئ={rng_bad or '—'}")

    # M6 — phases valid, consistent; every BUILD section with items is scheduled in Part A plan (أ.7)
    bad_phase = [k for k, v in K["secrows"].items() if v["phase"] not in ALLOWED_PHASES]
    incons = [r["id"] for r in regs if K["secrows"].get(r["sec"], {}).get("phase") != r["phase"]]
    plan = K["a"].split("### أ.7", 1)[1].split("### أ.8", 1)[0] if "### أ.7" in K["a"] else ""
    planned = set(re.findall(r"§(\S+?)(?=[\s|])", plan))
    need = sorted({r["sec"] for r in regs if r["phase"] in BUILD_PHASES})
    unplanned = [k for k in need if k not in planned]
    check("M6", "كل قسم بناء مجدول في خطة التنفيذ (أ.7) ومرحلته صالحة",
          not bad_phase and not incons and not unplanned and plan,
          f"أقسام بناء={len(need)} · مجدولة={len([k for k in need if k in planned])} · غير مجدولة={unplanned or '—'} · "
          f"مرحلة غير صالحة={bad_phase or '—'} · تعارض مرحلة={len(incons)}")

    # M7 — every decision in §14 has a row D<n> in Part A citing the REQ of that very line
    dec_lines = [(l, t) for l, t, k in content if k == "14" and re.match(r"^\d+\.\s", t)]
    dec_bad = []
    for l, t in dec_lines:
        n = re.match(r"^(\d+)\.", t).group(1)
        rid = by_line[l][0]["id"] if l in by_line else None
        row = re.search(rf"^\| D{n} \|.*$", K["a"], re.M)
        if not row or not rid or f"({rid} · §14)" not in row.group(0):
            dec_bad.append(n)
    check("M7", "كل قرار في §14 له صف وسلوك في جدول القرارات (أ.8) مستشهداً بسطره",
          dec_lines and not dec_bad, f"قرارات الوثيقة={len(dec_lines)} · ناقص/استشهاد خاطئ={dec_bad or '—'}")

    # M8 — citation grounding: no invented REQ or section anywhere in Part A
    ids = {r["id"]: r for r in regs}
    secs = set(heading_keys)
    cites = CITE_RE.findall(K["a"])
    wrong_cite = []
    for group, sec in cites:
        for rid in re.findall(r"REQ-\d{4}", group):
            if rid not in ids or ids[rid]["sec"] != sec:
                wrong_cite.append(f"{rid}·§{sec}")
    bare = set(re.findall(r"REQ-\d{4}", K["a"]))
    unknown_ids = sorted(x for x in bare if x not in ids)
    sec_refs = set(re.findall(r"§(\d+(?:\.(?:\d+|P\d|R))?(?:أ|ب|ج|د|هـ|و|ز|ح)?)", K["a"]))
    unknown_secs = sorted(x for x in sec_refs if x not in secs)
    check("M8", "كل استشهاد في الجزء أ يشير إلى متطلب وقسم موجودين ومتطابقين (لا شيء مخترع)",
          cites and not wrong_cite and not unknown_ids and not unknown_secs,
          f"استشهادات={len(cites)} · أرقام متطلبات مذكورة={len(bare)} · خاطئة={wrong_cite or '—'} · "
          f"غير موجودة={unknown_ids or '—'} · أقسام غير موجودة={unknown_secs or '—'}")

    # M9 — framework versions in Part A equal the §11.6 table
    vers = []
    for _, t, k in content:
        m = re.match(r"^\| ([A-Za-z.]+(?: [A-Za-z]+)?) \| \*\*([0-9.x]+)(?: LTS)?\*\*", t)
        if k == "11.6" and m:
            vers.append((m.group(1), m.group(2)))
    a5 = K["a"].split("### أ.5", 1)[1].split("### أ.6", 1)[0] if "### أ.5" in K["a"] else ""
    vmiss = [f"{c} {v}" for c, v in vers if f"{c} {v}" not in a5]
    check("M9", "إصدارات الإطار في التلقينة = جدول §11.6", len(vers) >= 8 and not vmiss,
          f"إصدارات الجدول={vers} · ناقص={vmiss or '—'}")

    # M10 — environment gate matches §11.6/§11.7
    pf = (root / "scripts/preflight.php").read_text(encoding="utf-8")
    guard = (root / ".claude/hooks/guard-preflight.php").read_text(encoding="utf-8")
    ext_line = next(t for _, t, k in content if k == "11.6" and "امتدادات PHP" in t)
    exts = TICK_RE.findall(ext_line)
    pf_ext_arr = re.search(r"\$EXT = \[([^\]]*)\]", pf)
    pf_exts = set(re.findall(r"'([a-z_]+)'", pf_ext_arr.group(1))) if pf_ext_arr else set()
    if "extension_loaded('gd') || extension_loaded('imagick')" in pf:
        pf_exts |= {"gd", "imagick"}
    if "extension_loaded('redis')" in pf:
        pf_exts.add("redis")
    ext_miss = [e for e in exts if e not in pf_exts]
    markers = {"git": "git --version", "composer2": "'2.0'", "node20": "'20.0'", "npm": "npm --version",
               "php>=8.3": "'8.3'", "php<8.6": "'8.6'", "hijri-1448": "'1448'", "hijri-date": "2026-11-20",
               "umalqura": "islamic-umalqura", "pg16": "160000", "redis7": "'7.0'", "s3": "object-storage",
               "utc": "APP_TIMEZONE", "utf8": "UTF-8", ".env": ".env", "packagist": "repo.packagist.org",
               "npm-registry": "registry.npmjs.org", "disk5gb": "5 * 1024 ** 3", "repo": "git rev-parse",
               "report": "docs/PREFLIGHT_REPORT.md", "pass-marker": ".preflight/PASS", "remove-on-fail": "@unlink($MARK)"}
    mk_miss = [k for k, v in markers.items() if v not in pf]
    try:
        st = json.loads((root / ".claude/settings.json").read_text(encoding="utf-8"))
        ss = st["hooks"]["SessionStart"][0]; pt = st["hooks"]["PreToolUse"][0]
        hooks_ok = all(x in ss["matcher"] for x in ("startup", "resume", "clear", "compact")) and \
            ss["hooks"][0]["command"] == "php" and any("preflight.php" in a for a in ss["hooks"][0]["args"]) and \
            all(x in pt["matcher"].split("|") for x in ("Write", "Edit", "MultiEdit", "NotebookEdit", "Bash", "PowerShell")) and \
            pt["hooks"][0]["command"] == "php" and any("guard-preflight.php" in a for a in pt["hooks"][0]["args"]) and \
            pt["hooks"][0].get("onFailure") == "block"
    except Exception:
        hooks_ok = False
    w_line = next(t for _, t, k in content if k == "11.7" and "قبل الكتابة" in t)
    allowed_spec = [x for x in TICK_RE.findall(w_line) if x not in ("PreToolUse", "Write", "Edit", "MultiEdit", "NotebookEdit")]
    am = re.search(r"const ALLOWED = \[([^\]]*)\]", guard)
    allowed_guard = re.findall(r"'([^']+)'", am.group(1)) if am else []
    allow_diff = sorted(set(allowed_spec) ^ set(allowed_guard))
    b_line = next(t for _, t, k in content if k == "11.7" and "قبل أوامر البناء" in t)
    build_cmds = [x for x in TICK_RE.findall(b_line) if x not in ("PreToolUse", "Bash", "PowerShell")]
    rx_m = re.search(r"const BUILD_CMD = '([^']+)'", guard)
    bc_miss = []
    if rx_m:
        rx = re.compile(rx_m.group(1).strip("/").rsplit("/", 1)[0] if rx_m.group(1).endswith("/i") else rx_m.group(1).strip("/"), re.I)
        for cmd in build_cmds:
            head, _, subs = cmd.partition(" ")
            variants = [head + " " + v for v in subs.split("/")] if subs else [head + " x"]
            for v in variants:
                if not rx.search(v + " "):
                    bc_miss.append(v)
    else:
        bc_miss = ["BUILD_CMD missing"]
    gate_ok = "exit(2)" in guard and "MAX_AGE_SECONDS = 86400" in guard
    check("M10", "بوابة البيئة تطابق §11.6 و§11.7 (الامتدادات، الفحوص، الخطافات، الملفات المسموحة، أوامر البناء، 24 ساعة، رمز 2)",
          not ext_miss and not mk_miss and hooks_ok and not allow_diff and not bc_miss and gate_ok,
          f"امتدادات الوثيقة={len(exts)} ناقص={ext_miss or '—'} · فحوص ناقصة={mk_miss or '—'} · الخطافات={hooks_ok} · "
          f"فرق الملفات المسموحة={allow_diff or '—'} · أوامر بناء غير ممنوعة={bc_miss or '—'} · 24س/رمز2={gate_ok}")

    # M11 — traceability: one row per REQ with the same section and kind
    tr_rows = re.findall(r"^\| (REQ-\d{4}) \| §(\S+) \| ([A-Z]+) \| ([A-Z0-9]+) \|", (root / "docs/TRACEABILITY.md").read_text(encoding="utf-8"), re.M)
    tr_ok = [(i, s, k, p) for i, s, k, p in tr_rows] == [(r["id"], r["sec"], r["kind"], r["phase"]) for r in regs]
    check("M11", "سجل التتبع فيه صف لكل متطلب بالقسم والنوع والمرحلة نفسها", tr_ok,
          f"صفوف التتبع={len(tr_rows)} · المتطلبات={len(regs)}")

    # M12 — design tokens equal the approved reference file
    try:
        src = (root / "docs/design/Dashboard.reference.dc.html").read_text(encoding="utf-8")
        tok = json.loads((root / "docs/design/tokens.json").read_text(encoding="utf-8"))
        blocks = re.findall(r"(light|dark):\s*\{([^}]*)\}", src)
        ref = [dict(re.findall(r"(\w+):\s*'(#[0-9A-Fa-f]{6})'", b)) for _, b in blocks]
        refs = [{k: v for k, v in re.findall(r"(\w+):\s*\['(#[0-9A-Fa-f]{6})'", b)} for _, b in blocks[6:8]]
        got = []
        for th in ("clay", "ink", "plum"):
            got += [tok["themes"][th]["light"], tok["themes"][th]["dark"]]
        st_ok = all(tok["status"][m][k][0] == refs[i][k] for i, m in enumerate(("light", "dark")) for k in refs[i])
        tok_ok = got == ref[:6] and st_ok and len(tok["status"]["light"]) == 5 and tok["platform_default_theme"] is None
        ncol = sum(len(x) for x in ref[:6])
    except Exception as e:
        tok_ok, ncol = False, f"error {e}"
    check("M12", "قيم السمات الثلاث وألوان الحالة = ملف الهوية المعتمد، والسمة الافتراضية غير مثبّتة (القرار 11)",
          tok_ok, f"قيم لونية مقارنة={ncol}")

    # M13 — kit files and .env keys
    files = ["CLAUDE.md", "PROMPT.md", "docs/SPEC.md", "docs/TRACEABILITY.md", "docs/QUESTIONS.md", "docs/PROGRESS.md",
             "scripts/preflight.php", ".claude/settings.json", ".claude/hooks/guard-preflight.php",
             ".env.example", "tools/build_register.py", "tools/trace_check.php", "docs/design/tokens.json"]
    fmiss = [f for f in files if not (root / f).is_file()]
    need_keys = set(re.findall(r"'([A-Z_]+)'", re.search(r"\$NEED = \[([^\]]*)\]", pf).group(1)))
    env_keys = set(re.findall(r"^([A-Z_]+)=", (root / ".env.example").read_text(encoding="utf-8"), re.M))
    check("M13", "ملفات الحزمة موجودة، و.env.example يحوي كل مفاتيح الفحص",
          not fmiss and need_keys <= env_keys, f"ملفات ناقصة={fmiss or '—'} · مفاتيح ناقصة={sorted(need_keys - env_keys) or '—'}")

    # M14 — the PreToolUse guard actually blocks/allows (executed, not read)
    import subprocess, os, time
    cases = [("Write", {"file_path": "app/Models/Lease.php"}, False, 2), ("Write", {"file_path": "docs/QUESTIONS.md"}, False, 0),
             ("Edit", {"file_path": ".env"}, False, 0), ("Bash", {"command": "composer create-project laravel/laravel ."}, False, 2),
             ("Bash", {"command": "php artisan migrate"}, False, 2), ("Bash", {"command": "git status"}, False, 0), ("PowerShell", {"command": "composer require foo/bar"}, False, 2),
             ("Write", {"file_path": "APP\\Models\\Lease.php"}, False, 2), ("Write", {"file_path": "DOCS\\notes.md"}, False, 0),
             ("Write", {"file_path": "app/Models/Lease.php"}, "fresh", 0), ("Bash", {"command": "npm install"}, "fresh", 0),
             ("Write", {"file_path": "app/Models/Lease.php"}, "stale", 2)]
    bad = []
    with tempfile.TemporaryDirectory() as td:
        proj = pathlib.Path(td)
        (proj / ".claude/hooks").mkdir(parents=True)
        shutil.copy(root / ".claude/hooks/guard-preflight.php", proj / ".claude/hooks/guard-preflight.php")
        for tool, ti, marker, want in cases:
            mk = proj / ".preflight/PASS"
            if marker:
                mk.parent.mkdir(exist_ok=True); mk.write_text("t")
                if marker == "stale":
                    old_t = time.time() - 2 * 86400; os.utime(mk, (old_t, old_t))
            elif mk.exists():
                mk.unlink()
            if "file_path" in ti:
                ti = {"file_path": str(proj / ti["file_path"])}
            p = subprocess.run(["php", str(proj / ".claude/hooks/guard-preflight.php")], input=json.dumps({"tool_name": tool, "tool_input": ti}),
                               text=True, capture_output=True, env={**os.environ, "CLAUDE_PROJECT_DIR": str(proj)})
            if p.returncode != want:
                bad.append(f"{tool}:{list(ti.values())[0][-30:]}:{marker}→{p.returncode}≠{want}")
    check("M14", "خطاف المنع يعمل فعلاً عند التشغيل (يمنع الكود وأوامر البناء قبل النجاح، يسمح بملفات التأسيس، يرفض علامة عمرها يومان)",
          not bad, f"حالات={len(cases)} · مخالفة={bad or '—'}")
    return R


# ---------------------------------------------------------------- negative tests
def mutate(root, rel, fn):
    p = root / rel
    s = p.read_text(encoding="utf-8")
    s2 = fn(s)
    if s2 == s:
        raise RuntimeError(f"mutation had no effect on {rel}")
    p.write_text(s2, encoding="utf-8")


def reg_line(s, rid):
    return next(l for l in s.splitlines() if l.startswith(f"- {rid} ["))


def negative_tests(root):
    rnd = random.Random(20261120)
    K = kit_model(root)
    regs = K["regs"]
    named = [
        ("N01", "حذف متطلب من السجل (REQ-0198 من §5.7)", "PROMPT.md", lambda s: s.replace(reg_line(s, "REQ-0198") + "\n", ""), "M1"),
        ("N02", "تغيير رقم داخل متطلب (60 → 61 في REQ-0191)", "PROMPT.md", lambda s: s.replace(reg_line(s, "REQ-0191"), reg_line(s, "REQ-0191").replace("60", "61", 1)), "M3"),
        ("N03", "حذف كلمة «لا» من متطلب منع (REQ-0168)", "PROMPT.md", lambda s: s.replace(reg_line(s, "REQ-0168"), reg_line(s, "REQ-0168").replace("لا ", "", 1)), "M1"),
        ("N04", "إضافة متطلب مخترع إلى السجل", "PROMPT.md", lambda s: s.replace(reg_line(s, "REQ-0700"), reg_line(s, "REQ-0700") + "\n- REQ-0701 [§16|SOURCE|SOURCES|L9999|] متطلب مخترع"), "M1"),
        ("N05", "حذف قسم من خريطة الأقسام (§6.6)", "PROMPT.md", lambda s: re.sub(r"^\| §6\.6 \|.*\n", "", s, flags=re.M), "M5"),
        ("N06", "حذف القرار 13 من جدول القرارات", "PROMPT.md", lambda s: re.sub(r"^\| D13 \|.*\n", "", s, flags=re.M), "M7"),
        ("N07", "استشهاد بقسم خاطئ (REQ-0185 · §5.5)", "PROMPT.md", lambda s: s.replace("(REQ-0185 · §5.4)", "(REQ-0185 · §5.5)"), "M8"),
        ("N08", "استشهاد بمتطلب غير موجود (REQ-0999)", "PROMPT.md", lambda s: s.replace("(REQ-0185 · §5.4)", "(REQ-0999 · §5.4)"), "M8"),
        ("N09", "إسقاط قسم من خطة التنفيذ (§6.4)", "PROMPT.md", lambda s: s.replace("§6.3 §6.4 §6.6", "§6.3 §6.6"), "M6"),
        ("N10", "تغيير إصدار Laravel في التلقينة إلى 12.x", "PROMPT.md", lambda s: s.replace("Laravel 13.x", "Laravel 12.x", 1), "M9"),
        ("N11", "حذف امتداد bcmath من الفحص المسبق", "scripts/preflight.php", lambda s: s.replace("'bcmath', ", ""), "M10"),
        ("N12", "تمديد صلاحية علامة النجاح إلى 48 ساعة", ".claude/hooks/guard-preflight.php", lambda s: s.replace("MAX_AGE_SECONDS = 86400", "MAX_AGE_SECONDS = 172800"), "M10"),
        ("N13", "إزالة Bash من خطاف PreToolUse", ".claude/settings.json", lambda s: s.replace("|Bash", ""), "M10"),
        ("N14", "حذف صف من سجل التتبع", "docs/TRACEABILITY.md", lambda s: re.sub(r"^\| REQ-0300 \|.*\n", "", s, flags=re.M), "M11"),
        ("N15", "تغيير لون في السمات", "docs/design/tokens.json", lambda s: s.replace("#9A3F22", "#9A3F23", 1), "M12"),
        ("N16", "تعديل الوثيقة دون إعادة توليد السجل", "docs/SPEC.md", lambda s: s.replace("- لا حذف فعلي لأي سجل مالي أو عقد — أرشفة فقط.", "- لا حذف فعلي لأي سجل مالي أو عقد — أرشفة فقط.\n- بند جديد لم يُسجَّل."), "M1"),
        ("N17", "تغيير فحص التقويم الهجري إلى 1447", "scripts/preflight.php", lambda s: s.replace("'1448'", "'1447'"), "M10"),
        ("N19", "تعطيل المنع في الخطاف (exit 2 → exit 0)", ".claude/hooks/guard-preflight.php", lambda s: s.replace("    exit(2);\n}", "    exit(0);\n}"), "M14"),
        ("N20", "حذف onFailure: block من الخطاف", ".claude/settings.json", lambda s: s.replace(',\n            "onFailure": "block"', ''), "M10"),
        ("N21", "حذف PowerShell من أدوات المنع", ".claude/settings.json", lambda s: s.replace("|PowerShell", ""), "M10"),
        ("N18", "تغيير عنوان قسم في الخريطة", "PROMPT.md", lambda s: s.replace("| §4.7 | 4.7 قواعد بيانات صارمة من اليوم الأول |", "| §4.7 | 4.7 قواعد بيانات |"), "M5"),
    ]
    results = []

    def run_one(rel, fn, expect):
        with tempfile.TemporaryDirectory() as td:
            tmp = pathlib.Path(td) / "kit"
            shutil.copytree(root, tmp, ignore=shutil.ignore_patterns(".git", "node_modules", "vendor"))
            mutate(tmp, rel, fn)
            res = run_checks(tmp)
            failed = [cid for cid, _, ok, _ in res if not ok]
            return failed, (expect in failed if expect else bool(failed))

    for nid, title, rel, fn, expect in named:
        failed, ok = run_one(rel, fn, expect)
        results.append((nid, title, expect, failed, ok))

    # Sweeps: random deletions and random digit changes across the whole register
    sample = rnd.sample(regs, 40)
    det = 0
    for r in sample:
        failed, ok = run_one("PROMPT.md", lambda s, r=r: s.replace(reg_line(s, r["id"]) + "\n", ""), "M1")
        det += ok
    results.append(("S1", "مسح عشوائي: حذف 40 متطلباً عشوائياً كلٌّ على حدة", "M1", [f"{det}/40 اكتُشف"], det == 40))
    numeric = [r for r in regs if re.search(r"[0-9]", r["text"])]
    sample = rnd.sample(numeric, 40)
    det = 0
    for r in sample:
        def f(s, r=r):
            line = reg_line(s, r["id"])
            i = next(i for i, ch in enumerate(line.split("] ", 1)[1]) if ch.isdigit()) + len(line.split("] ", 1)[0]) + 2
            new = line[:i] + str((int(line[i]) + 1) % 10) + line[i + 1:]
            return s.replace(line, new)
        failed, ok = run_one("PROMPT.md", f, "M3")
        det += ok
    results.append(("S2", "مسح عشوائي: تغيير رقم واحد في 40 متطلباً عشوائياً", "M3", [f"{det}/40 اكتُشف"], det == 40))
    return results


# ---------------------------------------------------------------- report
def main():
    pos = run_checks(ROOT)
    pos_ok = all(ok for _, _, ok, _ in pos)
    neg = negative_tests(ROOT) if (RUN_NEGATIVE and pos_ok) else []
    neg_ok = all(x[4] for x in neg) if neg else not RUN_NEGATIVE
    K = kit_model(ROOT)
    kinds = Counter(r["kind"] for r in K["regs"])
    lines = ["# تقرير قياس تغطية التلقينة للوثيقة", "",
             "مولّد آلياً بـ `python3 tools/coverage_check.py`. القياس آلي: يقرأ `docs/SPEC.md` بمحلّل مستقل عن مولّد السجل ويقارن.", "",
             f"- المتطلبات في السجل: **{len(K['regs'])}** — " + " · ".join(f"{k}: {v}" for k, v in sorted(kinds.items())),
             f"- النتيجة: **{'ناجح' if pos_ok and neg_ok else 'فاشل'}** — فحوص إيجابية {sum(ok for _,_,ok,_ in pos)}/{len(pos)}"
             + (f" · اختبارات سلبية مكتشفة {sum(x[4] for x in neg)}/{len(neg)}" if neg else ""), "",
             "## الفحوص الإيجابية", "", "| الفحص | ما يقيسه | النتيجة | التفاصيل |", "|---|---|---|---|"]
    for cid, title, ok, detail in pos:
        lines.append(f"| {cid} | {title} | {'✅' if ok else '❌'} | {detail.replace('|', '/')} |")
    if neg:
        lines += ["", "## الاختبارات السلبية (عيب متعمّد في نسخة مؤقتة — يجب أن يُكتشف)", "",
                  "| الاختبار | العيب المزروع | الفحص المتوقع | الفحوص التي فشلت | اكتُشف؟ |", "|---|---|---|---|---|"]
        for nid, title, exp, failed, ok in neg:
            lines.append(f"| {nid} | {title} | {exp} | {', '.join(failed) or '—'} | {'✅' if ok else '❌'} |")
    (ROOT / "docs/COVERAGE_REPORT.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines))
    sys.exit(0 if pos_ok and neg_ok else 1)


if __name__ == "__main__":
    main()

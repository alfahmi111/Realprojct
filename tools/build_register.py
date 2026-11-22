#!/usr/bin/env python3
"""Build the numbered requirements register from docs/SPEC.md.

Every content line of the spec becomes exactly one requirement (REQ-####), verbatim,
with its section, kind, phase and source line number. Output:
  - PROMPT.md   : the block between GENERATED markers is replaced (Part B section map + Part C register)
  - docs/TRACEABILITY.md : one row per REQ

Deterministic: same SPEC.md -> same IDs. Re-run after any spec change, then run tools/coverage_check.py.
Usage: python3 tools/build_register.py [kit_root]
"""
import re, sys, pathlib

ROOT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else pathlib.Path(__file__).resolve().parent.parent)
SPEC = ROOT / "docs/SPEC.md"
PROMPT = ROOT / "PROMPT.md"
TRACE = ROOT / "docs/TRACEABILITY.md"
BEGIN, END = "<!-- GENERATED:BEGIN -->", "<!-- GENERATED:END -->"

# Phase of each section, taken from spec section 12 (roadmap). MIXED = the section holds items of
# several phases; the exact phase of each item is the phase list in section 12 that names it
# (an item not named in the phase-2 or phase-3 lists is MVP).
PHASE = {
    "0": "META",
    "1": "CONTEXT", "1.1": "CONTEXT",
    "2": "CONTEXT", "2.1": "CONTEXT", "2.2": "CONTEXT", "2.3": "CONTEXT", "2.4": "MVP",
    "3": "MVP", "3.1": "MVP",
    "4": "MVP", "4.1": "MVP", "4.2": "MVP", "4.3": "MVP", "4.4": "MVP", "4.5": "MVP", "4.6": "MVP", "4.7": "MVP",
    "5": "MVP", "5.1": "MIXED", "5.2": "MIXED", "5.3": "MVP", "5.4": "MVP", "5.5": "MVP",
    "5.6": "MIXED", "5.7": "MVP", "5.8": "MIXED",
    "6": "MVP", "6.1": "MVP", "6.2": "MIXED", "6.3": "MVP", "6.4": "MIXED", "6.5": "P2", "6.6": "MIXED", "6.7": "P2",
    "7": "MVP", "7.1": "MIXED", "7.2": "MIXED", "7.3": "MIXED", "7.4": "MIXED", "7.5": "MVP",
    "7.6": "MVP", "7.6أ": "MVP", "7.6ب": "MVP", "7.6ج": "MVP", "7.6د": "MVP", "7.6هـ": "MVP",
    "7.6و": "MVP", "7.6ز": "MVP", "7.6ح": "MVP",
    "8": "MVP", "8.1": "MVP", "8.2": "MIXED", "8.3": "MIXED", "8.4": "MVP", "8.5": "MIXED",
    "9": "MVP", "9.1": "MVP", "9.2": "MIXED", "9.3": "MVP", "9.4": "MVP", "9.5": "MIXED", "9.6": "MVP",
    "10": "MIXED", "10.1": "MIXED", "10.2": "MIXED",
    "11": "FOUNDATION", "11.1": "FOUNDATION", "11.2": "FOUNDATION", "11.3": "FOUNDATION", "11.4": "FOUNDATION",
    "11.5": "FOUNDATION", "11.6": "FOUNDATION", "11.7": "FOUNDATION",
    "12": "PLAN", "12.P0": "OWNER", "12.P1": "PLAN", "12.P2": "PLAN", "12.P3": "PLAN", "12.R": "CONTEXT",
    "13": "MIXED",
    "14": "DECISIONS", "15": "BINDING", "16": "SOURCES",
}

# Kind of a requirement by phase (what Claude Code must do with it)
KIND = {
    "META": "META", "CONTEXT": "CONTEXT", "SOURCES": "SOURCE", "OWNER": "OWNER", "PLAN": "PLAN",
    "BINDING": "DECIDED", "DECISIONS": "DECISION",
    "MVP": "BUILD", "P2": "BUILD", "P3": "BUILD", "MIXED": "BUILD", "FOUNDATION": "BUILD",
}
STATUS = {"BUILD": "TODO", "CONTEXT": "READ", "SOURCE": "READ", "META": "READ", "OWNER": "OWNER",
          "PLAN": "PLAN", "DECIDED": "BINDING", "DECISION": "SEE-§14"}

NORMATIVE = re.compile(r"(لا ي|لا تُ|لا ت|يمنع|يُمنع|يجب|إلزامي|شرط|ممنوع|دائماً|القاعدة|قاعدة|لا حذف|لا أرقام|يتوقف|يُوقَف|لا يُكتب|غير قابل للتعديل)")
SEP_ROW = re.compile(r"^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$")


def section_key(level, text, ctx):
    """Return a stable section key for a heading."""
    if level == 1:
        return "0"
    m = re.match(r"^(\d+)\.\s", text)
    if level == 2 and m:
        return m.group(1)
    m = re.match(r"^(\d+\.\d+)\s", text)
    if level == 3 and m:
        return m.group(1)
    if level == 3 and ctx["top"] == "12":
        m = re.match(r"^المرحلة\s+(\d)", text)
        if m:
            return "12.P" + m.group(1)
        if text.startswith("العوائق"):
            return "12.R"
    m = re.match(r"^(أ|ب|ج|د|هـ|و|ز|ح)\)", text)
    if level == 4 and m:
        return ctx["sub"] + m.group(1)
    raise SystemExit(f"Unrecognised heading (level {level}): {text}")


def parse(spec_text):
    """Yield sections [(key, title, line_no)] and items [dict]."""
    sections, items = [], []
    ctx = {"top": None, "sub": None, "key": None}
    in_code = False
    for no, raw in enumerate(spec_text.split("\n"), start=1):
        line = raw.rstrip()
        s = line.strip()
        if s.startswith("```"):
            in_code = not in_code
            continue
        if not in_code:
            hm = re.match(r"^(#{1,4})\s+(.*)$", s)
            if hm:
                level, title = len(hm.group(1)), hm.group(2).strip()
                key = section_key(level, title, ctx)
                if level == 2:
                    ctx["top"] = key
                if level == 3:
                    ctx["sub"] = key
                ctx["key"] = key
                sections.append((key, title, no))
                continue
            if s in ("", "---", ">") or SEP_ROW.match(s):
                continue
        else:
            if s == "":
                continue
        key = ctx["key"]
        phase = PHASE[key]
        items.append({
            "line": no, "text": s, "section": key, "phase": phase, "kind": KIND[phase],
            "code": in_code, "norm": bool(NORMATIVE.search(s)),
        })
    if in_code:
        raise SystemExit("Unclosed code fence in SPEC.md")
    return sections, items


def main():
    spec_text = SPEC.read_text(encoding="utf-8")
    sections, items = parse(spec_text)
    missing = [k for k, _, _ in sections if k not in PHASE]
    if missing:
        raise SystemExit(f"Sections without a phase: {missing}")
    for i, it in enumerate(items, start=1):
        it["id"] = f"REQ-{i:04d}"

    by_sec = {}
    for it in items:
        by_sec.setdefault(it["section"], []).append(it)

    out = [BEGIN, "",
           "## الجزء ب — خريطة الأقسام (مولّدة آلياً من docs/SPEC.md — لا تُعدَّل يدوياً)", "",
           f"إجمالي المتطلبات: **{len(items)}** · إجمالي الأقسام: **{len(sections)}** · "
           f"BUILD: **{sum(1 for i in items if i['kind']=='BUILD')}**", "",
           "| القسم | العنوان | المرحلة | المتطلبات | العدد |", "|---|---|---|---|---|"]
    for key, title, _ in sections:
        lst = by_sec.get(key, [])
        rng = f"{lst[0]['id']}…{lst[-1]['id']}" if lst else "—"
        out.append(f"| §{key} | {title.replace('|', '/')} | {PHASE[key]} | {rng} | {len(lst)} |")
    out += ["", "## الجزء ج — سجل المتطلبات المرقّم (كل سطر في docs/SPEC.md بنصه الحرفي)", "",
            "صيغة كل سطر: `REQ-#### [§القسم|النوع|المرحلة|Lرقم_السطر_في_الوثيقة|علامات] النص الحرفي`. "
            "العلامة ★ مؤشر قراءة آلي لعبارة إلزامية أو منع (لا يُغني عن قراءة كل سطر)، و⌨ سطر من كتلة شيفرة/مخطط.", ""]
    for key, title, _ in sections:
        lst = by_sec.get(key, [])
        out.append(f"### §{key} — {title}  ·  المرحلة: {PHASE[key]}")
        out.append("")
        for it in lst:
            flags = ("★" if it["norm"] else "") + ("⌨" if it["code"] else "")
            out.append(f"- {it['id']} [§{it['section']}|{it['kind']}|{it['phase']}|L{it['line']}|{flags}] {it['text']}")
        out.append("")
    out.append(END)
    generated = "\n".join(out)

    prompt = PROMPT.read_text(encoding="utf-8")
    if BEGIN not in prompt or END not in prompt:
        raise SystemExit("PROMPT.md is missing GENERATED markers")
    head, rest = prompt.split(BEGIN, 1)
    _, tail = rest.split(END, 1)
    PROMPT.write_text(head + generated + tail, encoding="utf-8")

    tr = ["# سجل التتبع (Traceability)", "",
          "مولّد آلياً من docs/SPEC.md عبر tools/build_register.py. يحدّث Claude Code الأعمدة الأربعة الأخيرة فقط.",
          "الحالات: TODO → IN-PROGRESS → DONE (بكود واختبار) · READ (سياق) · OWNER (مهمة لصاحب المشروع) · "
          "PLAN (بند خارطة طريق) · BINDING (قرار ملزم) · SEE-§14 (قرار مفتوح/محسوم) · BLOCKED (ينتظر قراراً).", "",
          "| REQ | القسم | النوع | المرحلة | الحالة | الكود | الاختبارات | ملاحظات |", "|---|---|---|---|---|---|---|---|"]
    for it in items:
        tr.append(f"| {it['id']} | §{it['section']} | {it['kind']} | {it['phase']} | {STATUS[it['kind']]} |  |  |  |")
    TRACE.write_text("\n".join(tr) + "\n", encoding="utf-8")
    print(f"sections={len(sections)} requirements={len(items)} build={sum(1 for i in items if i['kind']=='BUILD')}")


if __name__ == "__main__":
    main()

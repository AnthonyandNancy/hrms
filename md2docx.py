# -*- coding: utf-8 -*-
"""Markdown -> styled DOCX renderer for the HRMS deliverables.

Supports the subset used in docs/: headings, paragraphs, * bullets, TABLE:
blocks, fenced code, blockquote callouts, images, and page breaks.
"""
import os, re, sys
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_TAB_ALIGNMENT, WD_TAB_LEADER
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image

NAVY = RGBColor(0x1B, 0x2A, 0x4A)
SLATE = RGBColor(0x3D, 0x4A, 0x63)
MUTED = RGBColor(0x6B, 0x76, 0x8C)
ACCENT = RGBColor(0x4F, 0x46, 0xE5)
TEAL = RGBColor(0x0E, 0x7C, 0x86)
RED = RGBColor(0xB4, 0x23, 0x18)
GOLD = RGBColor(0xA8, 0x72, 0x1A)
BODY = RGBColor(0x25, 0x2E, 0x40)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
LINE = "D9DEE8"
LIGHT = "F4F6FB"
PALE_ACCENT = "EEF0FF"
PALE_TEAL = "E8F5F5"
PALE_RED = "FBEDEC"
PALE_GOLD = "FBF5E8"

CN = "Microsoft YaHei"
LATIN = "Aptos"
MONO = "Consolas"


def set_font(run, name=CN, size=None, bold=None, color=None, italic=None):
    run.font.name = name
    rPr = run._element.get_or_add_rPr()
    rf = rPr.find(qn('w:rFonts'))
    if rf is None:
        rf = OxmlElement('w:rFonts'); rPr.append(rf)
    rf.set(qn('w:ascii'), LATIN if name != MONO else MONO)
    rf.set(qn('w:hAnsi'), LATIN if name != MONO else MONO)
    rf.set(qn('w:eastAsia'), name)
    if size is not None: run.font.size = Pt(size)
    if bold is not None: run.font.bold = bold
    if italic is not None: run.font.italic = italic
    if color is not None: run.font.color.rgb = color


def shade_cell(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear'); shd.set(qn('w:color'), 'auto'); shd.set(qn('w:fill'), fill)
    tcPr.append(shd)


def cell_margins(cell, top=80, start=110, bottom=80, end=110):
    tcPr = cell._tc.get_or_add_tcPr()
    mar = OxmlElement('w:tcMar')
    for tag, val in (('top', top), ('start', start), ('bottom', bottom), ('end', end)):
        n = OxmlElement(f'w:{tag}')
        n.set(qn('w:w'), str(val)); n.set(qn('w:type'), 'dxa')
        mar.append(n)
    tcPr.append(mar)


def table_borders(table, color=LINE, size=4):
    tblPr = table._tbl.tblPr
    borders = OxmlElement('w:tblBorders')
    for edge in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        el = OxmlElement(f'w:{edge}')
        el.set(qn('w:val'), 'single'); el.set(qn('w:sz'), str(size))
        el.set(qn('w:space'), '0'); el.set(qn('w:color'), color)
        borders.append(el)
    tblPr.append(borders)


def no_borders(table):
    tblPr = table._tbl.tblPr
    borders = OxmlElement('w:tblBorders')
    for edge in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'):
        el = OxmlElement(f'w:{edge}'); el.set(qn('w:val'), 'nil')
        borders.append(el)
    tblPr.append(borders)


def repeat_header(row):
    trPr = row._tr.get_or_add_trPr()
    el = OxmlElement('w:tblHeader'); el.set(qn('w:val'), 'true'); trPr.append(el)


def no_split(row):
    trPr = row._tr.get_or_add_trPr()
    el = OxmlElement('w:cantSplit'); el.set(qn('w:val'), 'true'); trPr.append(el)


def field(paragraph, instr, placeholder='1'):
    run = paragraph.add_run()
    a = OxmlElement('w:fldChar'); a.set(qn('w:fldCharType'), 'begin')
    b = OxmlElement('w:instrText'); b.set(qn('xml:space'), 'preserve'); b.text = instr
    c = OxmlElement('w:fldChar'); c.set(qn('w:fldCharType'), 'separate')
    t = OxmlElement('w:t'); t.text = placeholder
    e = OxmlElement('w:fldChar'); e.set(qn('w:fldCharType'), 'end')
    run._r.append(a); run._r.append(b); run._r.append(c); run._r.append(t); run._r.append(e)


def setup(doc, header_text, footer_text):
    st = doc.styles['Normal']
    st.font.name = CN
    st.font.size = Pt(10)
    st.font.color.rgb = BODY
    rPr = st.element.get_or_add_rPr()
    rf = rPr.find(qn('w:rFonts'))
    if rf is None:
        rf = OxmlElement('w:rFonts'); rPr.append(rf)
    rf.set(qn('w:ascii'), LATIN); rf.set(qn('w:hAnsi'), LATIN); rf.set(qn('w:eastAsia'), CN)
    pf = st.paragraph_format
    pf.line_spacing = 1.55; pf.space_after = Pt(6); pf.space_before = Pt(0)

    specs = {
        'Heading 1': (20, NAVY, 24, 8, 1.16),
        'Heading 2': (14.5, NAVY, 17, 6, 1.2),
        'Heading 3': (11.6, RGBColor(0x2F, 0x3E, 0x5E), 11, 4, 1.25),
        'Heading 4': (10.3, ACCENT, 8, 3, 1.3),
    }
    for name, (size, color, before, after, line) in specs.items():
        s = doc.styles[name]
        s.font.name = CN; s.font.size = Pt(size); s.font.bold = True; s.font.color.rgb = color
        r = s.element.get_or_add_rPr()
        f = r.find(qn('w:rFonts'))
        if f is None:
            f = OxmlElement('w:rFonts'); r.append(f)
        f.set(qn('w:ascii'), LATIN); f.set(qn('w:hAnsi'), LATIN); f.set(qn('w:eastAsia'), CN)
        s.paragraph_format.space_before = Pt(before)
        s.paragraph_format.space_after = Pt(after)
        s.paragraph_format.keep_with_next = True
        s.paragraph_format.line_spacing = line

    sec = doc.sections[0]
    sec.page_width = Cm(21); sec.page_height = Cm(29.7)
    sec.left_margin = Cm(2.4); sec.right_margin = Cm(2.4)
    sec.top_margin = Cm(2.0); sec.bottom_margin = Cm(1.9)
    sec.header_distance = Cm(1.0); sec.footer_distance = Cm(0.85)

    hp = sec.header.paragraphs[0]
    hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    r = hp.add_run(header_text)
    set_font(r, CN, 7.8, False, MUTED)
    pPr = hp._p.get_or_add_pPr()
    pbdr = OxmlElement('w:pBdr'); bot = OxmlElement('w:bottom')
    bot.set(qn('w:val'), 'single'); bot.set(qn('w:sz'), '4'); bot.set(qn('w:space'), '4'); bot.set(qn('w:color'), LINE)
    pbdr.append(bot); pPr.append(pbdr)

    fp = sec.footer.paragraphs[0]
    fp.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = fp.add_run(footer_text + "   ·   ")
    set_font(r, CN, 7.8, False, MUTED)
    field(fp, ' PAGE ', '1')
    r = fp.add_run(" / ")
    set_font(r, CN, 7.8, False, MUTED)
    field(fp, ' NUMPAGES ', '1')
    for run in fp.runs:
        run.font.size = Pt(7.8); run.font.color.rgb = MUTED


def rich(p, text, size=9.9, color=BODY, bold=False):
    # inline **bold** and `code`
    parts = re.split(r'(\*\*[^*]+\*\*|`[^`]+`)', text)
    for part in parts:
        if not part:
            continue
        if part.startswith('**') and part.endswith('**'):
            r = p.add_run(part[2:-2]); set_font(r, CN, size, True, color)
        elif part.startswith('`') and part.endswith('`'):
            r = p.add_run(part[1:-1]); set_font(r, MONO, max(size - 1.0, 7.0), False, ACCENT)
        else:
            r = p.add_run(part); set_font(r, CN, size, bold if bold else None, color)


def spacer(doc, pts=5):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(0); p.paragraph_format.space_after = Pt(0)
    p.add_run().font.size = Pt(pts)
    return p


def cover(doc, meta):
    p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(30); p.paragraph_format.space_after = Pt(2)
    r = p.add_run(meta['series']); set_font(r, CN, 9, True, ACCENT)
    p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(60); p.paragraph_format.space_after = Pt(8)
    r = p.add_run(meta['title']); set_font(r, CN, 31, True, NAVY)
    p = doc.add_paragraph(); p.paragraph_format.space_after = Pt(16)
    r = p.add_run(meta['subtitle']); set_font(r, CN, 15, False, SLATE)
    p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(2); p.paragraph_format.space_after = Pt(26)
    pPr = p._p.get_or_add_pPr(); pbdr = OxmlElement('w:pBdr'); bot = OxmlElement('w:bottom')
    bot.set(qn('w:val'), 'single'); bot.set(qn('w:sz'), '22'); bot.set(qn('w:space'), '1'); bot.set(qn('w:color'), '4F46E5')
    pbdr.append(bot); pPr.append(pbdr)
    p = doc.add_paragraph(); p.paragraph_format.space_after = Pt(8)
    r = p.add_run(meta['dek']); set_font(r, CN, 10.8, False, SLATE)
    p = doc.add_paragraph(); p.paragraph_format.space_after = Pt(46)
    r = p.add_run(meta['version_line']); set_font(r, CN, 9, False, MUTED)

    t = doc.add_table(rows=0, cols=2)
    t.autofit = False
    t.columns[0].width = Cm(3.4); t.columns[1].width = Cm(12.4)
    for k, v in meta['fields']:
        cells = t.add_row().cells
        cells[0].width = Cm(3.4); cells[1].width = Cm(12.4)
        p = cells[0].paragraphs[0]; p.paragraph_format.space_after = Pt(5)
        r = p.add_run(k); set_font(r, CN, 8.8, True, MUTED)
        p = cells[1].paragraphs[0]; p.paragraph_format.space_after = Pt(5)
        r = p.add_run(v); set_font(r, CN, 8.8, False, SLATE)
    doc.add_page_break()


def contents(doc, entries):
    doc.add_heading('目录', level=1)
    p = doc.add_paragraph(); p.paragraph_format.space_after = Pt(10)
    r = p.add_run('页码对应本 PDF / 打印版本；Word 中标题已应用 Heading 样式，可另行生成自动目录。')
    set_font(r, CN, 8.4, False, MUTED)

    table = doc.add_table(rows=0, cols=3)
    table.autofit = False
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    no_borders(table)
    for entry in entries:
        num, title, desc = entry[0], entry[1], entry[2]
        page = entry[3] if len(entry) > 3 else ''
        cells = table.add_row().cells
        widths = (1.1, 13.9, 1.2)
        for i, c in enumerate(cells):
            c.width = Cm(widths[i])
            cell_margins(c, 35, 50, 35, 40)
            c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP
        p = cells[0].paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.2
        r = p.add_run(str(num)); set_font(r, MONO, 8.6, True, ACCENT)
        p = cells[1].paragraphs[0]
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.2
        r = p.add_run(title); set_font(r, CN, 9.6, True, NAVY)
        r = p.add_run('   ' + desc); set_font(r, CN, 8.0, False, MUTED)
        p = cells[2].paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.2
        r = p.add_run(str(page)); set_font(r, MONO, 8.6, True, MUTED)
    doc.add_page_break()


def tiny_separator(doc):
    body = doc.element.body
    if len(body) and body[-1].tag == qn('w:tbl'):
        p = doc.add_paragraph()
        pf = p.paragraph_format
        pf.space_before = Pt(0)
        pf.space_after = Pt(0)
        pf.line_spacing = Pt(1)
        r = p.add_run('')
        r.font.size = Pt(1)


def callout(doc, kind, text):
    tiny_separator(doc)
    fill, bar, label = {
        'LEAD': (PALE_ACCENT, '4F46E5', '要点'),
        'KEY': (PALE_TEAL, '0E7C86', '关键'),
        'IMPORTANT': (PALE_ACCENT, '4F46E5', '重要'),
        'WARNING': (PALE_RED, 'B42318', '警告'),
        'NOTE': (PALE_GOLD, 'A8721A', '说明'),
    }.get(kind, (PALE_ACCENT, '4F46E5', '说明'))
    t = doc.add_table(rows=1, cols=1)
    t.autofit = False
    t.columns[0].width = Cm(16.2)
    c = t.cell(0, 0); c.width = Cm(16.2)
    shade_cell(c, fill)
    cell_margins(c, 140, 210, 140, 190)
    tcPr = c._tc.get_or_add_tcPr()
    borders = OxmlElement('w:tcBorders')
    for edge in ('top', 'bottom', 'right', 'insideH', 'insideV'):
        el = OxmlElement(f'w:{edge}'); el.set(qn('w:val'), 'nil'); borders.append(el)
    left = OxmlElement('w:left'); left.set(qn('w:val'), 'single'); left.set(qn('w:sz'), '24')
    left.set(qn('w:space'), '0'); left.set(qn('w:color'), bar)
    borders.append(left); tcPr.append(borders)
    p = c.paragraphs[0]; p.paragraph_format.space_after = Pt(0); p.paragraph_format.line_spacing = 1.45
    r = p.add_run(label + '  '); set_font(r, CN, 8.8, True, RGBColor.from_string(bar))
    rich(p, text, size=9.2, color=SLATE)
    spacer(doc, 4)


def code_block(doc, lines):
    tiny_separator(doc)
    t = doc.add_table(rows=1, cols=1)
    t.autofit = False
    t.columns[0].width = Cm(16.2)
    c = t.cell(0, 0); c.width = Cm(16.2)
    shade_cell(c, 'F6F8FB')
    cell_margins(c, 100, 170, 100, 140)
    tcPr = c._tc.get_or_add_tcPr()
    borders = OxmlElement('w:tcBorders')
    for edge in ('top', 'left', 'bottom', 'right'):
        el = OxmlElement(f'w:{edge}')
        if edge == 'left':
            el.set(qn('w:val'), 'single'); el.set(qn('w:sz'), '18'); el.set(qn('w:space'), '0'); el.set(qn('w:color'), '0E7C86')
        else:
            el.set(qn('w:val'), 'single'); el.set(qn('w:sz'), '4'); el.set(qn('w:space'), '0'); el.set(qn('w:color'), LINE)
        borders.append(el)
    tcPr.append(borders)
    for i, line in enumerate(lines):
        p = c.paragraphs[0] if i == 0 else c.add_paragraph()
        p.paragraph_format.space_before = Pt(0)
        p.paragraph_format.space_after = Pt(0)
        p.paragraph_format.line_spacing = 1.1
        txt = line if line else ' '
        r = p.add_run(txt); set_font(r, MONO, 8.0, False, RGBColor(0x26, 0x32, 0x48))
        if txt.strip().startswith(('#', '--', '//')):
            r.font.color.rgb = TEAL
    if len(lines) <= 28:
        trPr = t.rows[0]._tr.get_or_add_trPr()
        cant = OxmlElement('w:cantSplit'); cant.set(qn('w:val'), 'true'); trPr.append(cant)


def parse_table(lines):
    rows = []
    for line in lines:
        s = line.strip()
        if not s.startswith('|'):
            continue
        cells = [x.strip() for x in s.strip('|').split('|')]
        if all(re.fullmatch(r':?-{3,}:?', x) for x in cells if x):
            continue
        rows.append(cells)
    return rows


def _char_width(text):
    total = 0.0
    for ch in str(text):
        total += 2.0 if ord(ch) > 255 else 1.0
    return total


def table_block(doc, rows, caption=None):
    if not rows:
        return
    tiny_separator(doc)
    ncol = max(len(r) for r in rows)
    rows = [r + [''] * (ncol - len(r)) for r in rows]
    headers, body = rows[0], rows[1:]
    if caption:
        p = doc.add_paragraph()
        p.paragraph_format.space_before = Pt(6)
        p.paragraph_format.space_after = Pt(3)
        p.paragraph_format.keep_with_next = True
        r = p.add_run(caption)
        set_font(r, CN, 8.4, True, MUTED)

    natural = []
    for i in range(ncol):
        m = max(_char_width(r[i]) for r in rows)
        avg = sum(_char_width(r[i]) for r in body) / max(len(body), 1)
        natural.append(max(8.0, min(34.0, 0.55 * min(m, 40) + 0.45 * avg)))
    total = sum(natural)
    widths = [max(2.3, 16.2 * n / total) for n in natural]
    # fit exactly 16.2 cm, shrinking/growing only columns above the minimum
    diff = 16.2 - sum(widths)
    adjustable = [i for i, w in enumerate(widths) if w > 2.35]
    guard = 0
    while abs(diff) > 0.02 and adjustable and guard < 20:
        share = diff / len(adjustable)
        for i in adjustable:
            widths[i] += share
        widths = [max(2.3, w) for w in widths]
        diff = 16.2 - sum(widths)
        adjustable = [i for i, w in enumerate(widths) if w > 2.35]
        guard += 1
    t = doc.add_table(rows=1, cols=ncol)
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    t.autofit = False
    table_borders(t)
    hdr = t.rows[0]; no_split(hdr)
    if len(body) > 7:
        repeat_header(hdr)
    for i, h in enumerate(headers):
        c = hdr.cells[i]; c.width = Cm(widths[i]); cell_margins(c)
        shade_cell(c, '24324F'); c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        p = c.paragraphs[0]; p.paragraph_format.space_after = Pt(0); p.paragraph_format.line_spacing = 1.18
        rich(p, h, size=7.6 if len(body) >= 7 else 8.2, color=WHITE, bold=True)
        for r in p.runs:
            r.font.bold = True
    compact = len(body) >= 7
    for ri, row in enumerate(body):
        tr = t.add_row(); no_split(tr)
        for i, val in enumerate(row):
            c = tr.cells[i]; c.width = Cm(widths[i])
            cell_margins(c, 55 if compact else 80, 95 if compact else 110,
                         55 if compact else 80, 95 if compact else 110)
            if ri % 2 == 1:
                shade_cell(c, 'F7F8FC')
            c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.TOP
            p = c.paragraphs[0]; p.paragraph_format.space_after = Pt(0); p.paragraph_format.line_spacing = 1.3
            segments = val.replace('<br>', '\n').split('\n')
            for si, seg in enumerate(segments):
                if si:
                    p.add_run().add_break()
                rich(p, seg, size=7.5 if len(body) >= 7 else 8.0, color=BODY, bold=(i == 0))
    # Never strand a table header at the bottom of a page: header and first
    # body row stay together.
    if len(t.rows) >= 2:
        for cell in t.rows[0].cells + t.rows[1].cells:
            for p in cell.paragraphs:
                p.paragraph_format.keep_with_next = True

    # Short tables should move to the next page as a unit instead of leaving a
    # repeated header with zero body rows at a page boundary. Long tables are
    # allowed to split and keep repeating their header.
    if len(body) <= 7:
        for row in t.rows[:-1]:
            for cell in row.cells:
                for p in cell.paragraphs:
                    p.paragraph_format.keep_with_next = True


def add_image(doc, path, caption=None):
    p = doc.add_paragraph(); p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.paragraph_format.space_before = Pt(6); p.paragraph_format.space_after = Pt(3)
    p.add_run().add_picture(path, width=Cm(16.0))
    if caption:
        cp = doc.add_paragraph(); cp.alignment = WD_ALIGN_PARAGRAPH.CENTER
        cp.paragraph_format.space_after = Pt(10)
        r = cp.add_run(caption); set_font(r, CN, 8.0, False, MUTED)


HEAD_RE = re.compile(r'^(#{2,4})\s+(.*)$')
TABLE_RE = re.compile(r'^TABLE:\s*(.*)$')
IMG_RE = re.compile(r'^!\[([^\]]*)\]\(([^)]+)\)\s*$')


def render(md_path, out_path, meta, cover_meta):
    with open(md_path, encoding='utf-8') as f:
        lines = f.read().split('\n')
    doc = Document()
    setup(doc, meta['header'], meta['footer'])
    cover(doc, cover_meta)
    contents(doc, cover_meta['toc'])

    i = 0
    chapter_no = 0
    while i < len(lines):
        line = lines[i]
        s = line.strip()

        if not s:
            i += 1; continue

        m = HEAD_RE.match(s)
        if m:
            hashes, title = m.group(1), m.group(2).strip()
            level = len(hashes) - 1
            if level == 1:
                chapter_no += 1
                # kicker above H1
                km = re.match(r'^(\d{2})\s+(.*)$', title)
                if km:
                    p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(2); p.paragraph_format.space_after = Pt(0)
                    if chapter_no > 1:
                        p.paragraph_format.page_break_before = True
                    p.paragraph_format.keep_with_next = True
                    r = p.add_run(f'CHAPTER {km.group(1)}'); set_font(r, CN, 8.2, True, ACCENT)
                    h = doc.add_heading(km.group(2), level=1)
                    h.paragraph_format.keep_with_next = True
                else:
                    h = doc.add_heading(title, level=1)
                    if chapter_no > 1:
                        h.paragraph_format.page_break_before = True
            else:
                doc.add_heading(title, level=min(level, 4))
            i += 1; continue

        m = TABLE_RE.match(s)
        if m:
            caption = m.group(1).strip()
            i += 1
            tl = []
            while i < len(lines) and lines[i].strip().startswith('|'):
                tl.append(lines[i]); i += 1
            table_block(doc, parse_table(tl), caption)
            continue

        if s.startswith('```'):
            i += 1
            block = []
            while i < len(lines) and not lines[i].strip().startswith('```'):
                block.append(lines[i].rstrip()); i += 1
            i += 1
            code_block(doc, block)
            continue

        m = IMG_RE.match(s)
        if m:
            alt, rel = m.group(1), m.group(2)
            path = os.path.normpath(os.path.join(os.path.dirname(md_path), rel))
            if os.path.exists(path):
                add_image(doc, path, alt)
            else:
                callout(doc, 'WARNING', f'图片缺失：{rel}')
            i += 1; continue

        if s.startswith('> '):
            body = s[2:].strip()
            km = re.match(r'^(LEAD|KEY|IMPORTANT|WARNING|NOTE):\s*(.*)$', body)
            if km:
                callout(doc, km.group(1), km.group(2))
            else:
                callout(doc, 'NOTE', body)
            i += 1; continue

        if s in ('---', '***', '___'):
            p = doc.add_paragraph(); p.paragraph_format.space_before = Pt(4); p.paragraph_format.space_after = Pt(4)
            pPr = p._p.get_or_add_pPr(); pbdr = OxmlElement('w:pBdr'); bot = OxmlElement('w:bottom')
            bot.set(qn('w:val'), 'single'); bot.set(qn('w:sz'), '4'); bot.set(qn('w:space'), '1'); bot.set(qn('w:color'), LINE)
            pbdr.append(bot); pPr.append(pbdr)
            i += 1; continue

        if s.startswith('- [ ] '):
            p = doc.add_paragraph()
            pf = p.paragraph_format
            pf.left_indent = Cm(0.55); pf.first_line_indent = Cm(-0.42)
            pf.space_before = Pt(0.5); pf.space_after = Pt(2.2); pf.line_spacing = 1.4
            r = p.add_run('□  '); set_font(r, CN, 9.4, True, MUTED)
            rich(p, s[6:], size=9.5)
            i += 1; continue

        om = re.match(r'^(\s*)(\d+)\.\s+(.*)$', line)
        if om:
            indent = len(om.group(1)) // 2
            p = doc.add_paragraph()
            pf = p.paragraph_format
            pf.left_indent = Cm(0.62 + indent * 0.55)
            pf.first_line_indent = Cm(-0.44)
            pf.space_before = Pt(0.5); pf.space_after = Pt(2.6); pf.line_spacing = 1.45
            r = p.add_run(om.group(2) + '.  '); set_font(r, CN, 9.2, True, ACCENT)
            rich(p, om.group(3), size=9.5)
            i += 1; continue

        bm = re.match(r'^(\s*)-\s+(.*)$', line)
        if bm:
            indent = len(bm.group(1)) // 2
            p = doc.add_paragraph()
            pf = p.paragraph_format
            pf.left_indent = Cm(0.55 + indent * 0.55)
            pf.first_line_indent = Cm(-0.34)
            pf.space_before = Pt(0.5); pf.space_after = Pt(2.4); pf.line_spacing = 1.45
            r = p.add_run('•  ' if indent == 0 else '–  '); set_font(r, CN, 9.0, True, ACCENT if indent == 0 else MUTED)
            rich(p, bm.group(2), size=9.5)
            i += 1; continue

        # paragraph (join consecutive plain lines)
        para = [s]
        i += 1
        while i < len(lines):
            nxt = lines[i]
            ns = nxt.strip()
            if (not ns or HEAD_RE.match(ns) or TABLE_RE.match(ns) or ns.startswith('```')
                    or ns.startswith('> ') or ns.startswith('|') or IMG_RE.match(ns)
                    or re.match(r'^(\s*)-\s+', nxt) or re.match(r'^\s*\d+\.\s+', nxt)
                    or ns in ('---', '***', '___')):
                break
            para.append(ns); i += 1
        p = doc.add_paragraph()
        p.paragraph_format.space_after = Pt(6.5)
        p.paragraph_format.line_spacing = 1.55
        rich(p, ' '.join(para), size=9.9)

    doc.save(out_path)
    return out_path


if __name__ == '__main__':
    pass

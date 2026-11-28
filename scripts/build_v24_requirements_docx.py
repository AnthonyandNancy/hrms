#!/usr/bin/env python3
"""Build the FactoryHR V2.4 requirement and technical design DOCX.

The Markdown document is the editable source of truth. This script applies
consistent Chinese document styling and converts the Markdown structure used by
the source document into a Word document.
"""

from __future__ import annotations

import re
from pathlib import Path

from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT, WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "FactoryHR_V2.4_需求与技术设计说明书.md"
OUTPUT = ROOT / "docs" / "FactoryHR_V2.4_需求与技术设计说明书.docx"

BODY_FONT = "宋体"
HEADING_FONT = "微软雅黑"
CODE_FONT = "Consolas"
ACCENT = "1F4E79"
LIGHT_ACCENT = "D9EAF7"
GRID = "B7C9D6"
MUTED = "666666"


def set_cell_shading(cell, fill: str) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    shd = tc_pr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tc_pr.append(shd)
    shd.set(qn("w:fill"), fill)


def set_cell_margins(cell, top=80, start=100, bottom=80, end=100) -> None:
    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    tc_mar = tc_pr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for m, v in (("top", top), ("start", start), ("bottom", bottom), ("end", end)):
        node = tc_mar.find(qn(f"w:{m}"))
        if node is None:
            node = OxmlElement(f"w:{m}")
            tc_mar.append(node)
        node.set(qn("w:w"), str(v))
        node.set(qn("w:type"), "dxa")


def set_repeat_table_header(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    tbl_header = OxmlElement("w:tblHeader")
    tbl_header.set(qn("w:val"), "true")
    tr_pr.append(tbl_header)


def prevent_row_split(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    cant_split = OxmlElement("w:cantSplit")
    tr_pr.append(cant_split)


def set_table_borders(table, color=GRID, size="4") -> None:
    tbl = table._tbl
    tbl_pr = tbl.tblPr
    borders = tbl_pr.first_child_found_in("w:tblBorders")
    if borders is None:
        borders = OxmlElement("w:tblBorders")
        tbl_pr.append(borders)
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        tag = f"w:{edge}"
        element = borders.find(qn(tag))
        if element is None:
            element = OxmlElement(tag)
            borders.append(element)
        element.set(qn("w:val"), "single")
        element.set(qn("w:sz"), size)
        element.set(qn("w:space"), "0")
        element.set(qn("w:color"), color)


def apply_font(run, name: str, size: float | None = None, bold: bool | None = None, color: str | None = None) -> None:
    run.font.name = name
    run._element.get_or_add_rPr().rFonts.set(qn("w:eastAsia"), name)
    if size is not None:
        run.font.size = Pt(size)
    if bold is not None:
        run.bold = bold
    if color is not None:
        run.font.color.rgb = RGBColor.from_string(color)


def shade_paragraph(paragraph, fill: str) -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), fill)
    p_pr.append(shd)


def add_bottom_border(paragraph, color=ACCENT, size="8") -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    p_bdr = OxmlElement("w:pBdr")
    bottom = OxmlElement("w:bottom")
    bottom.set(qn("w:val"), "single")
    bottom.set(qn("w:sz"), size)
    bottom.set(qn("w:space"), "1")
    bottom.set(qn("w:color"), color)
    p_bdr.append(bottom)
    p_pr.append(p_bdr)


def add_page_number_footer(section) -> None:
    footer = section.footer
    p = footer.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = p.add_run("第 ")
    apply_font(run, BODY_FONT, 9, color=MUTED)
    fld_begin = OxmlElement("w:fldChar")
    fld_begin.set(qn("w:fldCharType"), "begin")
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = " PAGE "
    fld_sep = OxmlElement("w:fldChar")
    fld_sep.set(qn("w:fldCharType"), "separate")
    fld_end = OxmlElement("w:fldChar")
    fld_end.set(qn("w:fldCharType"), "end")
    run._r.extend([fld_begin, instr, fld_sep, fld_end])
    run2 = p.add_run(" 页")
    apply_font(run2, BODY_FONT, 9, color=MUTED)


def add_header(section, text: str) -> None:
    header = section.header
    p = header.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    run = p.add_run(text)
    apply_font(run, HEADING_FONT, 8.5, color=MUTED)
    add_bottom_border(p, color=GRID, size="4")


def configure_styles(document: Document) -> None:
    styles = document.styles

    normal = styles["Normal"]
    normal.font.name = BODY_FONT
    normal._element.get_or_add_rPr().rFonts.set(qn("w:eastAsia"), BODY_FONT)
    normal.font.size = Pt(10.5)
    normal.paragraph_format.space_after = Pt(4)
    normal.paragraph_format.line_spacing = 1.35

    for name, size, color in (
        ("Title", 21, ACCENT),
        ("Subtitle", 11, MUTED),
        ("Heading 1", 17, ACCENT),
        ("Heading 2", 14, ACCENT),
        ("Heading 3", 12, "243F60"),
        ("Heading 4", 11, "243F60"),
    ):
        style = styles[name]
        style.font.name = HEADING_FONT
        style._element.get_or_add_rPr().rFonts.set(qn("w:eastAsia"), HEADING_FONT)
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = RGBColor.from_string(color)
        style.paragraph_format.space_before = Pt(12 if name == "Heading 1" else 8)
        style.paragraph_format.space_after = Pt(6)
        style.paragraph_format.keep_with_next = True

    styles["Title"].paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER
    styles["Subtitle"].paragraph_format.alignment = WD_ALIGN_PARAGRAPH.CENTER
    styles["Title"].paragraph_format.space_before = Pt(72)
    styles["Title"].paragraph_format.space_after = Pt(12)

    for list_style in ("List Bullet", "List Number", "List Bullet 2", "List Number 2"):
        try:
            style = styles[list_style]
            style.font.name = BODY_FONT
            style._element.get_or_add_rPr().rFonts.set(qn("w:eastAsia"), BODY_FONT)
            style.font.size = Pt(10.5)
        except KeyError:
            pass

    code_style = styles.add_style("Code Block", 1)
    code_style.font.name = CODE_FONT
    code_style._element.get_or_add_rPr().rFonts.set(qn("w:eastAsia"), CODE_FONT)
    code_style.font.size = Pt(8.5)
    code_style.paragraph_format.left_indent = Cm(0.45)
    code_style.paragraph_format.right_indent = Cm(0.2)
    code_style.paragraph_format.space_before = Pt(2)
    code_style.paragraph_format.space_after = Pt(2)
    code_style.paragraph_format.line_spacing = 1.0


def add_inline_runs(paragraph, text: str, base_size: float | None = None) -> None:
    # Support **bold**, `code`, and links; everything else is normal text.
    pattern = re.compile(r"(\*\*.+?\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))")
    cursor = 0
    for match in pattern.finditer(text):
        if match.start() > cursor:
            run = paragraph.add_run(text[cursor:match.start()])
            apply_font(run, BODY_FONT, base_size)
        token = match.group(0)
        if token.startswith("**") and token.endswith("**"):
            run = paragraph.add_run(token[2:-2])
            apply_font(run, BODY_FONT, base_size, bold=True)
        elif token.startswith("`") and token.endswith("`"):
            run = paragraph.add_run(token[1:-1])
            apply_font(run, CODE_FONT, (base_size or 10.5) - 1)
            run.font.color.rgb = RGBColor.from_string("7A3E00")
        else:
            label = re.sub(r"^\[([^\]]+)\].*$", r"\1", token)
            run = paragraph.add_run(label)
            apply_font(run, BODY_FONT, base_size, color="0563C1")
            run.underline = True
        cursor = match.end()
    if cursor < len(text):
        run = paragraph.add_run(text[cursor:])
        apply_font(run, BODY_FONT, base_size)


def add_paragraph(document: Document, text: str, style: str | None = None, base_size: float | None = None):
    p = document.add_paragraph(style=style) if style else document.add_paragraph()
    add_inline_runs(p, text, base_size)
    return p


def add_code_block(document: Document, lines: list[str]) -> None:
    p = document.add_paragraph(style="Code Block")
    shade_paragraph(p, "F3F6F8")
    run = p.add_run("\n".join(lines))
    apply_font(run, CODE_FONT, 8.5)
    p.paragraph_format.keep_together = True


def add_bullet(document: Document, text: str, level: int = 0) -> None:
    style = "List Bullet" if level == 0 else "List Bullet 2"
    try:
        p = document.add_paragraph(style=style)
    except KeyError:
        p = document.add_paragraph()
        p.paragraph_format.left_indent = Cm(0.8 + 0.5 * level)
        p.add_run("• ")
    add_inline_runs(p, text)


def add_numbered(document: Document, text: str, level: int = 0) -> None:
    style = "List Number" if level == 0 else "List Number 2"
    try:
        p = document.add_paragraph(style=style)
    except KeyError:
        p = document.add_paragraph()
        p.paragraph_format.left_indent = Cm(0.8 + 0.5 * level)
        p.add_run("1. ")
    add_inline_runs(p, text)


def parse_table_row(line: str) -> list[str]:
    value = line.strip()
    if value.startswith("|"):
        value = value[1:]
    if value.endswith("|"):
        value = value[:-1]
    return [cell.strip() for cell in value.split("|")]


def is_table_separator(line: str) -> bool:
    cells = parse_table_row(line)
    return bool(cells) and all(re.fullmatch(r":?-{3,}:?", c.strip()) for c in cells)


def add_table(document: Document, rows: list[list[str]]) -> None:
    if not rows:
        return
    columns = max(len(r) for r in rows)
    normalized = [r + [""] * (columns - len(r)) for r in rows]
    table = document.add_table(rows=len(normalized), cols=columns)
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = True
    set_table_borders(table)

    available = 17.2
    if columns > 5:
        font_size = 8
    elif columns == 5:
        font_size = 8.5
    else:
        font_size = 9

    for row_index, row_values in enumerate(normalized):
        row = table.rows[row_index]
        prevent_row_split(row)
        if row_index == 0:
            set_repeat_table_header(row)
        for col_index, value in enumerate(row_values):
            cell = row.cells[col_index]
            set_cell_margins(cell)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            p = cell.paragraphs[0]
            p.paragraph_format.space_after = Pt(0)
            p.paragraph_format.line_spacing = 1.0
            if row_index == 0:
                p.alignment = WD_ALIGN_PARAGRAPH.CENTER
                set_cell_shading(cell, LIGHT_ACCENT)
                run = p.add_run(value.replace("**", ""))
                apply_font(run, HEADING_FONT, font_size, bold=True, color="153A5B")
            else:
                add_inline_runs(p, value, font_size)
            try:
                cell.width = Cm(available / columns)
            except Exception:
                pass


def build() -> Path:
    md = SOURCE.read_text(encoding="utf-8")
    lines = md.splitlines()

    document = Document()
    section = document.sections[0]
    section.page_width = Cm(21.0)
    section.page_height = Cm(29.7)
    section.top_margin = Cm(2.0)
    section.bottom_margin = Cm(1.9)
    section.left_margin = Cm(1.9)
    section.right_margin = Cm(1.9)
    section.header_distance = Cm(1.0)
    section.footer_distance = Cm(1.0)

    configure_styles(document)
    add_header(section, "FactoryHR V2.4｜需求与技术设计说明书")
    add_page_number_footer(section)

    document.core_properties.title = "FactoryHR V2.4 薪酬规则引擎与 21 模块业务闭环整改需求及技术设计说明书"
    document.core_properties.subject = "FactoryHR V2.4 Requirement and Technical Design"
    document.core_properties.author = "FactoryHR 项目组"
    document.core_properties.category = "需求与技术设计"
    document.core_properties.comments = "由已确认的需求拆解和技术调查分析整理。"

    i = 0
    first_heading = True
    toc_like = False

    while i < len(lines):
        raw = lines[i]
        line = raw.rstrip()

        if line.strip() == "<!-- PAGEBREAK -->":
            document.add_page_break()
            i += 1
            continue

        if line.strip() == "---":
            p = document.add_paragraph()
            add_bottom_border(p, color=GRID, size="4")
            i += 1
            continue

        if line.startswith("```"):
            code_lines: list[str] = []
            i += 1
            while i < len(lines) and not lines[i].startswith("```"):
                code_lines.append(lines[i])
                i += 1
            add_code_block(document, code_lines)
            i += 1
            continue

        if line.startswith("|"):
            table_rows: list[list[str]] = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                if not is_table_separator(lines[i]):
                    table_rows.append(parse_table_row(lines[i]))
                i += 1
            add_table(document, table_rows)
            document.add_paragraph().paragraph_format.space_after = Pt(1)
            continue

        heading_match = re.match(r"^(#{1,4})\s+(.+)$", line)
        if heading_match:
            level = len(heading_match.group(1))
            text = heading_match.group(2).strip()

            if level == 1 and first_heading:
                p = document.add_paragraph(style="Title")
                add_inline_runs(p, text)
                subtitle = document.add_paragraph(style="Subtitle")
                run = subtitle.add_run("需求已确认 · 技术设计待评审")
                apply_font(run, HEADING_FONT, 11, color=MUTED)
                first_heading = False
                i += 1
                continue

            style = f"Heading {level}"
            p = document.add_paragraph(style=style)
            add_inline_runs(p, text)
            i += 1
            continue

        if line.startswith("> "):
            p = document.add_paragraph()
            p.paragraph_format.left_indent = Cm(0.7)
            p.paragraph_format.right_indent = Cm(0.4)
            shade_paragraph(p, "F2F6F9")
            add_inline_runs(p, line[2:].strip())
            i += 1
            continue

        bullet_match = re.match(r"^(\s*)[-*]\s+(.+)$", line)
        if bullet_match:
            level = len(bullet_match.group(1)) // 2
            add_bullet(document, bullet_match.group(2), level)
            i += 1
            continue

        number_match = re.match(r"^(\s*)\d+\.\s+(.+)$", line)
        if number_match:
            level = len(number_match.group(1)) // 4
            add_numbered(document, number_match.group(2), level)
            i += 1
            continue

        if line.strip() == "":
            i += 1
            continue

        add_paragraph(document, line.strip())
        i += 1

    document.save(OUTPUT)
    return OUTPUT


def verify(path: Path) -> None:
    doc = Document(path)
    paragraph_count = len(doc.paragraphs)
    table_count = len(doc.tables)
    heading_count = sum(1 for p in doc.paragraphs if p.style.name.startswith("Heading"))
    text = "\n".join(p.text for p in doc.paragraphs)
    required = [
        "第一部分 需求拆解与需求摘要",
        "第二部分 技术拆解与技术摘要",
        "17. 需求摘要",
        "7. 技术方案摘要",
        "附录 A：功能追踪基线",
    ]
    missing = [item for item in required if item not in text]
    if missing:
        raise RuntimeError(f"Missing required sections: {missing}")
    if paragraph_count < 250:
        raise RuntimeError(f"Unexpectedly small document: {paragraph_count} paragraphs")
    if table_count < 15:
        raise RuntimeError(f"Unexpectedly few tables: {table_count}")
    print(f"DOCX verified: {path}")
    print(f"Paragraphs: {paragraph_count}, tables: {table_count}, headings: {heading_count}")
    print(f"Size: {path.stat().st_size} bytes")


if __name__ == "__main__":
    output = build()
    verify(output)

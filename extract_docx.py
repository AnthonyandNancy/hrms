from docx import Document
from docx.document import Document as _Doc
from docx.table import Table
from docx.text.paragraph import Paragraph
from docx.oxml.ns import qn
import json, sys

path = "企业级人事管理系统_HRMS_V1.0_完整实施书.docx"
doc = Document(path)

def iter_block_items(parent):
    if isinstance(parent, _Doc):
        parent_elm = parent.element.body
    else:
        parent_elm = parent._tc
    for child in parent_elm.iterchildren():
        if child.tag == qn('w:p'):
            yield Paragraph(child, parent)
        elif child.tag == qn('w:tbl'):
            yield Table(child, parent)

lines=[]
tbl_idx=0
for block in iter_block_items(doc):
    if isinstance(block, Paragraph):
        text=block.text.strip()
        style=block.style.name if block.style else ''
        if text:
            prefix = f"[{style}] " if style else ''
            lines.append(prefix+text)
    else:
        tbl_idx+=1
        lines.append(f"\n===== TABLE {tbl_idx} ({len(block.rows)}x{len(block.columns)}) =====")
        for row in block.rows:
            cells=[]
            for c in row.cells:
                cells.append(" / ".join(p.text.strip() for p in c.paragraphs if p.text.strip()))
            lines.append(" | ".join(cells))
        lines.append("===== END TABLE =====\n")

out="\n".join(lines)
open("analysis/source_full.txt","w",encoding="utf-8").write(out)
print("chars:",len(out),"paras:",len(doc.paragraphs),"tables:",len(doc.tables),"sections:",len(doc.sections))
print("inline shapes:",len(doc.inline_shapes))
print("---- HEADINGS ----")
for p in doc.paragraphs:
    if p.style and p.style.name.startswith(("Heading","Title","标题")) and p.text.strip():
        print(p.style.name, "|", p.text.strip())

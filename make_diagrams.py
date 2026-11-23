from PIL import Image, ImageDraw, ImageFont
import os

W, H = 2000, 1180
BG = (255,255,255)
NAVY=(27,42,74); SLATE=(61,74,99); MUTED=(107,118,140)
ACCENT=(79,70,229); TEAL=(14,124,134); LINE=(217,222,232)
LIGHT=(244,246,251); PALE_ACC=(238,240,255); PALE_TEAL=(232,245,245)
GOLD=(180,132,42)

FONT='/c/Windows/Fonts/msyh.ttc'
FONTB='/c/Windows/Fonts/msyhbd.ttc'
def F(size,bold=False):
    return ImageFont.truetype(FONTB if bold else FONT, size)

def rrect(d, xy, r, fill=None, outline=None, width=2):
    d.rounded_rectangle(xy, radius=r, fill=fill, outline=outline, width=width)

def text(d, xy, s, font, fill=NAVY, anchor='la', spacing=8):
    d.multiline_text(xy, s, font=font, fill=fill, anchor=anchor, spacing=spacing)

def arrow(d, x1,y1,x2,y2, color=ACCENT, width=3, head=14):
    d.line((x1,y1,x2,y2), fill=color, width=width)
    # arrow head
    import math
    ang=math.atan2(y2-y1,x2-x1)
    for a in (ang+2.65, ang-2.65):
        d.line((x2,y2,x2+head*math.cos(a),y2+head*math.sin(a)), fill=color, width=width)

def title(d, kicker, t, sub=None):
    text(d,(80,45),kicker,F(30,True),ACCENT)
    text(d,(80,88),t,F(68,True),NAVY)
    if sub: text(d,(82,178),sub,F(33),SLATE)

# ---------- architecture ----------
img=Image.new('RGB',(W,H),BG); d=ImageDraw.Draw(img)
title(d,'SOLUTION ARCHITECTURE','五层本质架构', '体验可替换，契约要稳定；决策可演进，事实必须唯一')
# left labels
layers=[
 ('L5','体验层','管理端 · 员工自助 · 审批中心 · 报表中心', (244,246,251), SLATE),
 ('L4','契约层','OpenAPI · 命令/查询 · 错误码 · 幂等 · 事件契约', (238,240,255), ACCENT),
 ('L3','决策层','Case · Decision · Effect · Process Manager · 补偿', (232,245,245), TEAL),
 ('L2','事实内核','Person · Employment · Assignment · OrgEdge · Ledger · PayrollRun', (235,239,248), NAVY),
 ('L1','证据与投影','AuditEvent · Outbox · CurrentProfile · Headcount · ReportProjection', (248,244,235), GOLD),
]
x0=420; y=285; h=132; gap=22
for i,(code,name,desc,fill,accent) in enumerate(layers):
    y1=y+i*(h+gap)
    rrect(d,(x0,y1,1930,y1+h),18,fill=fill,outline=None)
    d.rectangle((x0,y1,x0+9,y1+h),fill=accent)
    text(d,(x0+32,y1+14),code,F(26,True),accent)
    text(d,(x0+32,y1+48),name,F(42,True),NAVY)
    text(d,(x0+250,y1+48),desc,F(31),SLATE)
# arrows between layers (bidirectional-ish)
for i in range(4):
    yy=y+i*(h+gap)+h
    arrow(d,x0+930,yy+2,x0+930,yy+gap-2,color=(160,168,190),width=2,head=8)
# side annotations
ann=[('可替换','页面、图表、交互',TEAL),('版本化','向后兼容与契约测试',ACCENT),('可审计','决定不可变，效果可补偿',TEAL),('唯一真相','时间化关系事实',NAVY),('可重建','投影失败不影响命令',GOLD)]
for i,(a,b,c) in enumerate(ann):
    yy=y+i*(h+gap)+38
    text(d,(80,yy),a,F(32,True),c)
    text(d,(80,yy+40),b,F(24),MUTED)
# bottom principle strip
rrect(d,(80,1030,1920,1125),16,fill=(27,42,74))
text(d,(118,1061),'设计原则：减少真相数量 · 显式时间语义 · 编译授权谓词 · 决定与生效分离',F(34,True),(255,255,255))
img.save('docs/diagrams/solution_architecture.png',quality=95)

# ---------- effect flow ----------
img=Image.new('RGB',(W,H),BG); d=ImageDraw.Draw(img)
title(d,'DECISION → EFFECT','审批决定不等于业务生效','Case 承载意图，Decision 留存证据，Effect 物化事实，Projection 负责解释')
# top flow boxes
y=365; h=280
boxes=[
 ('CASE','业务请求','请假 / 调岗 / 合同续签\n草稿 → 提交',PALE_ACC,ACCENT),
 ('DECISION','不可变决定','同意 / 驳回 / 撤回\n模板版本 + 节点快照',PALE_TEAL,TEAL),
 ('EFFECT','业务生效','SCHEDULED / APPLIED\nFAILED / REVERSED',(248,244,235),GOLD),
 ('FACT','事实与投影','账本 / 条款 / 任职版本\n当前视图 / 报表',(235,239,248),NAVY),
]
bw=425; gap=(1920-80-4*bw)//3
for i,(code,name,desc,fill,accent) in enumerate(boxes):
    x=80+i*(bw+gap)
    rrect(d,(x,y,x+bw,y+h),20,fill=fill)
    d.rectangle((x,y,x+bw,y+8),fill=accent)
    text(d,(x+26,y+30),code,F(25,True),accent)
    text(d,(x+26,y+68),name,F(44,True),NAVY)
    text(d,(x+26,y+142),desc,F(27),SLATE,spacing=11)
    if i<3:
        arrow(d,x+bw+12,y+h//2,x+bw+gap-14,y+h//2,color=(150,158,180),width=3,head=12)
# lower band: guarantees
y2=740
rrect(d,(80,y2,1920,y2+315),18,fill=(250,251,254),outline=LINE)
text(d,(112,y2+24),'一致性边界',F(36,True),NAVY)
cols=[
 ('同事务','本地确定性效果\n任职生效 / 请假占用','与 Decision 同事务提交，失败则整体回滚',ACCENT),
 ('至少一次','Outbox 事件\n通知 / 搜索 / 集成','消费者唯一键去重，事件只带 ID 与版本',TEAL),
 ('不可逆','已发工资 / 已签合同\n已报税 / 已发通知','停止扩散 + 更正批次 / 冲正 + 人工对账',GOLD),
]
cw=570; cg=(1790-3*cw)//2
for i,(tag,t1,t2,c) in enumerate(cols):
    x=112+i*(cw+cg)
    text(d,(x,y2+86),tag,F(40,True),c)
    text(d,(x,y2+146),t1,F(27),SLATE,spacing=9)
    text(d,(x,y2+235),t2,F(24),MUTED,spacing=8)
# warning strip
rrect(d,(80,1088,1920,1155),12,fill=(251,237,236))
text(d,(108,1107),'关键不变量：APPROVED ≠ EFFECTIVE。业务表不得以“通用审批状态”替代生效状态。',F(30,True),(180,35,24))
img.save('docs/diagrams/solution_effect.png',quality=95)

print('diagrams done', os.path.getsize('docs/diagrams/solution_architecture.png'), os.path.getsize('docs/diagrams/solution_effect.png'))

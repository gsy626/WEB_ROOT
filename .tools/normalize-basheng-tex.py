# -*- coding: utf-8 -*-
"""规整 八省联考压轴题.tex 的公式定界符：
   保留所有正文，只把"落单/残缺"的 $$ 补齐或去掉，
   并把左侧的说明文字与公式分成两行，避免 即$$公式$$ 这种粘连。"""
import sys

p = r'D:\HOME\PERSONAL_PROJECTS\tex\八省联考压轴\八省联考压轴题.tex'
raw = open(p, 'rb').read()
for enc in ('utf-8', 'gb18030'):
    try:
        text = raw.decode(enc)
        break
    except UnicodeDecodeError:
        continue
else:
    sys.exit('无法解码')

D = '$'
lines = text.split('\n')
out = []
stats = {'inline': 0, 'block': 0, 'stray': 0}

for line in lines:
    if D not in line:
        out.append(line)
        continue

    # 切出记号
    toks = []
    k = 0
    while k < len(line):
        if line[k] == D and (k == 0 or line[k - 1] != '\\'):
            n = 1
            while k + n < len(line) and line[k + n] == D:
                n += 1
            toks.append({'pos': k, 'run': n, 'block': n >= 2})
            k += n
        else:
            k += 1

    segs = []
    cur = 0
    for t in toks:
        segs.append({'math': False, 'text': line[cur:t['pos']]})
        segs.append({'math': True, 'block': t['block']})
        cur = t['pos'] + t['run']
    segs.append({'math': False, 'text': line[cur:]})

    # 重新拼装：block 记号单独成行，inline 记号就地闭合
    buf = []            # 普通行缓冲
    j = 0
    while j < len(segs):
        s = segs[j]
        if not s['math']:
            buf.append(s['text'])
            j += 1
            continue
        if s['block']:
            nxt = None
            for q in range(j + 1, len(segs)):
                if segs[q]['math'] and segs[q]['block']:
                    nxt = q
                    break
            if nxt is None:
                stats['stray'] += 1
                text_part = ''.join(x['text'] for x in segs[j + 1:] if not x['math'])
                if buf and buf[-1].strip():
                    pass
                out.append(''.join(buf))
                buf = []
                if text_part.strip():
                    out.append(text_part.strip())
                break
            body = ''.join(x['text'] for x in segs[j + 1:nxt] if not x['math']).strip()
            if body:
                head = ''.join(buf).strip()
                if head:
                    out.append(head)
                buf = []
                out.append(D * 2 + body + D * 2)
                stats['block'] += 1
            else:
                buf = ['']   # 空公式，丢弃
            j = nxt + 1
            continue
        # inline
        nxt = j + 1
        body = ''
        if nxt < len(segs) and not segs[nxt]['math']:
            body = segs[nxt]['text']
            nxt += 1
        if nxt < len(segs) and segs[nxt]['math'] and not segs[nxt]['block']:
            buf.append(D + body + D)
            stats['inline'] += 1
            j = nxt + 1
            continue
        # 没有配对的 $：当作普通文字，去掉记号
        stats['stray'] += 1
        buf.append(body)
        j = nxt
    if buf:
        out.append(''.join(buf))

new = '\n'.join(out)
# 收尾：合并连续空行
while '\n\n\n\n' in new:
    new = new.replace('\n\n\n\n', '\n\n\n')
open(p, 'wb').write(new.encode(enc))
print('行内 %d，行间 %d，落单 %d' % (stats['inline'], stats['block'], stats['stray']))
print('剩余孤行 $$：', sum(1 for l in new.split('\n') if l.strip() == D * 2))

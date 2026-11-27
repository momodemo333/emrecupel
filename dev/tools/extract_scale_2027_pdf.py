#!/usr/bin/env python3
"""Extract Recupel 2027 list amounts (FR/NL/EN PDF) with strike-through detection.

Usage: extract27.py file.pdf out.json
For every code: list of amount pairs (ttc, ht) in reading order, each amount
flagged struck=True when a thin horizontal line crosses it.
"""
import re, sys, json
import pdfplumber

CODE = re.compile(r'^\d(?:\.\d{1,2}){1,2}$')
AMT = re.compile(r'^\d{1,3},\d{4}$')

def struck(word, segs):
    mid = (word['top'] + word['bottom']) / 2
    h = word['bottom'] - word['top']
    for (x0, x1, y) in segs:
        if abs(y - mid) <= h * 0.35 and min(x1, word['x1']) - max(x0, word['x0']) >= 0.6 * (word['x1'] - word['x0']):
            return True
    return False

out = {}
order = []
current = None
with pdfplumber.open(sys.argv[1]) as pdf:
    for pno, page in enumerate(pdf.pages, 1):
        segs = []
        for l in page.lines:
            if abs(l['top'] - l['bottom']) < 1.5:
                segs.append((min(l['x0'], l['x1']), max(l['x0'], l['x1']), (l['top'] + l['bottom']) / 2))
        for r in page.rects:
            if r['height'] < 1.5 and r['width'] > 5:
                segs.append((r['x0'], r['x1'], (r['top'] + r['bottom']) / 2))
        for c in page.curves:
            ys = [p[1] for p in c.get('pts', [])]
            if ys and max(ys) - min(ys) < 1.5:
                xs = [p[0] for p in c['pts']]
                segs.append((min(xs), max(xs), sum(ys) / len(ys)))
        words = page.extract_words(keep_blank_chars=False, use_text_flow=False)
        words.sort(key=lambda w: (round(w['top']), w['x0']))
        for w in words:
            t = w['text']
            if CODE.match(t) and w['x0'] < 80:
                current = t
                if current not in out:
                    out[current] = {'page': pno, 'amounts': []}
                    order.append(current)
            elif AMT.match(t) and current is not None:
                out[current]['amounts'].append({'v': float(t.replace(',', '.')), 'x': round(w['x0']), 'y': round(w['top']), 'page': pno, 'struck': struck(w, segs)})
json.dump({'order': order, 'codes': out}, open(sys.argv[2], 'w'), indent=1)
print(len(order), 'codes')

#!/usr/bin/env python3
"""Compare data/recupel-scale-2026.csv and -2027.csv with the official PDFs.

Usage: check_scale.py <pdfdir> <jsondir> <csvdir>
"""
import re, sys, json, csv
import pdfplumber

pdfdir, jsondir, csvdir = sys.argv[1:4]
CODE = re.compile(r'^\d(?:\.\d{1,2}){1,2}$')
AMT = re.compile(r'^\d{1,3},\d{4}$')


def parse26(path):
    """One-page 2026 list: two columns; amounts (TTC, HT) on the code row or nearby."""
    res = {}
    with pdfplumber.open(path) as pdf:
        page = pdf.pages[0]
        words = page.extract_words()
        mid = page.width / 2
        codes = [w for w in words if CODE.match(w['text'])]
        amts = [w for w in words if AMT.match(w['text'].lstrip('€'))]
        for c in codes:
            col = 0 if c['x0'] < mid else 1
            cand = [a for a in amts if (0 if a['x0'] < mid else 1) == col and abs(a['top'] - c['top']) < 14]
            # the pair nearest vertically, sorted left to right
            if not cand:
                continue
            best = min(abs(a['top'] - c['top']) for a in cand)
            row = sorted([a for a in cand if abs(abs(a['top'] - c['top']) - best) < 2], key=lambda a: a['x0'])
            if len(row) >= 2:
                res[c['text']] = (float(row[0]['text'].lstrip('€').replace(',', '.')), float(row[1]['text'].lstrip('€').replace(',', '.')))
    return res


def load_csv(path):
    rows = {}
    for r in csv.reader((l for l in open(path, encoding='utf-8') if not l.startswith('#')), delimiter=';'):
        if r[0] == 'code' or not r[7]:
            continue
        rows[r[0]] = {'ht': float(r[7]), 'ttc': float(r[8]), 'fee': r[3], 'use': r[2]}
    return rows


c26 = load_csv(csvdir + '/recupel-scale-2026.csv')
c27 = load_csv(csvdir + '/recupel-scale-2027.csv')
codes = sorted(set(c26) | set(c27), key=lambda c: [int(x) for x in c.split('.')])
report = {'diff': [], 'notes': []}

p26 = {lang: parse26(f'{pdfdir}/{lang}26.pdf') for lang in ('fr', 'nl')}
for lang, d in p26.items():
    report['notes'].append(f'{lang}26.pdf: {len(d)} codes with amounts found')

for lang in ('fr', 'nl', 'en'):
    d = json.load(open(f'{jsondir}/{lang}27.json'))['codes']
    n_ok27 = n_ok26 = 0
    for c in codes:
        a = d.get(c, {}).get('amounts', [])
        live = [x for x in a if not x['struck']]
        dead = [x for x in a if x['struck']]
        if len(live) != 2 or len(dead) not in (0, 2):
            report['diff'].append(f'{lang}27 {c}: unexpected amounts {[(x["v"], x["struck"]) for x in a]}')
            continue
        new = (live[0]['v'], live[1]['v'])
        old = (dead[0]['v'], dead[1]['v']) if dead else new
        e27 = (c27[c]['ttc'], c27[c]['ht'])
        e26 = (c26[c]['ttc'], c26[c]['ht'])
        if max(abs(new[0] - e27[0]), abs(new[1] - e27[1])) > 1e-6:
            report['diff'].append(f'{lang}27 {c}: PDF 2027 {new} != CSV 2027 {e27}')
        else:
            n_ok27 += 1
        if max(abs(old[0] - e26[0]), abs(old[1] - e26[1])) > 1e-6:
            report['diff'].append(f'{lang}27 {c}: PDF 2027 struck (old) {old} != CSV 2026 {e26}')
        else:
            n_ok26 += 1
    report['notes'].append(f'{lang}27.pdf: 2027 amounts equal for {n_ok27}/{len(codes)} codes; struck (2026) amounts equal to CSV 2026 for {n_ok26}/{len(codes)}')

for lang, d in p26.items():
    ok = 0
    missing = []
    for c in codes:
        if c not in d:
            missing.append(c)
            continue
        e = (c26[c]['ttc'], c26[c]['ht'])
        if max(abs(d[c][0] - e[0]), abs(d[c][1] - e[1])) > 1e-6:
            report['diff'].append(f'{lang}26 {c}: PDF 2026 {d[c]} != CSV 2026 {e}')
        else:
            ok += 1
    report['notes'].append(f'{lang}26.pdf: 2026 amounts equal for {ok}/{len(codes)} codes; not located: {missing}')

# Internal consistency: TTC = HT x 1.21 (HT rounded to 4 decimals)
for y, data in (('2026', c26), ('2027', c27)):
    bad = [c for c in codes if abs(round(data[c]['ttc'] / 1.21, 4) - data[c]['ht']) > 0.00011]
    report['notes'].append(f'CSV {y}: {len(data)} codes, HT = round(TTC/1.21, 4) for all but {bad}')

print(json.dumps(report, indent=1, ensure_ascii=False))

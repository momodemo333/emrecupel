#!/usr/bin/env python3
"""Build data/recupel-scale-YYYY.csv files.

Inputs: a Markdown table transcribed from the official Recupel appliance lists
(columns: code | label FR | label NL | use | TTC year1 | HT year1 | TTC year2 |
HT year2 | type), and the text of the official EN list (pdftotext -layout) for
the EN labels.

Usage: build_scale_csv.py <table.md> <en.txt> <outdir>
After generation, run dev/tools/check_scale_pdf.py against the official PDFs
(see docs/SCALE-VERIFICATION.md).
"""
import re, sys, csv

table, entxt, outdir = sys.argv[1:4]
rows = []
for line in open(table, encoding='utf-8'):
    m = re.match(r'^\| (\d(?:\.\d+){1,2}) \| (.+?) \| (.+?) \| (ménager|pro) \| ([\d,]+) \| ([\d,]+) \| ([\d,=]+) \| ([\d,=]+) \| (.+?) \|', line)
    if not m:
        continue
    code, fr, nl, use, t26, h26, t27, h27, typ = m.groups()
    fr = re.sub(r'\s*\(2026 : .*?\)', '', fr).strip()
    num = lambda s: s.replace(',', '.')
    t27 = t26 if t27 == '=' else t27
    h27 = h26 if h27 == '=' else h27
    typ = typ.replace('*', '')
    f26, f27 = ('admin', 'allin') if 'A→T' in typ else (('admin', 'admin') if typ.strip() == 'A' else ('allin', 'allin'))
    rows.append(dict(code=code, fr=fr, nl=nl, use='professional' if use == 'pro' else 'household',
                     t26=num(t26), h26=num(h26), t27=num(t27), h27=num(h27), f26=f26, f27=f27))

en = {}
for line in open(entxt, encoding='utf-8'):
    m = re.match(r'^\s*\x0c?\s*(\d(?:\.\d+){1,2})\s+(\S.*)$', line.replace('\x0c', ''))
    if not m:
        continue
    code, label = m.group(1), m.group(2).strip()
    label = re.sub(r'5 0 c m', '50 cm', label)
    parts = re.split(r'(?<=cm)(?=[A-Z])', label)  # "old titlenew title" -> new
    label = parts[-1].strip()
    if code not in en and re.search(r'[a-z]', label):
        en[code] = label

manual_en = {
    # These codes collide with section titles of the PDF (Exceptions, Notes,
    # Structure of the appliance list, Dimensions): set them by hand.
    '1.2': 'Oil-filled radiators',
    '1.3': 'Large white goods with heat pump',
    '3.1': 'Gas discharge lamps',
    '3.2': 'Retrofit (replaceable) LED lamps',
    '1.1': 'Temperature exchange equipment for cooling (plug-in, with compressor or absorption system)',
    '1.50': 'Temperature exchange equipment exclusively for professional use (plug-in or not, with compressor or absorption system)',
    '4.3.2': 'Ventilation, air treatment, heating and water treatment equipment (except category 1) > 50 cm',
    '4.7': 'Electr(on)ic tools, garden tools, aquarium and pond accessories, (garden) measuring and control instruments > 50 cm',
    '4.50': 'Large white goods, textile, catering and care equipment (non-medical) exclusively for professional use > 50 cm',
    '4.51': 'Cleaning, ironing and textile processing equipment exclusively for professional use > 50 cm',
    '4.52': 'Ventilation, air treatment, heating and water treatment equipment (except cat. 1) exclusively for professional use > 50 cm',
    '4.55': 'Uninterruptible power supplies (UPS) and power converters exclusively for professional use > 50 cm',
    '4.57': 'Electr(on)ic tools, garden tools, aquarium and pond accessories and automotive test and measuring equipment exclusively for professional use > 50 cm',
    '4.58': 'Medical, veterinary, livestock and laboratory equipment exclusively for professional use > 50 cm',
    '5.3.2': 'Ventilation, air treatment, heating and water treatment equipment (except category 1) ≤ 50 cm',
    '5.7': 'Electr(on)ic tools, garden tools, aquarium and pond accessories, (garden) measuring and control instruments ≤ 50 cm',
    '5.50': 'Large white goods, textile, catering and care equipment (non-medical) exclusively for professional use ≤ 50 cm',
    '5.51': 'Cleaning, ironing and textile processing equipment exclusively for professional use ≤ 50 cm',
    '5.52': 'Ventilation, air treatment, heating and water treatment equipment (except cat. 1) exclusively for professional use ≤ 50 cm',
    '5.55': 'Uninterruptible power supplies (UPS) and power converters exclusively for professional use ≤ 50 cm',
    '5.57': 'Electr(on)ic tools, garden tools, aquarium and pond accessories and automotive test and measuring equipment exclusively for professional use ≤ 50 cm',
    '5.58': 'Medical, veterinary, livestock and laboratory equipment exclusively for professional use ≤ 50 cm',
    '6.51': '(Fire) alarm systems, access control and intercom systems exclusively for professional use ≤ 50 cm',
}
en.update(manual_en)

parents = [
    ('1', 'Équipements d\'échange thermique', 'Temperatuuruitwisselingsapparatuur', 'Temperature exchange equipment'),
    ('2', 'Écrans, moniteurs et équipements comprenant des écrans d\'une surface > 100 cm²', 'Schermen, monitoren en apparatuur met schermen met een oppervlakte > 100 cm²', 'Screens, monitors and equipment containing screens with a surface area > 100 cm²'),
    ('3', 'Lampes', 'Lampen', 'Lamps'),
    ('4', 'Gros appareils (une dimension extérieure > 50 cm)', 'Grote apparatuur (een buitenafmeting > 50 cm)', 'Large equipment (any external dimension > 50 cm)'),
    ('5', 'Petits appareils (aucune dimension extérieure > 50 cm)', 'Kleine apparatuur (geen buitenafmeting > 50 cm)', 'Small equipment (no external dimension > 50 cm)'),
    ('6', 'Petits équipements informatiques et de télécommunications (≤ 50 cm)', 'Kleine IT- en telecommunicatieapparatuur (≤ 50 cm)', 'Small IT and telecommunication equipment (≤ 50 cm)'),
]

assert len(rows) == 92, len(rows)
missing = [r['code'] for r in rows if r['code'] not in en]
assert not missing, missing

hdr = ['code', 'parent_code', 'use_type', 'fee_type', 'label_fr', 'label_nl', 'label_en', 'amount_ht', 'amount_ttc', 'date_start', 'date_end', 'source']
for year, src in (('2026', 'Recupel appliance list 2026 - https://www.recupel.be/fr/media/2246'),
                  ('2027', 'Recupel appliance list 2027 - https://www.recupel.be/fr/media/2411')):
    with open(f'{outdir}/recupel-scale-{year}.csv', 'w', encoding='utf-8', newline='') as f:
        f.write(f'# Recupel official scale valid from {year}-01-01. Source: {src}\n')
        f.write('# Amounts per appliance; amount_ttc = official VAT-inclusive amount (21 %).\n')
        w = csv.writer(f, delimiter=';', quoting=csv.QUOTE_MINIMAL, lineterminator='\n')
        w.writerow(hdr)
        for code, fr, nl, en_ in parents:
            w.writerow([code, '', '', '', fr, nl, en_, '', '', '', '', ''])
        for r in rows:
            y = year[2:]
            w.writerow([r['code'], r['code'].split('.')[0], r['use'], r['f' + y], r['fr'], r['nl'], en[r['code']],
                        r['h' + y], r['t' + y], f'{year}-01-01', '', src])
print('ok', len(rows))

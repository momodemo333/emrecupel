# Recupel scales 2026 and 2027 — verification / Contrôle des barèmes

**Date:** 27 November 2026 — **Result: no difference** between the bundled
scales (`data/recupel-scale-2026.csv`, `data/recupel-scale-2027.csv`) and the
official Recupel appliance lists, for all 92 codes, amounts incl. VAT and
excl. VAT.

## Sources (downloaded on 27/11/2026)

| File | URL | SHA-256 |
|---|---|---|
| List 2026 FR | https://www.recupel.be/fr/media/2246 | `b95fbed9bfb301958c707afeeaacd91c1b98f01c9cbbee927dea926399441576` |
| List 2026 NL | https://www.recupel.be/nl/media/2245 | `049af64cd03860ab287f3e035b80e55f5b57bde774d6197ffc62a0ab0dff88b6` |
| List 2027 FR | https://www.recupel.be/fr/media/2411 | `2eb1753c6fa6fcb488b1c5a3a2a3c13292f72a8c61faf8de348ab7ea9b7bd443` |
| List 2027 NL | https://www.recupel.be/nl/media/2409 | `0bf77f8b23b406fc23e9c25fd3bbe303ed1feaf41c86149b665d118f61e8ca8f` |
| List 2027 EN | https://www.recupel.be/en/media/2410 | `973653e1caca5eb9d28beed7bd855fb84f0f092ab78a1685a61330c7612b6023` |
| Change 2027 | https://www.recupel.be/en/place-appliances-market/what-recupel-contribution/change-recupel-contribution | (web page) |

## Method

- The 2027 lists show, for each code, either one pair of amounts (unchanged)
  or the 2026 pair **struck through** followed by the 2027 pair.
  `dev/tools/extract_scale_2027_pdf.py` (pdfplumber) reads every amount with its
  position and detects the strike-through line drawn over it; a page was also
  checked visually (old amounts struck in red, new ones underlined in green).
- `dev/tools/check_scale_pdf.py` compares, code by code:
  - the 2027 amounts (not struck) of the FR, NL and EN lists with the 2027 CSV;
  - the struck 2026 amounts of the three 2027 lists with the 2026 CSV;
  - the one-page 2026 lists (FR and NL) with the 2026 CSV;
  - TTC / HT consistency (HT = TTC / 1.21 rounded to 4 decimals).
- The fee type (all-in / administrative) of each 2027 code was compared with
  the text of the FR list.

## Results

| Check | Result |
|---|---|
| 2027 amounts — FR / NL / EN lists vs CSV 2027 | 92/92, 92/92, 92/92 |
| 2026 amounts struck in the 2027 lists — FR / NL / EN vs CSV 2026 | 92/92, 92/92, 92/92 |
| 2026 lists — NL / FR vs CSV 2026 | 92/92 ; 91/92 (5.51 not located by the parser because of the two-column layout; confirmed by the NL list and by the three 2027 lists) |
| HT = round(TTC / 1.21, 4) | 92/92 for each year |
| Fee type 2027 | 91/92 identical; see 1.50 below |

**Code 1.50 in 2027.** The 2027 PDF still prints the heading « Appareils
professionnels, avec une cotisation administrative » above the 2027 amount of
€ 13,00 incl. VAT. The official change page states: *"From 1 January 2027,
professional temperature exchange equipment will be subject to an all-in
contribution similar to household appliances […] the contribution for
categories 1.50 and 1.1 amounts to € 13,00 incl. VAT (€ 10,7438 excl. VAT)."*
The module therefore keeps 1.50 as **all-in** in 2027 (it is a professional
code with an all-in fee). No change to the CSV.

Unit tests (`tests/Unit/ScaleCsvTest.php`) keep checking the CSV files against
`tests/Fixtures/recupel-reference.php` (92 codes × 4 amounts and fee types).

---

**FR — résumé.** Contrôle du 27/11/2026 : les 92 codes des barèmes 2026 et 2027
du module (TVAC et HTVA) sont identiques aux listes officielles Recupel FR, NL et
EN ; les montants 2026 barrés dans les listes 2027 concordent aussi. Seul point :
le PDF 2027 laisse l'intitulé « cotisation administrative » au-dessus du code
1.50, alors que la page officielle du changement 2027 indique une cotisation
tout compris de 13,00 € TVAC ; le module applique la page officielle.

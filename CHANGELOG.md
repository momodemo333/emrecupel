# Changelog — emRecupel

Toutes les modifications notables seront documentées ici.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/),
et ce projet adhère au [Semantic Versioning](https://semver.org/).

## [2.0.1] - 2026-11-27

### Fixed
- Declaration report: the estimated amount of each code was the sum of amounts
  rounded to 2 decimals **per invoice line**, which drifted from the number of
  appliances × the rate (example: 5 invoices of 4 laptops, code 2.4 at
  0,1240 € excl. VAT → 2,50 € instead of 20 × 0,1240 = 2,48 €). Amounts are now
  stored with 8 decimals and rounded once, on the total. The invoice detail
  shows them with up to 4 decimals.
- The fee lines of documents are not affected (they are priced per line by
  Dolibarr, as any invoice line).

### Upgrade notes
Replace the files: the 2.0.1 migration runs by itself on the next page load and
recomputes the amounts already stored in the declaration ledger
(`amount_ht = rate × units`); appliance counts and weights do not change.

## [2.0.0] - 2026-11-27

Refonte complète du prototype 1.x. Version majeure : modèle de données,
règles d'affichage et rapport changent.

### Added
- **Barèmes officiels 2026 et 2027** (92 codes, libellés FR/NL/EN, montants
  HTVA et TVAC), datés : la cotisation suit la date du document (bascule
  automatique au 01/01/2027). Le code 1.50 passe d'une cotisation
  administrative à une cotisation tout compris en 2027 : le type de cotisation
  est porté par le tarif, l'usage (ménager/professionnel) par le code.
- Écran **Barème** (tarifs à une date, historique par code) et **import CSV
  d'un nouveau barème** avec contrôles (nombre de codes, codes nouveaux ou
  disparus, variations > 50 %). Un nouveau tarif ferme le précédent la veille
  de sa date de début, sans rien effacer.
- **Assistant de bascule** : codes qui changent, produits du catalogue dont la
  cotisation change, devis et commandes ouverts datés d'avant la bascule.
- **Plusieurs codes par produit avec une quantité** (luminaire + lampes, kits),
  statut « non soumis », origine (importé/produit, acheté en Belgique,
  occasion belge), poids déclarable ; onglet Recupel de la fiche produit.
- Écran **Produits à classer** (filtres statut, catégorie Dolibarr, vendus
  cette année, affectation en masse) et **import/export CSV**
  `ref;code;quantité;origine`.
- **Tous les documents de vente** : devis, commandes, factures, avoirs, factures
  de remplacement, clones, factures modèles (récurrentes). Le lien entre un
  produit et sa ligne de cotisation est un extrafield de ligne que Dolibarr
  recopie à chaque conversion : aucun doublon.
- **Politique d'affichage** selon la règle Recupel (FAQ 2.13) : ligne séparée
  imposée seulement pour une cotisation tout compris vendue à un revendeur
  d'appareils électriques ; ailleurs, au choix : ligne séparée, « incluse »
  avec mention sous la ligne et total en pied, ou total en pied seulement.
  Rôle (utilisateur final / revendeur / exempté) et surcharge d'affichage par
  tiers.
- **Mentions PDF** par hook (`beforePDFCreation`), compatibles avec les modèles
  standards, sans modèle PDF propre.
- **Pas de cotisation hors Belgique** (pays du contact de livraison, sinon du
  client) ; appareil d'occasion belge jamais facturé.
- **TVA** : la ligne de cotisation reprend le code TVA complet de la ligne
  produit (taux, code, taxes locales, NPR) ; option base TVAC pour obtenir le
  montant rond officiel (10,00 € et non 9,99 €).
- Service **RECUPEL** créé automatiquement pour les lignes de cotisation
  (libellés traduits, compte comptable paramétrable).
- **Journal déclaratif** (`llx_emrecupel_ledger`) écrit à la validation de
  chaque facture et retiré si elle repasse en brouillon.
- **Rapport de déclaration** : mois / trimestre / année, blocs ménager et
  professionnel, « mis sur le marché » (nombre, poids pour le pro),
  « Returnable » (exports d'appareils achetés en Belgique), montant estimé,
  plafond de 10 000 € de cotisations administratives, échéance du 20 du mois
  suivant, détail jusqu'aux factures, export CSV, reconstruction d'une période.
  **Profil d'entreprise** (revendeur / revendeur qui exporte / importateur ou
  fabricant) : un revendeur pur voit un état de contrôle, pas une déclaration.
- Onglet Recupel des devis, commandes et factures : analyse ligne par ligne,
  bouton « Recalculer ».
- Migrations versionnées (`EmrecupelMigrations`), relancées automatiquement
  après une mise à jour de fichiers (hook `printCommonFooter`) ; page de
  configuration avec diagnostic de la base et réparation.
- Tests : 246 tests unitaires PHPUnit (dont contrôle des 92 codes contre une
  référence indépendante) et un test d'intégration CLI de 82 vérifications.

### Changed
- Tables `llx_emrecupel_cat`, `llx_emrecupel_rate`, `llx_emrecupel_product`,
  `llx_emrecupel_product_code`, `llx_emrecupel_ledger` (les tables 1.x ne sont
  plus utilisées).
- Lignes de cotisation : ligne de service ordinaire (`special_code` 0) portant
  le service RECUPEL, reconnue par ses extrafields et non plus par son libellé.
- Paquet construit sur liste blanche (`make build-release`), sans `vendor/`,
  `dev/`, `tests/`.

### Removed
- Extrafields produit 1.x `emrecupel_category`, `emrecupel_use_type`,
  `emrecupel_exempt` (désactivés, valeurs conservées et reprises).
- Pages 1.x `admin/categories.php`, `admin/tariffs.php`, `produit/recupel.php`,
  `report/declaration.php`, onglet facture 1.x.

### Fixed
- Règle d'affichage 1.x fondée sur « société / particulier » au lieu de
  « revendeur / utilisateur final × ménager / professionnel ».
- Doublons de cotisation au clonage, à la conversion et aux avoirs ; signe
  des avoirs ; cotisation appliquée aux clients étrangers ; arrondi TVAC.
- Rapport 1.x : brouillons comptés, pas d'origine, pas d'export, pas de poids.
- Libellés EN de 1.2, 1.3, 3.1 et 3.2 (titres de sections du PDF).
- Barèmes contrôlés le 27/11/2026 contre les listes officielles Recupel 2026
  et 2027 (FR, NL, EN) : 92 codes, montants TVAC et HTVA, aucun écart
  (voir `docs/SCALE-VERIFICATION.md`).
- Revue de code avant livraison : retrait manuel mémorisé par code (un
  produit à plusieurs codes garde ses autres cotisations) ; quantité négative
  sur une facture standard = cotisation négative ; avoir et facture de
  remplacement au tarif de la facture source ; avoir resté négatif avec
  `FACTURE_ENABLE_NEGATIVE_LINES` ; contact de livraison ajouté ou retiré suivi ;
  réconciliations différées rejouées avant la fermeture de la base (action de
  masse « Facturer ») ; « Recalculer », « Reconstruire » et « Réparer » en POST
  avec jeton, droit de modifier le document exigé ; reprise 1.x étendue aux
  factures modèles et aux lignes non journalisées, codes 1.x non assignables
  ignorés ; journal sans code parent ni code sans tarif, écrit en transaction ;
  migrations automatiques suspendues après 3 échecs ; remboursement
  « Returnable » limité aux cotisations tout compris ; protection des exports
  CSV contre les formules.

### Upgrade notes (depuis 1.0.1)
1. Remplacer les fichiers du module, puis **désactiver et réactiver le module**
   une fois (Configuration › Modules) : la 1.0.1 n'enregistrait pas le contexte
   de hook `all` nécessaire aux migrations automatiques.
2. À la réactivation, les migrations créent les nouvelles tables et reprennent
   les données 1.x sans rien supprimer : produits classés (code et
   « exonéré » → « non soumis »), lignes Recupel 1.x des factures adoptées
   (pas de seconde ligne sur les brouillons), profil « importateur » si
   `EMRECUPEL_PROVIDER_MEMBER` valait 1. Les tables 1.x
   (`llx_emrecupel_category`, `llx_emrecupel_tariff`,
   `llx_emrecupel_invoice_log`) restent en place et peuvent être supprimées à
   la main après contrôle.
3. Vérifier le **profil**, l'**origine par défaut** et la **matrice
   d'affichage** (Configuration du module), puis classer l'**origine** des
   produits importés.
4. Rapport : cliquer « Reconstruire la période » pour les factures validées
   avant la mise à jour.

## [1.0.1] - 2026-04-28

### Fixed (critical)
- **Recupel line was never updated when modifying a product line** (qty / VAT / price change). The trigger was listening for `LINEBILL_UPDATE` but Dolibarr fires `LINEBILL_MODIFY` on `updateline()` — fixed by switching to the correct trigger name.
- **"Recalculate Recupel" button now correctly handles category changes**: instead of skipping lines with an existing log, it now delegates to `LINEBILL_MODIFY` for every product line, so a product re-categorization (or exemption toggle) is properly reflected.

### Added
- **`LINEBILL_MODIFY` trigger handler** — Recupel line is now automatically kept in sync when the source product line is modified (quantity, VAT rate, product change).
- Lazy creation: if a product is categorized after the invoice line was added, modifying the line now creates the missing Recupel line.
- Auto-removal: if a product is exempted or its category cleared, modifying the line now removes the orphan Recupel line.
- New methods on `EmrecupelInvoiceLog`: `findForSourceLine()` (read full log entry) and `updateById()` (partial update).

### Changed
- Refactored the trigger to share the compute/create/update/drop logic between INSERT and MODIFY handlers (single source of truth).
- Hardened `LINEBILL_DELETE`: uses `findForSourceLine()` for cleaner lookup, explicit anti-recursion guard against the module's own Recupel lines.
- `Facture::updateline()` is called with `notrigger=1` to prevent re-firing `LINEBILL_MODIFY` on the Recupel line itself (avoids cycles).

### Fixed
- SQL `.key.sql` files now use `DROP INDEX IF EXISTS` before `ADD INDEX` to make module re-activation silent (no more "Duplicate key name" warnings in the log).
- B2C detection improved: now considers both `typent_code` (preferred) and `tva_assuj` (fallback) to identify private customers.

### Documentation
- USER-GUIDE: added "How does the module detect a B2C customer?" section
- FAQ: clarified B2C detection mechanism

## [1.0.0] - 2026-04-28

### Added
- Initial public release
- 75 Recupel categories preloaded (compliant with EU 2012/19/EU directive)
- Full 2026 official tariffs (in force since 2026-01-01)
- 3-step welcome wizard
- Auto-add Recupel line on invoice line creation (`LINEBILL_INSERT` trigger)
- Idempotency: prevents duplicate Recupel lines for the same source line
- Cleanup on `LINEBILL_DELETE` and `BILL_DELETE`
- B2B / B2C distinction (configurable per side)
- VAT applied at the product's rate (compliant with CNC opinion 144)
- Customizable line labels (FR / EN / NL) with `{CODE}` placeholder
- "Recalculate Recupel" button on invoice card (draft only)
- Tab "Recupel" on product card with category selector and current tariff
- Tab "Recupel" on invoice card with detailed log view
- Quarterly declaration report (current/previous quarter, year, custom)
- CSV export of the declaration report
- Categories browser admin page (75 categories)
- Tariffs browser admin page (current + history per category)
- About page (version, author, legal disclaimer, data sources)
- Multilingual: French, English, Dutch
- Multicompany compatible
- 4 permissions: read, write, setup, report
- Two left-menu entries: declaration report + categories browser
- GPL-3.0-or-later license

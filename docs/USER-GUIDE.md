# emRecupel 2.0 — Guide utilisateur

> emRecupel aide à facturer et à déclarer la cotisation Recupel ; il ne
> remplace pas votre vérification. Le classement des produits, leur origine et
> les chiffres déclarés restent sous votre responsabilité. Vérifiez les codes
> dans la liste officielle et relisez le rapport avant de déclarer.

## 1. Mise en route (10 minutes)

### 1.1 Profil de l'entreprise

*Configuration › Modules › emRecupel › Configuration*, ou *Produits › Recupel ›
Configuration*.

| Profil | Votre situation | Ce que montre le rapport |
|---|---|---|
| Revendeur | Vous achetez vos appareils chez des fournisseurs belges et vous les vendez en Belgique. Vous répercutez la cotisation, vous ne déclarez rien. | Un état de contrôle des cotisations répercutées. |
| Revendeur qui exporte | Idem, mais vous êtes affilié à Recupel pour vous faire rembourser la cotisation des appareils vendus à l'étranger. | La colonne « Returnable ». |
| Importateur ou fabricant | Vous êtes membre Recupel : vous déclarez les appareils importés ou produits mis sur le marché belge. | « Mis sur le marché » et « Returnable ». |

L'**origine par défaut** des produits découle du profil (« acheté en Belgique »
pour un revendeur, « importé ou produit » pour un importateur). Une entreprise
mixte précise l'origine produit par produit (§ 2).

### 1.2 Affichage de la cotisation

La règle Recupel (FAQ 2.13) :

- appareil à **cotisation tout compris** (ménager, et le code 1.50 à partir de
  2027) vendu à un **revendeur d'appareils électriques** : la cotisation figure
  séparément, appareil par appareil. Le module impose alors une **ligne
  séparée** ;
- dans tous les autres cas (utilisateur final, entreprise ou particulier ;
  appareil professionnel), la mention « cotisation Recupel incluse » suffit ;
  une ligne séparée reste permise.

Le critère n'est pas « société ou particulier » : une PME qui achète un PC pour
son usage est un utilisateur final.

Trois rendus, réglables pour chaque case de la matrice :

- **Ligne séparée** : une ligne « Cotisation Recupel — Cat. 1.1 — … » juste
  sous le produit, même quantité, même TVA ; elle s'ajoute au total.
- **Incluse (mention sous la ligne + total en pied)** : votre prix contient déjà
  la cotisation. Le PDF écrit sous le produit « Dont cotisation Recupel :
  10,00 € TVAC/pièce (cat. 1.1) » et en pied « Cotisations Recupel incluses dans
  les prix : x,xx € TVAC ». Rien n'est ajouté au total.
- **Incluse (total en pied seulement)**.

Hors Belgique, aucune cotisation n'est due : rien n'est ajouté. Le pays retenu
est celui du **contact de livraison** du document, sinon celui du client.

### 1.3 Rôle des clients

Fiche tiers, champs :

- **Rôle Recupel** : *Utilisateur final* (par défaut), *Revendeur d'appareils
  électriques*, *Exempté de cotisation* (par exemple un membre Recupel qui
  exporte lui-même) ;
- **Affichage de la cotisation Recupel** : force un rendu pour ce client
  (vide = règle de la configuration). La ligne séparée obligatoire d'un
  revendeur ne peut pas être retirée.

### 1.4 Autres réglages

- Documents concernés : devis, commandes, factures et avoirs (cochés par défaut).
- **Lignes en base TVAC** (recommandé) : le barème officiel publie un montant
  rond TVAC ; la ligne est construite depuis ce montant pour que le client paie
  10,00 € et non 9,99 €. Pour une ligne à un autre taux que 21 %, la ligne part
  du montant HTVA et suit le taux du produit (avis CNC 2009/9 : la TVA de la
  cotisation suit celle du bien).
- **Service des lignes de cotisation** : le service RECUPEL est créé à
  l'activation. Réglez son compte comptable (sous-compte du 70) sur sa fiche.
- Description de la ligne par langue : variables `{CODE}`, `{LABEL}`,
  `{AMOUNT}`, `{UNITS}`.

## 2. Classer les produits

### 2.1 Onglet Recupel d'un produit

- **Statut** : *À classer*, *Soumis*, *Non soumis* (accessoire fourni avec un
  appareil, composant sans fonction propre, clé USB, batterie domestique,
  panneau solaire, service…).
- **Origine** : *importé ou produit* (vous le déclarez), *acheté en Belgique*
  (votre fournisseur vous a facturé la cotisation), *occasion belge* (déjà mis
  sur le marché belge : facturer la cotisation est interdit ; le PDF indique
  « cotisation Recupel incluse »).
- **Poids déclarable** : pour les appareils professionnels (produit +
  accessoires, câbles compris, sans emballage, manuel ni batteries). Vide = poids
  de la fiche produit.
- **Codes** : un ou plusieurs, avec le nombre d'appareils par unité vendue.
  Exemples : luminaire avec 4 lampes LED = armature 5.6 ×1 + lampe 3.2 ×4 ;
  kit de vidéosurveillance = moniteur, enregistreur et chaque caméra.
  L'onglet affiche le tarif du jour et celui du prochain barème.

Le lien « Moteur de recherche officiel Recupel » ouvre la recherche par
appareil de recupel.be. Le module ne classe jamais rien automatiquement.

Une **variante** sans classement propre reprend celui de son produit parent.

### 2.2 Produits à classer

*Produits › Recupel › Produits à classer* : liste filtrable (statut, texte,
catégorie Dolibarr, biens seulement, vendus cette année). Le bandeau signale
les produits vendus cette année et pas encore classés.

Cochez des produits, choisissez l'action (*Affecter le code*, *Marquer « non
soumis »*, *Changer l'origine*, *Remettre « à classer »*) puis *Appliquer*.
Avec le filtre de catégorie, une famille entière se classe en une fois.

### 2.3 Import / export CSV

*Produits › Recupel › Import / export CSV*. Format (UTF-8, `;`) :

```
ref;code;quantity;origin
LAPTOP-14;2.4;1;local
FRIGO-A;1.1;1;import
SPOT-KIT;5.6;1;import
SPOT-KIT;3.2;3;import
CABLE-USB;exempt;;
```

- plusieurs lignes pour une même référence = plusieurs codes ;
- `exempt` (ou `non soumis`) = non soumis ;
- origine : `import`, `local`, `used` (mots FR/NL acceptés), vide = défaut ;
- les codes du fichier remplacent ceux du produit.

*Contrôler* vérifie le fichier sans rien écrire ; *Importer* l'applique.
L'export produit le même format.

## 3. Au quotidien

### 3.1 Documents

Ajoutez vos produits comme d'habitude. Selon le client et le produit, la
ligne de cotisation apparaît sous le produit, ou rien n'est ajouté et le PDF
porte la mention.

- Changer la quantité met la cotisation à jour ; supprimer le produit supprime
  sa cotisation.
- Supprimer une ligne de cotisation à la main la retire pour ce produit et ce
  code seulement (elle ne revient pas ; les autres codes du produit restent) ;
  le bouton **Recalculer** de l'onglet Recupel du document la rétablit.
- Une ligne produit en quantité négative (reprise) porte une cotisation négative.
- Un avoir ou une facture de remplacement liés à une facture prennent le tarif
  de la date de cette facture : l'avoir rembourse exactement ce qui a été payé.
- Changer la date, le client ou le contact de livraison d'un brouillon recalcule
  la cotisation (tarif à la nouvelle date, ligne retirée pour un client étranger…).
- Devis → commande → facture, clone, avoir avec reprise des lignes, facture de
  remplacement, facture modèle : les lignes de cotisation suivent, sans doublon ;
  sur un avoir elles sont négatives. À la facturation, le tarif est celui de la
  date de la facture (un devis de décembre 2026 facturé en janvier 2027 prend le
  tarif 2027).
- Un document **validé n'est jamais modifié**.

L'onglet **Recupel** d'un devis, d'une commande ou d'une facture détaille,
produit par produit : code, tarif, décision (due, hors Belgique, occasion,
retirée à la main…) et rendu. Pour une facture validée, il montre les appareils
enregistrés pour la déclaration.

Acomptes et factures de situation ne portent pas de cotisation.

### 3.2 Barème et bascule annuelle

*Produits › Recupel › Barème* : tarifs à une date, colonne « barème précédent »,
historique d'un code (clic sur le code).

Recupel change son barème au 1er janvier ou au 1er juillet, avec six mois de
préavis. Le module embarque 2026 et 2027. Pour un barème suivant, en attendant
une mise à jour du module, *Importer un barème* (CSV, format décrit à l'écran) :
le contrôle annonce le nombre de codes, les codes nouveaux ou absents et les
variations de plus de 50 % ; l'import est refusé en entier à la moindre erreur.

*Produits › Recupel › Assistant de bascule* : codes qui changent, produits du
catalogue dont la cotisation change, devis et commandes ouverts datés d'avant
la bascule. En 2027, les lave-vaisselle passent de 4.1.2 à 4.1.1 : l'assistant
les signale.

## 4. Déclaration

*Facturation | Paiement › Déclaration Recupel*.

- Période : mois, trimestre (par défaut) ou année. Échéance : le 20 du mois qui
  suit la période.
- Deux blocs, **ménager** et **professionnel**, par code :
  - **Mis sur le marché** : appareils importés ou produits, vendus en Belgique
    (avec le **poids** pour le professionnel) ;
  - **Returnable** : appareils achetés en Belgique (cotisation payée au
    fournisseur) et vendus hors de Belgique ;
  - **Achetés et vendus en Belgique** : cotisation répercutée, rien à déclarer
    (contrôle).
- Montant estimé au tarif de la date de chaque facture ; le remboursement
  « Returnable » ne compte que les cotisations tout compris (la cotisation
  administrative n'est pas récupérable) ; alerte si les
  cotisations administratives de l'année dépassent le plafond de 10 000 €.
- Un clic sur un nombre ouvre le détail jusqu'aux factures. *Exporter en CSV*.

Hypothèses (rappelées à l'écran) : factures validées seulement ; la facture
remplacée est exclue au profit de la facture de remplacement ; avoirs déduits ;
codes et origine figés à la validation de chaque facture. Après un classement
tardif, *Reconstruire la période* relit les factures avec le classement actuel.

La déclaration se saisit sur https://declarations.recupel.be.

## 5. Droits

| Droit | Permet |
|---|---|
| Consulter les données Recupel | barème, onglets, assistant |
| Classer les produits et recalculer | onglet produit, produits à classer, import, « Recalculer » |
| Configurer et importer des barèmes | configuration, import de barème |
| Consulter le rapport de déclaration | rapport, export CSV |

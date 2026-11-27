# emRecupel — Installation et mise à jour

## Prérequis

- Dolibarr 21 ou plus récent (testé sur 23.0.1 et 21.0.1) ;
- PHP 8.1 ou plus récent (testé en 8.1 et 8.2) ; MySQL ou MariaDB (PostgreSQL non pris en charge) ;
- modules Factures, Tiers et Produits activés (Propositions commerciales et
  Commandes si vous voulez la cotisation sur ces documents).

## Installation

1. Télécharger `module_emrecupel-2.0.0.zip` depuis
   https://github.com/momodemo333/emrecupel/releases/latest et le décompresser
   dans `htdocs/custom/` : on obtient `htdocs/custom/emrecupel/`. Autre voie :
   `git clone https://github.com/momodemo333/emrecupel.git htdocs/custom/emrecupel`. (Ou *Configuration › Modules › Déployer un
   module externe*.) Rien n'est à compiler ni à installer en plus.
2. *Configuration › Modules › Modules financiers* : activer **emRecupel**.
   L'activation crée les tables, les champs (rôle et affichage sur la fiche
   tiers, champs techniques invisibles sur les lignes), le service RECUPEL, et
   charge les barèmes 2026 et 2027.
3. Configuration du module : profil de l'entreprise, origine par défaut,
   matrice d'affichage (voir le guide utilisateur).
4. Fiche du service **RECUPEL** : régler le compte comptable de vente.
5. Donner les droits aux utilisateurs (*Utilisateurs › Droits › emRecupel*).
6. Classer les produits (*Produits › Recupel › Produits à classer* ou import CSV).

Multi-société : le barème est commun à toutes les entités ; la configuration,
le classement des produits et le journal suivent l'entité du produit ou de la
facture.

## Mise à jour

Remplacer le dossier `htdocs/custom/emrecupel/` par celui du nouveau zip. Les
migrations de la base se lancent seules au premier affichage d'une page
(diagnostic et bouton « Vérifier et réparer la base » dans la configuration).

### Depuis la 1.0.1 (prototype non publié)

1. Remplacer les fichiers, puis **désactiver et réactiver le module une fois**
   (la 1.0.1 n'enregistrait pas le contexte de hook nécessaire aux migrations
   automatiques).
2. Les migrations reprennent sans rien supprimer :
   - le classement des produits (code 1.x → code 2.0, « exonéré » → « non
     soumis ») ; les anciens champs produit sont désactivés, leurs valeurs gardées ;
   - les lignes Recupel déjà créées sur les factures (adoptées, pas de seconde
     ligne sur les brouillons) ;
   - le profil « importateur » si l'ancienne option « membre Recupel » était
     cochée ; les gabarits de description restés à leur valeur 1.x par défaut.
3. Les tables 1.x (`llx_emrecupel_category`, `llx_emrecupel_tariff`,
   `llx_emrecupel_invoice_log`) restent en place, inutilisées ; vous pouvez les
   supprimer après contrôle.
4. Vérifier le profil, l'origine des produits importés et la matrice
   d'affichage, puis *Déclaration Recupel › Reconstruire la période* pour les
   factures déjà validées.

## Désactivation et désinstallation

Désactiver le module ne supprime ni les tables ni les données ni la
configuration : une réactivation les retrouve. Les lignes de cotisation déjà
présentes sur les documents restent des lignes de service ordinaires.

Pour tout supprimer : désactiver le module, supprimer le dossier, puis les
tables `llx_emrecupel_*`, les constantes `EMRECUPEL_%` et les champs
supplémentaires `emrecupel_*` (Configuration › Tiers / Lignes).

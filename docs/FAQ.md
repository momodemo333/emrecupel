# FAQ — emRecupel

### Le module garantit-il que je suis en règle ?
Non. Il applique les règles publiées par Recupel (liste des appareils 2026 et
2027, FAQ déclarations 2.13, pages « declare appliances » et « retailer ») et
l'avis CNC 2009/9 pour la TVA. Le classement des produits, leur origine et les
chiffres déclarés restent sous votre responsabilité. Certains points ne sont
pas tranchés par Recupel (voir plus bas).

### Je vends un PC à une entreprise : ligne séparée ou « incluse » ?
« Incluse » suffit : l'entreprise est un utilisateur final. La ligne séparée
n'est obligatoire que pour un appareil à cotisation tout compris vendu à un
**revendeur** d'appareils électriques. Indiquez ce rôle sur la fiche du client.

### Pourquoi 9,99 € et pas 10,00 € ?
La ligne a été calculée depuis le montant HTVA. Activez « Lignes de cotisation
en base TVAC » : la ligne part du montant TVAC officiel.

### Mon client est en France ou aux Pays-Bas.
Aucune cotisation n'est ajoutée : l'appareil n'est pas mis sur le marché belge.
Si vous l'aviez acheté en Belgique (cotisation payée), il apparaît dans la
colonne « Returnable » du rapport pour un profil « revendeur qui exporte » ou
« importateur ».

### Un produit contient plusieurs appareils (luminaire et lampes, kit).
Ajoutez un code par type d'appareil, avec la quantité par unité vendue.

### Clé USB, chargeur fourni, câble ?
Une clé USB, une mémoire, un composant sans fonction propre sont non soumis.
Un accessoire n'est soumis que s'il est vendu séparément d'un appareil soumis.
Utilisez le statut « non soumis » et le moteur de recherche de recupel.be.

### Appareil d'occasion ?
Le critère est l'origine. Déjà mis sur le marché belge : origine « occasion
belge », aucune cotisation, le PDF indique « cotisation Recupel incluse ».
Occasion importée : origine « importé », comme un neuf.

### J'ai supprimé une ligne de cotisation par erreur.
Onglet Recupel du document › « Recalculer ».

### Le devis date de 2026 et la facture de 2027.
La facture prend le tarif 2027 (date de la facture). L'assistant de bascule
liste les devis et commandes concernés.

### Et Bebat (piles et batteries) ?
Pas dans la 2.0.0.

### Points que Recupel ne tranche pas (à vérifier avec Recupel ou votre conseil)
- Traitement des notes de crédit pour un retour en Belgique (le module déduit
  l'avoir de la période de l'avoir).
- Montant à afficher pour un stock acheté en 2026 et vendu en 2027 par un
  revendeur (le module applique le tarif de la date du document).
- Existence d'un import de fichier dans le portail de déclaration (le module
  produit un écran et un CSV à recopier).

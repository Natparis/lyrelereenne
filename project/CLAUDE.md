# Consignes projet La Lyre Léréenne
- À chaque ajout/modification de champ membres/élèves/chorale/harmonie : mettre à jour le modèle d'import téléchargeable dans le site (COLS + EX dans impVals, bouton dlModele) ET export-import/modele-import-membres.csv, plus ALIAS/MAP de l'import.
- Après chaque modification du site : recopier Site Lyre Lereenne.dc.html vers export-ovh/www/index.html.
- Toute nouvelle photo : la première chose à faire est d'en réduire le poids (WebP, 1600 px max pour les photos, 400 px pour les portraits, qualité ~0,78), puis référencer le .webp dans le site et le copier dans export-ovh/www/assets. Les envois de photos depuis le site passent par compressImg (WebP).
- Le site doit rester responsive, pensé d'abord pour le mobile (sauf les affiches).
- Vue mobile uniquement (≤ 640 px) : aucune barre de défilement (ascenseur) visible, le défilement reste actif. Ne pas la masquer sur ordinateur.

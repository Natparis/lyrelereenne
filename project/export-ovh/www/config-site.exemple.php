<?php
// RÉGLAGES DU SERVEUR — La Lyre Léréenne
// 1. Copiez ce fichier sous le nom config-site.php (dans le même dossier www).
// 2. Remplacez les valeurs ci-dessous. Ne communiquez jamais ce fichier.

return [
  // Code du serveur : à saisir une fois dans l'administration du site (bandeau « Serveur OVH »).
  // Choisissez au moins 12 caractères, connus seulement du bureau.
  'code_bureau' => 'CHANGEZ-MOI-code-du-bureau',

  // Adresse d'expédition par défaut des e-mails : une adresse de votre domaine OVH.
  'from_email' => 'contact@votre-domaine.fr',
  'from_name'  => 'La Lyre Léréenne',

  // Facultatif : clé API Brevo (brevo.com, gratuit jusqu'à 300 e-mails par jour).
  // Laissez vide pour utiliser l'envoi de l'hébergement OVH.
  'brevo_key'  => '',

  // Nombre maximum de destinataires par envoi.
  'max_dest'   => 400,
];

<?php
// Comptes du bureau : les mots de passe sont vérifiés ici, sur le serveur, et gardés chiffrés.
// POST comptes.php {action: etat | initialiser | connexion | oubli | reinit | liste | enregistrer | journal_vider}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
$cfgFile = __DIR__ . '/config-site.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Fichier config-site.php absent sur le serveur (voir LISEZ-MOI).']);
$cfg = require $cfgFile;
require __DIR__ . '/reglages-lib.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);
$in = json_decode(file_get_contents('php://input'), true);
$action = is_array($in) ? (string)($in['action'] ?? '') : '';
$C = lyre_comptes();
$liste = function ($C) { return array_map('lyre_compte_public', $C['comptes']); };
$nettoie = function ($a) { return ['id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string)($a['id'] ?? '')), 'name' => trim((string)($a['name'] ?? '')), 'role' => trim((string)($a['role'] ?? '')), 'login' => strtolower(trim((string)($a['login'] ?? ''))), 'email' => strtolower(trim((string)($a['email'] ?? ''))), 'active' => !empty($a['active']), 'access' => array_values(array_filter(array_map('strval', (array)($a['access'] ?? []))))]; };

if ($action === 'etat') out(200, ['ok' => true, 'pret' => (bool)$C]);

if ($action === 'initialiser') {
  // Première mise en place : envoi des comptes existants, avec le code du serveur.
  $qui = lyre_bureau($cfg);
  if (!$qui || $qui['via'] !== 'code') { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
  $l = lyre_verrou(); $C = lyre_comptes();
  if ($C) { lyre_deverrou($l); out(200, ['ok' => true, 'pret' => true, 'deja' => true]); }
  $res = [];
  foreach ((array)($in['comptes'] ?? []) as $a) { $c = $nettoie($a); $pwd = (string)($a['pwd'] ?? ''); if ($c['id'] === '' || $c['login'] === '' || $pwd === '') continue;
    $c['hash'] = password_hash($pwd, PASSWORD_DEFAULT); $c['defaut'] = ($pwd === 'lyre2026'); $c['lastLogin'] = (string)($a['lastLogin'] ?? ''); $res[] = $c; }
  $aDroits = false; foreach ($res as $c) if ($c['active'] && in_array('droits', $c['access'], true)) $aDroits = true;
  if (!$res || !$aDroits) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Il faut au moins un compte actif ayant accès à « Comptes et droits ».']); }
  $ok = lyre_fichier_ecrire('comptes', ['comptes' => $res, 'journal' => []]); lyre_deverrou($l);
  out($ok ? 200 : 500, $ok ? ['ok' => true, 'pret' => true] : ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
}

if (!$C) out(409, ['ok' => false, 'pret' => false, 'error' => 'Les comptes ne sont pas encore installés sur le serveur.']);

// Mot de passe oublié : un lien valable 1 heure, utilisable une seule fois, est envoyé à l'adresse e-mail du compte
// (ou, à défaut, à celle de la fiche membre de la même personne).
$cherche = function ($C, $q) {
  foreach ($C['comptes'] as $k => $x) if ($x['login'] === $q) return $k;
  if (strpos($q, '@') === false) return -1;
  $t = []; foreach ($C['comptes'] as $k => $x) if (($x['email'] ?? '') !== '' && $x['email'] === $q) $t[] = $k;
  if (!$t) { $noms = []; foreach (lyre_annuaire() as $m) if (strtolower(trim((string)($m['email'] ?? ''))) === $q) $noms[] = lyre_norm(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
    foreach ($C['comptes'] as $k => $x) if (in_array(lyre_norm($x['name']), $noms, true)) $t[] = $k; }
  return count($t) === 1 ? $t[0] : -1;
};
$empreinte = function ($u) { return substr(hash('sha256', (string)$u['hash']), 0, 16); };
if ($action === 'oubli') {
  $q = strtolower(trim((string)($in['login'] ?? '')));
  if ($q === '') out(400, ['ok' => false, 'error' => 'Saisissez votre identifiant ou votre adresse e-mail.']);
  $cle = 'oubli|' . lyre_ip();
  if (!lyre_essais($cle)) out(429, ['ok' => false, 'error' => 'Trop de demandes. Réessayez dans un quart d’heure.']);
  lyre_essais($cle, true);
  $msg = ['ok' => true, 'message' => 'Si ce compte existe et a une adresse e-mail, un lien vient d’être envoyé. Il est valable 1 heure. Pensez à regarder dans les courriers indésirables. Sans e-mail d’ici quelques minutes, demandez au président de vous donner un nouveau mot de passe.'];
  $i = $cherche($C, $q); if ($i < 0) { sleep(1); out(200, $msg); }
  $u = $C['comptes'][$i]; if (empty($u['active'])) { sleep(1); out(200, $msg); }
  $mail = strtolower(trim((string)($u['email'] ?? '')));
  if ($mail === '') { $t = []; foreach (lyre_annuaire() as $m) if (lyre_norm(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')) === lyre_norm($u['name']) && filter_var(trim((string)($m['email'] ?? '')), FILTER_VALIDATE_EMAIL)) $t[] = strtolower(trim((string)$m['email']));
    $t = array_values(array_unique($t)); if (count($t) === 1) $mail = $t[0]; }
  if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { sleep(1); out(200, $msg); }
  $p = lyre_b64(json_encode(['t' => 'r', 'id' => (string)$u['id'], 'h' => $empreinte($u), 'exp' => time() + 3600]));
  $jeton = $p . '.' . lyre_b64(hash_hmac('sha256', $p, lyre_secret(), true));
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $lien = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/#reinit=' . $jeton;
  $txt = "Bonjour " . $u['name'] . ",\n\nUne demande de nouveau mot de passe a été faite pour votre compte de l'administration du site de La Lyre Léréenne (identifiant : " . $u['login'] . ").\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure, une seule fois) :\n" . $lien . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste valable.\n\nLa Lyre Léréenne";
  $h = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#17161c;max-width:520px">'
     . '<p>Bonjour ' . htmlspecialchars($u['name']) . ',</p><p>Une demande de nouveau mot de passe a été faite pour votre compte de l’administration du site de La Lyre Léréenne (identifiant : <b>' . htmlspecialchars($u['login']) . '</b>).</p>'
     . '<p style="margin:24px 0"><a href="' . htmlspecialchars($lien) . '" style="background:#e3106e;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px;display:inline-block">Choisir un nouveau mot de passe</a></p>'
     . '<p style="font-size:13px;color:#5c5a64">Ce lien est valable 1 heure et ne sert qu’une fois. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe actuel reste valable.</p><p>La Lyre Léréenne</p></div>';
  [$ok, $info] = lyre_mail($cfg, $mail, 'Votre nouveau mot de passe – La Lyre Léréenne', $txt, $h);
  $l = lyre_verrou(); $C = lyre_comptes();
  array_unshift($C['journal'], ['at' => gmdate('c'), 'name' => $u['name'], 'login' => $u['login'], 'ok' => $ok, 'result' => $ok ? 'lien « mot de passe oublié » envoyé' : 'lien « mot de passe oublié » non envoyé (' . $info . ')']);
  $C['journal'] = array_slice($C['journal'], 0, 200); lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l);
  if (!$ok) out(502, ['ok' => false, 'error' => 'L’e-mail n’a pas pu être envoyé (' . $info . '). Demandez au président de vous donner un nouveau mot de passe.']);
  out(200, $msg);
}
if ($action === 'reinit') {
  $d = lyre_jeton_lire((string)($in['jeton'] ?? '')); $pwd = (string)($in['pwd'] ?? '');
  $perime = 'Ce lien n’est plus valable (il a déjà servi ou a plus d’une heure). Refaites une demande avec « Mot de passe oublié ».';
  if (!$d || ($d['t'] ?? '') !== 'r') out(400, ['ok' => false, 'error' => $perime]);
  if (strlen($pwd) < 8) out(400, ['ok' => false, 'error' => 'Le mot de passe doit faire au moins 8 caractères.']);
  $l = lyre_verrou(); $C = lyre_comptes(); $i = -1;
  foreach ($C['comptes'] as $k => $x) if ((string)$x['id'] === (string)$d['id']) $i = $k;
  if ($i < 0 || empty($C['comptes'][$i]['active']) || !hash_equals($empreinte($C['comptes'][$i]), (string)($d['h'] ?? ''))) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => $perime]); }
  $C['comptes'][$i]['hash'] = password_hash($pwd, PASSWORD_DEFAULT); $C['comptes'][$i]['defaut'] = false;
  array_unshift($C['journal'], ['at' => gmdate('c'), 'name' => $C['comptes'][$i]['name'], 'login' => $C['comptes'][$i]['login'], 'ok' => true, 'result' => 'nouveau mot de passe choisi (lien e-mail)']);
  $C['journal'] = array_slice($C['journal'], 0, 200);
  $ok = lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l);
  out($ok ? 200 : 500, $ok ? ['ok' => true, 'login' => $C['comptes'][$i]['login']] : ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
}

if ($action === 'connexion') {
  $login = strtolower(trim((string)($in['login'] ?? ''))); $pwd = (string)($in['pwd'] ?? '');
  $cle = lyre_ip() . '|' . $login;
  if (!lyre_essais($cle)) out(429, ['ok' => false, 'error' => 'Trop d’essais. Réessayez dans un quart d’heure.']);
  $l = lyre_verrou(); $C = lyre_comptes(); $u = null; $i = -1;
  foreach ($C['comptes'] as $k => $x) if ($x['login'] === $login) { $u = $x; $i = $k; }
  // Connexion possible avec l'adresse e-mail : celle du compte, sinon celle de la fiche membre de la même personne.
  if (!$u && strpos($login, '@') !== false) {
    $t = []; foreach ($C['comptes'] as $k => $x) if (($x['email'] ?? '') !== '' && $x['email'] === $login) $t[] = $k;
    if (!$t) { $noms = []; foreach (lyre_annuaire() as $m) if (strtolower(trim((string)($m['email'] ?? ''))) === $login) $noms[] = lyre_norm(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
      foreach ($C['comptes'] as $k => $x) if (in_array(lyre_norm($x['name']), $noms, true)) $t[] = $k; }
    if (count($t) === 1) { $i = $t[0]; $u = $C['comptes'][$i]; }
  }
  $bon = $u && password_verify($pwd, (string)$u['hash']);
  $res = !$u ? 'identifiant inconnu' : (!$bon ? 'mot de passe incorrect' : (empty($u['active']) ? 'compte désactivé' : 'connexion réussie'));
  $okFinal = $bon && !empty($u['active']);
  array_unshift($C['journal'], ['at' => gmdate('c'), 'name' => $u ? $u['name'] : '', 'login' => substr($login, 0, 60), 'ok' => $okFinal, 'result' => $res]);
  $C['journal'] = array_slice($C['journal'], 0, 200);
  if ($okFinal) $C['comptes'][$i]['lastLogin'] = gmdate('c');
  lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l);
  if (!$okFinal) { lyre_essais($cle, true); sleep(1); out(401, ['ok' => false, 'error' => $bon ? 'Ce compte est désactivé. Contactez le président.' : 'Identifiant ou mot de passe incorrect.']); }
  lyre_essais($cle, false);
  $peutDroits = in_array('droits', (array)$u['access'], true);
  out(200, ['ok' => true, 'jeton' => lyre_jeton('b', $u['id'], 16), 'compte' => lyre_compte_public($C['comptes'][$i]), 'comptes' => $liste($C), 'journal' => $peutDroits ? $C['journal'] : []]);
}

$qui = lyre_bureau($cfg);
if (!$qui) { sleep(1); out(401, ['ok' => false, 'error' => 'Session expirée : reconnectez-vous.']); }

// Changer son propre mot de passe (depuis l'espace membres ou l'administration) : il vaut pour les deux espaces.
if ($action === 'mdp') {
  if ($qui['via'] !== 'jeton') out(400, ['ok' => false, 'error' => 'Connectez-vous avec votre compte pour changer votre mot de passe.']);
  $pwd = (string)($in['pwd'] ?? ''); if (strlen($pwd) < 6) out(400, ['ok' => false, 'error' => 'Le mot de passe doit faire au moins 6 caractères.']);
  $l = lyre_verrou(); $C = lyre_comptes(); $i = -1; foreach ($C['comptes'] as $k => $x) if ((string)$x['id'] === (string)$qui['compte']['id']) $i = $k;
  if ($i < 0) { lyre_deverrou($l); out(404, ['ok' => false, 'error' => 'Compte introuvable.']); }
  $C['comptes'][$i]['hash'] = password_hash($pwd, PASSWORD_DEFAULT); $C['comptes'][$i]['defaut'] = ($pwd === 'lyre2026');
  array_unshift($C['journal'], ['at' => gmdate('c'), 'name' => $C['comptes'][$i]['name'], 'login' => $C['comptes'][$i]['login'], 'ok' => true, 'result' => 'mot de passe changé par la personne']);
  $C['journal'] = array_slice($C['journal'], 0, 200); $ok = lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l);
  out($ok ? 200 : 500, $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
}
if ($action === 'liste') out(200, ['ok' => true, 'comptes' => $liste($C), 'journal' => lyre_droit($qui, 'droits') ? $C['journal'] : []]);

if (!lyre_droit($qui, 'droits')) out(403, ['ok' => false, 'error' => 'Votre compte ne donne pas accès à « Comptes et droits ».']);

if ($action === 'enregistrer') {
  $l = lyre_verrou(); $C = lyre_comptes(); $anc = []; foreach ($C['comptes'] as $x) $anc[$x['id']] = $x;
  $res = []; $logins = [];
  foreach ((array)($in['comptes'] ?? []) as $a) { $c = $nettoie($a); $pwd = (string)($a['pwd'] ?? '');
    if ($c['id'] === '' || $c['login'] === '' || $c['name'] === '') continue;
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Adresse e-mail invalide pour ' . $c['name'] . '.']); }
    if (isset($logins[$c['login']])) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Deux comptes ont le même identifiant : ' . $c['login']]); } $logins[$c['login']] = 1;
    $o = $anc[$c['id']] ?? null;
    if ($pwd !== '') { if (strlen($pwd) < 6) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Le mot de passe doit faire au moins 6 caractères.']); } $c['hash'] = password_hash($pwd, PASSWORD_DEFAULT); $c['defaut'] = ($pwd === 'lyre2026'); }
    elseif ($o) { $c['hash'] = $o['hash']; $c['defaut'] = !empty($o['defaut']); }
    else { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Choisissez un mot de passe pour le nouveau compte.']); }
    $c['lastLogin'] = $o['lastLogin'] ?? ''; $res[] = $c; }
  $aDroits = false; foreach ($res as $c) if ($c['active'] && in_array('droits', $c['access'], true)) $aDroits = true;
  if (!$aDroits) { lyre_deverrou($l); out(400, ['ok' => false, 'error' => 'Gardez au moins un compte actif ayant accès à « Comptes et droits ».']); }
  $C['comptes'] = $res; $ok = lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l);
  out($ok ? 200 : 500, $ok ? ['ok' => true, 'comptes' => $liste($C)] : ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
}
if ($action === 'journal_vider') { $l = lyre_verrou(); $C = lyre_comptes(); $C['journal'] = []; lyre_fichier_ecrire('comptes', $C); lyre_deverrou($l); out(200, ['ok' => true]); }
out(400, ['ok' => false, 'error' => 'Action inconnue.']);

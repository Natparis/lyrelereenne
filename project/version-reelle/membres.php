<?php
// Espace membres : liste des noms, connexion, fiche du membre connecté, fichiers (partitions, audios), inscriptions en ligne.
function out($code, $arr) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
$cfgFile = __DIR__ . '/config-site.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Fichier config-site.php absent sur le serveur (voir LISEZ-MOI).']);
$cfg = require $cfgFile;
require __DIR__ . '/reglages-lib.php';
$action = (string)($_GET['action'] ?? '');
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'televerser') { $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = []; if ($action === '') $action = (string)($in['action'] ?? ''); }
$A = lyre_annuaire();
$public = function ($m) { return ['id' => (string)$m['id'], 'prenom' => $m['prenom'] ?? '', 'nom' => $m['nom'] ?? '', 'ens' => array_values((array)($m['ens'] ?? [])), 'pup' => array_values((array)($m['pup'] ?? [])), 'byEns' => (object)($m['byEns'] ?? []), 'depuis' => $m['depuis'] ?? '']; };
$trouve = function ($id) use ($A) { foreach ($A as $m) if ((string)$m['id'] === (string)$id) return $m; return null; };
$esp = function () { $o = json_decode(lyre_espace_json()); return (is_object($o) && isset($o->esp) && is_object($o->esp)) ? $o->esp : new stdClass(); };

// Liste pour le menu « Choisissez votre nom » : prénom et initiale seulement, visible avant connexion.
if ($action === 'noms') {
  $l = []; foreach ($A as $m) { $p = trim((string)($m['prenom'] ?? '')); $n = trim((string)($m['nom'] ?? '')); if ($p === '' && $n === '') continue; $l[] = ['id' => (string)$m['id'], 'p' => $p, 'n' => $n, 'k' => 1]; }
  $lib = function ($x) { return trim($x['p'] . ' ' . ($x['n'] !== '' ? mb_convert_case(mb_substr($x['n'], 0, $x['k'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') . (mb_strlen($x['n'], 'UTF-8') > $x['k'] ? '.' : '') : '')); };
  for ($tour = 0; $tour < 12; $tour++) { $vu = []; foreach ($l as $x) { $t = $lib($x); $vu[$t] = ($vu[$t] ?? 0) + 1; } $dbl = false; foreach ($l as $i => $x) if ($vu[$lib($x)] > 1 && mb_strlen($x['n'], 'UTF-8') > $x['k']) { $l[$i]['k']++; $dbl = true; } if (!$dbl) break; }
  $res = array_map(function ($x) use ($lib) { return ['id' => $x['id'], 't' => $lib($x)]; }, $l);
  usort($res, function ($a, $b) { return strcmp(lyre_norm($a['t']), lyre_norm($b['t'])); });
  out(200, ['ok' => true, 'noms' => $res]);
}

if ($action === 'connexion') {
  $id = (string)($in['id'] ?? ''); $code = (string)($in['code'] ?? ''); $mail = strtolower(trim((string)($in['email'] ?? '')));
  $cle = lyre_ip() . '|m|' . ($id !== '' ? $id : $mail);
  if (!lyre_essais($cle)) out(429, ['ok' => false, 'error' => 'Trop d’essais. Réessayez dans un quart d’heure.']);
  // Connexion par adresse e-mail à la place du nom.
  if ($id === '' && $mail !== '') { $t = []; foreach ($A as $x) if (strtolower(trim((string)($x['email'] ?? ''))) === $mail) $t[] = (string)$x['id'];
    if (count($t) > 1) out(409, ['ok' => false, 'error' => 'Plusieurs membres ont cette adresse e-mail : choisissez plutôt votre nom dans la liste.']);
    if (!$t) { lyre_essais($cle, true); sleep(1); out(401, ['ok' => false, 'error' => 'Adresse e-mail ou code d’accès incorrect.']); }
    $id = $t[0]; }
  $m = $trouve($id);
  if (!$m) { sleep(1); out(401, ['ok' => false, 'error' => 'Choisissez votre nom dans la liste ou saisissez votre adresse e-mail.']); }
  $e = $esp(); $codes = lyre_fichier_lire('codes') ?: []; $commun = (isset($e->code) && is_string($e->code) && $e->code !== '') ? $e->code : 'LYRE2026';
  $perso = isset($codes[$id]); $ok = $perso ? password_verify($code, (string)$codes[$id]) : (lyre_norm($code) === lyre_norm($commun) && $code !== '');
  // Un membre du bureau peut aussi entrer avec le mot de passe de son compte.
  $admin = null;
  if (!$ok && $code !== '') { $C = lyre_comptes(); $nom = lyre_norm(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
    $mel = strtolower(trim((string)($m['email'] ?? '')));
    foreach (($C['comptes'] ?? []) as $c) if (!empty($c['active']) && (lyre_norm($c['name']) === $nom || ($mel !== '' && strtolower((string)($c['email'] ?? '')) === $mel)) && password_verify($code, (string)$c['hash'])) { $ok = true; $admin = ['jeton' => lyre_jeton('b', $c['id'], 16), 'compte' => lyre_compte_public($c), 'comptes' => array_map('lyre_compte_public', $C['comptes'])]; } }
  if (!$ok) { lyre_essais($cle, true); sleep(1); out(401, ['ok' => false, 'error' => $mail !== '' && ($in['id'] ?? '') === '' ? 'Adresse e-mail ou code d’accès incorrect.' : ($perso ? 'Code personnel incorrect. Vous l’avez choisi lors de votre première connexion.' : 'Code d’accès incorrect.')]); }
  lyre_essais($cle, false);
  $done = isset($e->perso) && is_object($e->perso) && isset($e->perso->$id) && !empty($e->perso->$id->done);
  out(200, ['ok' => true, 'jeton' => lyre_jeton('m', $id, 24 * 30), 'moi' => $m, 'annuaire' => array_map($public, $A), 'premier' => !$done && !$admin, 'admin' => $admin]);
}

// Fiche du membre connecté et liste des membres (noms, ensembles, pupitres : sans coordonnées).
if ($action === 'moi') {
  $id = lyre_membre(); if ($id === null) { sleep(1); out(401, ['ok' => false, 'error' => 'Session expirée : reconnectez-vous.']); }
  $m = $trouve($id); if (!$m) out(404, ['ok' => false, 'error' => 'Votre fiche n’existe plus : contactez le bureau.']);
  out(200, ['ok' => true, 'moi' => $m, 'annuaire' => array_map($public, $A)]);
}

// Téléchargement d'une partition, d'un audio ou d'un document.
if ($action === 'fichier') {
  if (lyre_membre() === null && !lyre_bureau($cfg) && !(isset($_GET['j']) && ($d = lyre_jeton_lire((string)$_GET['j'])) && ($d['t'] ?? '') === 'b')) { sleep(1); out(401, ['ok' => false, 'error' => 'Connectez-vous à l’espace membres pour ouvrir ce fichier.']); }
  $id = preg_replace('/[^a-f0-9]/', '', (string)($_GET['id'] ?? '')); $meta = lyre_fichier_lire('fichiers') ?: []; $f = lyre_dossier() . '/fichiers/' . $id . '.bin';
  if ($id === '' || !isset($meta[$id]) || !is_file($f)) out(404, ['ok' => false, 'error' => 'Fichier introuvable.']);
  $type = (string)($meta[$id]['type'] ?? ''); if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $type)) $type = 'application/octet-stream';
  $nom = str_replace(['"', "\r", "\n"], '', (string)($meta[$id]['name'] ?? 'fichier'));
  $taille = filesize($f); $debut = 0; $fin = $taille - 1;
  header('Content-Type: ' . $type); header('Accept-Ranges: bytes'); header('Cache-Control: private, max-age=3600');
  header('Content-Disposition: ' . (preg_match('#^(audio|image)/|pdf$#i', $type) ? 'inline' : 'attachment') . "; filename=\"" . rawurlencode($nom) . "\"; filename*=UTF-8''" . rawurlencode($nom));
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) { if ($r[1] !== '') $debut = (int)$r[1]; if ($r[2] !== '') $fin = min($taille - 1, (int)$r[2]); if ($debut > $fin) { http_response_code(416); exit; } http_response_code(206); header("Content-Range: bytes $debut-$fin/$taille"); }
  header('Content-Length: ' . ($fin - $debut + 1));
  $h = fopen($f, 'rb'); fseek($h, $debut); $reste = $fin - $debut + 1; while ($reste > 0 && !feof($h)) { $b = fread($h, min(65536, $reste)); echo $b; $reste -= strlen($b); flush(); } fclose($h); exit;
}

// Réponse à une demande de disponibilité depuis un lien reçu par e-mail (lien signé, propre à chaque membre).
$signe = function ($i, $m, $v) { return substr(lyre_b64(hash_hmac('sha256', 'vote|' . $i . '|' . $m . '|' . $v, lyre_secret(), true)), 0, 22); };
if ($action === 'voter') {
  $i = (string)($_GET['i'] ?? ''); $mid = (string)($_GET['m'] ?? ''); $v = (string)($_GET['v'] ?? ''); $sg = (string)($_GET['s'] ?? '');
  $lib = ['o' => 'Présent', 'p' => 'Peut-être', 'n' => 'Absent'];
  $page = function ($titre, $texte, $ok) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>La Lyre Léréenne</title></head><body style="margin:0; font-family:Arial,Helvetica,sans-serif; background:#f5f4f1; color:#17161c"><div style="max-width:460px; margin:8vh auto; background:#fff; border-radius:18px; padding:30px 26px; text-align:center; border:1px solid #d9d6cf"><div style="font-size:44px; line-height:1">' . ($ok ? '✓' : '⚠') . '</div><h1 style="font-size:22px; margin:14px 0 10px">' . htmlspecialchars($titre) . '</h1><p style="font-size:16px; line-height:1.5; margin:0 0 22px">' . $texte . '</p><a href="./" style="display:inline-block; background:#ffe14d; color:#17161c; text-decoration:none; font-weight:bold; padding:12px 22px; border-radius:999px">Ouvrir le site de la Lyre</a></div></body></html>'; exit; };
  if (!isset($lib[$v]) || $i === '' || $mid === '' || !hash_equals($signe($i, $mid, $v), $sg)) { http_response_code(400); $page('Lien incomplet', 'Ce lien n’est pas valable. Ouvrez l’espace membres pour répondre.', false); }
  $l = lyre_verrou(); $o = json_decode(lyre_espace_json()); if (!is_object($o)) $o = new stdClass();
  $esp2 = lyre_obj($o, 'esp'); $titre = ''; foreach ((array)($esp2->items ?? []) as $it) if (is_object($it) && (string)($it->id ?? '') === $i) $titre = (string)($it->title ?? '');
  if ($titre === '') { lyre_deverrou($l); http_response_code(404); $page('Demande terminée', 'Cette demande de disponibilité n’existe plus.', false); }
  lyre_obj(lyre_obj($esp2, 'votes'), $i)->$mid = $lib[$v];
  $ok = lyre_fichier_ecrire('membres', $o); lyre_deverrou($l);
  if (!$ok) { http_response_code(500); $page('Réponse non enregistrée', 'Un problème est survenu. Réessayez dans un instant.', false); }
  $page('Merci, c’est noté', 'Votre réponse « <b>' . $lib[$v] . '</b> » est enregistrée pour :<br><b>' . htmlspecialchars($titre) . '</b>.<br><br>Vous pouvez la changer en cliquant sur un autre lien du même e-mail.', true);
}

// Inscription en ligne (fiche publique) : gardée sur le serveur en plus de l'e-mail.
if ($action === 'inscription') {
  if (!lyre_essais('insc|' . lyre_ip())) out(429, ['ok' => false, 'error' => 'Trop d’envois. Réessayez plus tard.']);
  $row = array_map(function ($x) { return lyre_txt($x, 300); }, array_slice(array_values((array)($in['row'] ?? [])), 0, 60));
  if (count($row) < 2 || trim($row[0] . $row[1]) === '') out(400, ['ok' => false, 'error' => 'Fiche incomplète.']);
  lyre_essais('insc|' . lyre_ip(), true);
  $l = lyre_verrou(); $L = lyre_fichier_lire('inscriptions') ?: [];
  array_unshift($L, ['id' => 'i' . bin2hex(random_bytes(6)), 'at' => gmdate('c'), 'row' => $row, 'cols' => array_map(function ($x) { return lyre_txt($x, 60); }, array_slice(array_values((array)($in['cols'] ?? [])), 0, 60)), 'message' => lyre_txt($in['message'] ?? '', 2000), 'total' => lyre_txt($in['total'] ?? '', 40), 'pret' => !empty($in['pret']), 'solfege' => !empty($in['solfege'])]);
  $ok = lyre_fichier_ecrire('inscriptions', array_slice($L, 0, 500)); lyre_deverrou($l);
  out($ok ? 200 : 500, ['ok' => $ok]);
}

// ---- Réservé au bureau ----
$qui = lyre_bureau($cfg);
if (!$qui) { sleep(1); out(401, ['ok' => false, 'error' => 'Accès refusé : reconnectez-vous.']); }

// Le site du bureau publie la liste des membres (calculée à partir des fiches).
if ($action === 'annuaire') {
  $liste = []; foreach (array_slice((array)($in['membres'] ?? []), 0, 2000) as $m) { if (!is_array($m) || !isset($m['id'])) continue; $liste[] = $m; }
  $l = lyre_verrou(); $ok = lyre_fichier_ecrire('annuaire', ['membres' => $liste, 'at' => gmdate('c')]); lyre_deverrou($l);
  out($ok ? 200 : 500, ['ok' => $ok, 'n' => count($liste)]);
}
// Liens de réponse à mettre dans l'e-mail de chaque membre.
if ($action === 'liens') {
  $i = (string)($in['item'] ?? ''); $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/membres.php?action=voter';
  $res = []; foreach (array_slice((array)($in['ids'] ?? []), 0, 500) as $mid) { $mid = (string)$mid; $x = [];
    foreach (['o', 'p', 'n'] as $v) $x[$v] = $base . '&i=' . rawurlencode($i) . '&m=' . rawurlencode($mid) . '&v=' . $v . '&s=' . $signe($i, $mid, $v);
    $res[$mid] = $x; }
  out(200, ['ok' => true, 'liens' => $res]);
}
// Photo ou vidéo du site (galerie, agenda, actualités) : rangée dans le dossier « photos », visible de tous comme les autres images du site.
if ($action === 'photo') {
  if (empty($_FILES['f']) || ($_FILES['f']['error'] ?? 1) !== UPLOAD_ERR_OK) out(400, ['ok' => false, 'error' => 'Photo non reçue (fichier trop lourd ?).']);
  $tmp = $_FILES['f']['tmp_name']; $info = @getimagesize($tmp); $exts = ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
  $ext = $exts[is_array($info) ? ($info['mime'] ?? '') : ''] ?? '';
  if ($ext === '') { // vidéo (MP4, MOV ou WebM), reconnue par ses premiers octets
    $h = (string)@file_get_contents($tmp, false, null, 0, 16);
    if (substr($h, 4, 4) === 'ftyp') $ext = substr($h, 8, 2) === 'qt' ? 'mov' : 'mp4'; elseif (substr($h, 0, 4) === "\x1A\x45\xDF\xA3") $ext = 'webm';
    if ($ext !== '' && filesize($tmp) > 30 * 1048576) out(400, ['ok' => false, 'error' => 'Vidéo trop lourde (30 Mo maximum).']); }
  if ($ext === '') out(400, ['ok' => false, 'error' => 'Ce fichier n’est pas une image ni une vidéo.']);
  $dir = __DIR__ . '/photos'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $nom = bin2hex(random_bytes(10)) . '.' . $ext;
  if (!move_uploaded_file($tmp, $dir . '/' . $nom)) out(500, ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
  @chmod($dir . '/' . $nom, 0644);
  out(200, ['ok' => true, 'url' => 'photos/' . $nom]);
}
if ($action === 'televerser') {
  if (empty($_FILES['f']) || ($_FILES['f']['error'] ?? 1) !== UPLOAD_ERR_OK) { $e = $_FILES['f']['error'] ?? -1; out(400, ['ok' => false, 'error' => ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE || $e === -1) ? 'Fichier trop lourd pour le serveur (limite : ' . ini_get('upload_max_filesize') . ').' : 'Envoi du fichier interrompu (code ' . $e . ').']); }
  $dir = lyre_dossier() . '/fichiers'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
  $id = bin2hex(random_bytes(10));
  if (!move_uploaded_file($_FILES['f']['tmp_name'], $dir . '/' . $id . '.bin')) out(500, ['ok' => false, 'error' => 'Écriture impossible sur le serveur.']);
  $l = lyre_verrou(); $meta = lyre_fichier_lire('fichiers') ?: []; $meta[$id] = ['name' => lyre_txt($_POST['name'] ?? $_FILES['f']['name'], 200), 'type' => lyre_txt($_POST['type'] ?? '', 100), 'size' => (int)$_FILES['f']['size'], 'at' => gmdate('c')]; lyre_fichier_ecrire('fichiers', $meta); lyre_deverrou($l);
  out(200, ['ok' => true, 'id' => $id]);
}
if ($action === 'inscriptions') out(200, ['ok' => true, 'inscriptions' => lyre_fichier_lire('inscriptions') ?: []]);
if ($action === 'inscription_supprimer') { $l = lyre_verrou(); $L = lyre_fichier_lire('inscriptions') ?: []; $id = (string)($in['id'] ?? ''); $L = array_values(array_filter($L, function ($x) use ($id) { return ($x['id'] ?? '') !== $id; })); $ok = lyre_fichier_ecrire('inscriptions', $L); lyre_deverrou($l); out(200, ['ok' => $ok, 'inscriptions' => $L]); }
out(400, ['ok' => false, 'error' => 'Action inconnue.']);

<?php
// Stockage des données du site sur l'hébergement OVH (sans base de données).
// GET  donnees.php?part=public             -> lecture publique (actus, agenda, photos, tarifs…)
// GET  donnees.php?part=prive    + X-Code  -> lecture réservée au bureau (membres, élèves, compta, e-mails, espace membres, comptes…)
// POST donnees.php?part=public|prive + X-Code, corps {"data": {...}, "base": "<version lue>"}
//      -> enregistrement. Si quelqu'un a enregistré entre-temps, réponse 409 avec la version du serveur
//         (le site fusionne les deux et renvoie : rien ne s'efface).
// GET  donnees.php?part=noms               -> liste des prénoms/noms pour la connexion à l'espace membres
// GET  donnees.php?part=membres  + X-Code (bureau) ou X-Membre + X-Code-Membre (membre)
// POST donnees.php?part=membres  + mêmes en-têtes, corps {"op": "vote|perso|profil|photo|cv-add|cv-del|cv-join|prof-ack", ...}
// POST donnees.php?part=inscription        -> fiche d'inscription envoyée par un visiteur (sans code)
// GET  donnees.php?part=inscriptions + X-Code -> fiches reçues ; POST op=del pour en retirer une
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$cfgFile = __DIR__ . '/config-site.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Fichier config-site.php absent sur le serveur (voir LISEZ-MOI).']);
$cfg = require $cfgFile;
function hdr($n) { $k = 'HTTP_' . strtoupper(str_replace('-', '_', $n)); return isset($_SERVER[$k]) ? rawurldecode((string)$_SERVER[$k]) : ''; }
$codeOk = function () use ($cfg) {
  $given = hdr('X-Code');
  $real = (string)($cfg['code_bureau'] ?? '');
  return $real !== '' && strpos($real, 'CHANGEZ-MOI') !== 0 && hash_equals($real, $given);
};

$part = isset($_GET['part']) ? $_GET['part'] : '';
if (!in_array($part, ['public', 'prive', 'noms', 'membres', 'inscription', 'inscriptions'], true)) out(400, ['ok' => false, 'error' => 'Partie inconnue.']);
$dir = __DIR__ . '/donnees-lyre';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$GARDE = "<?php http_response_code(403); exit; ?>\n";
function fichier($nom) { global $dir; return $dir . '/' . $nom . '.json.php'; }
function lireBrut($nom) { global $GARDE; $f = fichier($nom); if (!is_file($f)) return ''; $r = file_get_contents($f); return strpos($r, $GARDE) === 0 ? substr($r, strlen($GARDE)) : $r; }
function lire($nom) { $r = lireBrut($nom); if ($r === '') return []; $d = json_decode($r, true); return is_array($d) ? $d : []; }
function version($nom) { $r = lireBrut($nom); return $r === '' ? '' : md5($r); }
// Les tables indexées par membre restent des objets JSON (sinon PHP en fait des listes quand les clés sont 0, 1, 2…).
function objets($D) { foreach (['photos', 'votes', 'perso', 'prof'] as $k) if (isset($D[$k])) { $D[$k] = (object)$D[$k]; if ($k === 'votes') foreach ($D[$k] as $i => $v) $D[$k]->$i = (object)$v; } return $D; }
function ecrire($nom, $data, $sauvegarde = true) {
  global $dir, $GARDE;
  $f = fichier($nom);
  if ($nom === 'membres') $data = objets($data);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if ($json === false) out(400, ['ok' => false, 'error' => 'Données illisibles.']);
  if ($sauvegarde && is_file($f)) { // 30 dernières versions gardées
    $bdir = $dir . '/sauvegardes';
    if (!is_dir($bdir)) @mkdir($bdir, 0750, true);
    @copy($f, $bdir . '/' . $nom . '-' . date('Ymd-His') . '.json.php');
    $old = glob($bdir . '/' . $nom . '-*.json.php'); sort($old);
    while (count($old) > 30) @unlink(array_shift($old));
  }
  $tmp = $f . '.tmp.php';
  if (file_put_contents($tmp, $GARDE . $json) === false || !rename($tmp, $f)) out(500, ['ok' => false, 'error' => "Écriture impossible sur le serveur (droits du dossier donnees-lyre)."]);
  return md5($json);
}
// Un seul enregistrement à la fois (évite que deux envois simultanés s'écrasent).
$verrou = fopen($dir . '/.verrou', 'c'); if ($verrou) flock($verrou, LOCK_EX);
function corps() { $b = json_decode(file_get_contents('php://input'), true); return is_array($b) ? $b : []; }
function norm($s) {
  $s = strtr((string)$s, ['à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ÿ'=>'y','ñ'=>'n',
    'À'=>'a','Â'=>'a','Ä'=>'a','Á'=>'a','Ç'=>'c','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','Î'=>'i','Ï'=>'i','Ô'=>'o','Ö'=>'o','Ù'=>'u','Û'=>'u','Ü'=>'u','Ÿ'=>'y','Ñ'=>'n']);
  return trim(preg_replace('/\s+/', ' ', strtolower($s)));
}
function txt($v, $max = 300) { return mb_substr(trim((string)$v), 0, $max); }
$M = $_SERVER['REQUEST_METHOD'];

// ---------- Données du bureau (public / privé) ----------
if ($part === 'public' || $part === 'prive') {
  if ($M === 'GET') {
    if ($part === 'prive' && !$codeOk()) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
    $f = fichier($part);
    if (!is_file($f)) out(200, ['ok' => true, 'data' => new stdClass(), 'at' => 0, 'v' => '']);
    $data = lire($part);
    out(200, ['ok' => true, 'data' => $data ? $data : new stdClass(), 'at' => filemtime($f), 'v' => version($part)]);
  }
  if ($M === 'POST') {
    if (!$codeOk()) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
    $body = corps();
    if (!isset($body['data']) || !is_array($body['data'])) out(400, ['ok' => false, 'error' => 'Données illisibles.']);
    $cur = version($part);
    if (array_key_exists('base', $body) && (string)$body['base'] !== $cur) {
      $data = lire($part);
      out(409, ['ok' => false, 'conflict' => true, 'error' => "Quelqu'un a enregistré entre-temps.", 'data' => $data ? $data : new stdClass(), 'v' => $cur]);
    }
    $v = ecrire($part, $body['data']);
    out(200, ['ok' => true, 'at' => filemtime(fichier($part)), 'v' => $v]);
  }
}

// ---------- Liste des noms pour se connecter à l'espace membres ----------
if ($part === 'noms') {
  $p = lire('prive'); $l = [];
  foreach (($p['memDir'] ?? []) as $m) if (isset($m['id'])) $l[] = ['id' => (string)$m['id'], 'name' => (string)($m['name'] ?? '')];
  out(200, ['ok' => true, 'noms' => $l]);
}

// ---------- Espace membres (saisies des membres) ----------
if ($part === 'membres') {
  $prive = lire('prive'); $D = lire('membres');
  foreach (['covoit' => [], 'photos' => [], 'votes' => [], 'perso' => [], 'prof' => []] as $k => $def) if (!isset($D[$k]) || !is_array($D[$k])) $D[$k] = $def;
  $bureau = $codeOk();
  $id = hdr('X-Membre'); $me = null; $acct = '';
  foreach (($prive['memDir'] ?? []) as $m) if (isset($m['id']) && (string)$m['id'] === $id) $me = $m;
  if (!$bureau) {
    $code = hdr('X-Code-Membre');
    if (!$me || $code === '') { sleep(1); out(401, ['ok' => false, 'error' => 'Connexion à l’espace membres refusée.']); }
    $P = $D['perso'][$id] ?? [];
    $ok = !empty($P['hash']) ? password_verify($code, $P['hash']) : (norm($code) !== '' && norm($code) === norm($prive['esp']['code'] ?? ''));
    foreach (($prive['accounts'] ?? []) as $a) if (!empty($a['active']) && norm($a['name'] ?? '') === norm($me['name'] ?? '') && (string)($a['pwd'] ?? '') !== '' && hash_equals((string)$a['pwd'], $code)) { $ok = true; $acct = (string)$a['id']; }
    if (!$ok) { sleep(1); out(401, ['ok' => false, 'error' => !empty($P['hash']) ? 'Code personnel incorrect. Vous l’avez choisi lors de votre première connexion.' : 'Code d’accès incorrect.', 'perso' => !empty($P['hash'])]); }
  }
  $vue = function () use (&$D) { $o = $D; $o['perso'] = [];
    foreach ($D['perso'] as $k => $p) $o['perso'][$k] = ['done' => !empty($p['done']), 'hasCode' => !empty($p['hash']), 'setAt' => $p['setAt'] ?? ''];
    return $o; };
  $repondre = function () use (&$D, $vue, $bureau, $prive, $me, $acct) {
    $r = ['ok' => true, 'data' => objets($vue())];
    if (!$bureau || $me) { $esp = $prive['esp'] ?? null; if (is_array($esp)) { unset($esp['code'], $esp['perso'], $esp['votes']); } $r['esp'] = $esp; $r['me'] = $me; $r['acct'] = $acct; }
    out(200, $r); };
  if ($M === 'GET') $repondre();
  if ($M !== 'POST') out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);
  $b = corps(); $op = (string)($b['op'] ?? '');
  if ($op === 'prof-ack') { // le bureau a reporté les profils modifiés dans sa liste des membres
    if (!$bureau) out(403, ['ok' => false, 'error' => 'Réservé au bureau.']);
    foreach ((array)($b['ids'] ?? []) as $k => $at) if (isset($D['prof'][$k]) && (int)$D['prof'][$k]['at'] === (int)$at) unset($D['prof'][$k]);
    ecrire('membres', $D, false); $repondre();
  }
  if ($op === 'cv-del' && $bureau && !$me) { $cid = (string)($b['cid'] ?? ''); $D['covoit'] = array_values(array_filter($D['covoit'], function ($c) use ($cid) { return ($c['id'] ?? '') !== $cid; })); ecrire('membres', $D, false); $repondre(); }
  if (!$me) out(400, ['ok' => false, 'error' => 'Membre inconnu.']);
  $nom = (string)($me['name'] ?? '');
  switch ($op) {
    case 'vote':
      $it = txt($b['item'] ?? '', 80); $v = (string)($b['v'] ?? '');
      if ($it === '' || !in_array($v, ['Présent', 'Peut-être', 'Absent', ''], true)) out(400, ['ok' => false, 'error' => 'Réponse invalide.']);
      if (!isset($D['votes'][$it]) || !is_array($D['votes'][$it])) $D['votes'][$it] = [];
      if ($v === '') unset($D['votes'][$it][$id]); else $D['votes'][$it][$id] = $v;
      break;
    case 'perso':
      $P = $D['perso'][$id] ?? []; $P['done'] = true;
      if (!empty($b['keep'])) unset($P['hash']);
      if (isset($b['code']) && (string)$b['code'] !== '') { $c = (string)$b['code']; if (mb_strlen($c) < 6) out(400, ['ok' => false, 'error' => 'Au moins 6 caractères.']); $P['hash'] = password_hash($c, PASSWORD_DEFAULT); $P['setAt'] = date('c'); }
      $D['perso'][$id] = $P;
      break;
    case 'profil':
      $cells = $b['cells'] ?? null; if (!is_array($cells) || count($cells) > 60) out(400, ['ok' => false, 'error' => 'Profil illisible.']);
      $D['prof'][$id] = ['cells' => array_map(function ($c) { return txt($c, 500); }, array_values($cells)), 'at' => time()];
      break;
    case 'photo':
      $u = $b['url'] ?? null;
      if ($u === null || $u === '') unset($D['photos'][$id]);
      else { if (!preg_match('#^data:image/(webp|jpeg|png);base64,#', (string)$u) || strlen($u) > 400000) out(400, ['ok' => false, 'error' => 'Photo trop lourde ou illisible.']); $D['photos'][$id] = (string)$u; }
      break;
    case 'cv-add':
      $e = (array)($b['entry'] ?? []);
      $D['covoit'] = array_slice(array_merge([[ 'id' => 'cv' . time() . mt_rand(100, 999), 'type' => ($e['type'] ?? '') === 'recherche' ? 'recherche' : 'offre', 'authorId' => $id, 'author' => $nom, 'tel' => txt($me['tel'] ?? '', 40),
        'ev' => txt($e['ev'] ?? '', 200), 'from' => txt($e['from'] ?? '', 120), 'date' => txt($e['date'] ?? '', 10), 'time' => txt($e['time'] ?? '', 40), 'seats' => max(1, min(9, (int)($e['seats'] ?? 1))), 'note' => txt($e['note'] ?? '', 500), 'takers' => [] ]], $D['covoit']), 0, 300);
      break;
    case 'cv-del':
      $cid = (string)($b['cid'] ?? '');
      $D['covoit'] = array_values(array_filter($D['covoit'], function ($c) use ($cid, $id, $bureau) { return ($c['id'] ?? '') !== $cid || (($c['authorId'] ?? '') !== $id && !$bureau); }));
      break;
    case 'cv-join':
      $cid = (string)($b['cid'] ?? ''); $join = !empty($b['join']);
      foreach ($D['covoit'] as &$c) if (($c['id'] ?? '') === $cid && ($c['authorId'] ?? '') !== $id) {
        $t = array_values(array_filter((array)($c['takers'] ?? []), function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));
        if ($join && (($c['type'] ?? '') !== 'offre' || count($t) < (int)($c['seats'] ?? 1))) $t[] = ['id' => $id, 'name' => $nom];
        $c['takers'] = $t; }
      unset($c);
      break;
    default: out(400, ['ok' => false, 'error' => 'Opération inconnue.']);
  }
  ecrire('membres', $D, false);
  $repondre();
}

// ---------- Fiches d'inscription envoyées par les visiteurs ----------
if ($part === 'inscription') {
  if ($M !== 'POST') out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);
  $raw = file_get_contents('php://input');
  if (strlen($raw) > 40000) out(413, ['ok' => false, 'error' => 'Fiche trop longue.']);
  $b = json_decode($raw, true); if (!is_array($b) || !isset($b['data']) || !is_array($b['data'])) out(400, ['ok' => false, 'error' => 'Fiche illisible.']);
  // Anti-abus : 10 fiches par heure et par connexion.
  $ip = md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'lyre'); $lim = lire('limites'); $now = time();
  foreach ($lim as $k => $ts) { $lim[$k] = array_values(array_filter((array)$ts, function ($t) use ($now) { return $t > $now - 3600; })); if (!$lim[$k]) unset($lim[$k]); }
  if (count($lim[$ip] ?? []) >= 10) out(429, ['ok' => false, 'error' => 'Trop de fiches envoyées. Réessayez dans une heure.']);
  $lim[$ip][] = $now; ecrire('limites', $lim, false);
  $L = lire('inscriptions');
  array_unshift($L, ['id' => 'i' . $now . mt_rand(100, 999), 'at' => date('c'), 'data' => $b['data']]);
  ecrire('inscriptions', array_slice($L, 0, 1000), false);
  out(200, ['ok' => true]);
}
if ($part === 'inscriptions') {
  if (!$codeOk()) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
  $L = lire('inscriptions');
  if ($M === 'POST') { $b = corps(); if (($b['op'] ?? '') === 'del') { $iid = (string)($b['id'] ?? ''); $L = array_values(array_filter($L, function ($x) use ($iid) { return ($x['id'] ?? '') !== $iid; })); ecrire('inscriptions', $L, false); } }
  out(200, ['ok' => true, 'list' => $L]);
}
out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);

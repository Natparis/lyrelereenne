<?php
// Réglages protégés (clé Brevo, code des réglages) gardés sur le serveur, fichier inaccessible depuis le web.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'reglages-lib.php') { http_response_code(403); exit; }
function lyre_reglages_fichier() { return __DIR__ . '/donnees-lyre/reglages.json.php'; }
function lyre_reglages_lire() {
  $f = lyre_reglages_fichier(); if (!is_file($f)) return [];
  $r = (string)file_get_contents($f); $p = strpos($r, "\n"); if ($p !== false && strpos($r, '<?php') === 0) $r = substr($r, $p + 1);
  $j = json_decode($r, true); return is_array($j) ? $j : [];
}
function lyre_reglages_ecrire($arr) {
  $dir = __DIR__ . '/donnees-lyre'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
  return file_put_contents(lyre_reglages_fichier(), "<?php http_response_code(403); exit; ?>\n" . json_encode($arr), LOCK_EX) !== false;
}
function lyre_brevo_saved() { $r = lyre_reglages_lire(); return (string)($r['brevo_key'] ?? ''); }
function lyre_brevo_save($k) { $r = lyre_reglages_lire(); if ($k === '') unset($r['brevo_key']); else $r['brevo_key'] = $k; return lyre_reglages_ecrire($r); }
function lyre_tech_defini() { $r = lyre_reglages_lire(); return !empty($r['tech_hash']); }
function lyre_tech_ok($code) { $r = lyre_reglages_lire(); return !empty($r['tech_hash']) && $code !== '' && password_verify($code, $r['tech_hash']); }
function lyre_tech_creer($code) { $r = lyre_reglages_lire(); $r['tech_hash'] = password_hash($code, PASSWORD_DEFAULT); return lyre_reglages_ecrire($r); }

// ---- Fichiers protégés, jetons de session, comptes du bureau ----
function lyre_dossier() { $d = __DIR__ . '/donnees-lyre'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function lyre_fichier_lire($nom) {
  $f = lyre_dossier() . '/' . $nom . '.json.php'; if (!is_file($f)) return null;
  $r = (string)file_get_contents($f); $p = strpos($r, "\n"); if ($p !== false && strpos($r, '<?php') === 0) $r = substr($r, $p + 1);
  $j = json_decode($r, true); return is_array($j) ? $j : null;
}
function lyre_fichier_ecrire($nom, $arr) {
  $f = lyre_dossier() . '/' . $nom . '.json.php'; $tmp = $f . '.tmp.php';
  return file_put_contents($tmp, "<?php http_response_code(403); exit; ?>\n" . json_encode($arr, JSON_UNESCAPED_UNICODE)) !== false && rename($tmp, $f);
}
function lyre_verrou() { $l = @fopen(lyre_dossier() . '/verrou.lock', 'c'); if ($l) flock($l, LOCK_EX); return $l; }
function lyre_deverrou($l) { if ($l) { flock($l, LOCK_UN); fclose($l); } }
function lyre_secret() { $r = lyre_reglages_lire(); if (empty($r['secret'])) { $r['secret'] = bin2hex(random_bytes(32)); lyre_reglages_ecrire($r); } return (string)$r['secret']; }
function lyre_b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function lyre_jeton($type, $id, $heures) { $p = lyre_b64(json_encode(['t' => $type, 'id' => (string)$id, 'exp' => time() + (int)($heures * 3600)])); return $p . '.' . lyre_b64(hash_hmac('sha256', $p, lyre_secret(), true)); }
function lyre_jeton_lire($j) {
  $x = explode('.', (string)$j); if (count($x) !== 2) return null;
  if (!hash_equals(lyre_b64(hash_hmac('sha256', $x[0], lyre_secret(), true)), $x[1])) return null;
  $d = json_decode((string)base64_decode(strtr($x[0], '-_', '+/')), true);
  return (is_array($d) && ($d['exp'] ?? 0) > time()) ? $d : null;
}
function lyre_comptes() { $c = lyre_fichier_lire('comptes'); return (is_array($c) && !empty($c['comptes'])) ? $c : null; }
function lyre_compte_public($c) { return ['id' => $c['id'], 'name' => $c['name'], 'role' => $c['role'] ?? '', 'login' => $c['login'], 'email' => $c['email'] ?? '', 'active' => !empty($c['active']), 'access' => array_values((array)($c['access'] ?? [])), 'lastLogin' => $c['lastLogin'] ?? '', 'defaut' => !empty($c['defaut'])]; }
// Qui appelle ? Renvoie ['via' => 'code'] (code du serveur), ['via' => 'jeton', 'compte' => …] ou null.
function lyre_bureau($cfg, $donne = null) {
  $real = (string)($cfg['code_bureau'] ?? '');
  $code = $donne !== null ? (string)$donne : (isset($_SERVER['HTTP_X_CODE']) ? (string)$_SERVER['HTTP_X_CODE'] : '');
  $jeton = isset($_SERVER['HTTP_X_JETON']) ? (string)$_SERVER['HTTP_X_JETON'] : '';
  if (strpos($code, 'jeton:') === 0) { $jeton = substr($code, 6); $code = ''; }
  if ($code !== '' && $real !== '' && strpos($real, 'CHANGEZ-MOI') !== 0 && hash_equals($real, $code)) return ['via' => 'code'];
  if ($jeton !== '') { $d = lyre_jeton_lire($jeton); if ($d && ($d['t'] ?? '') === 'b') { $c = lyre_comptes(); foreach (($c['comptes'] ?? []) as $x) if ((string)$x['id'] === (string)$d['id'] && !empty($x['active'])) return ['via' => 'jeton', 'compte' => $x]; } }
  return null;
}
function lyre_droit($qui, $section) { return $qui && ($qui['via'] === 'code' || in_array($section, (array)($qui['compte']['access'] ?? []), true)); }
// Limite les essais de mot de passe : 8 échecs par quart d'heure et par adresse.
function lyre_essais($cle, $echec = null) {
  $t = lyre_fichier_lire('essais') ?: []; $now = time(); $k = md5($cle);
  foreach ($t as $kk => $v) { $t[$kk] = array_values(array_filter((array)$v, function ($x) use ($now) { return $x > $now - 900; })); if (!$t[$kk]) unset($t[$kk]); }
  if ($echec === true) { $t[$k][] = $now; lyre_fichier_ecrire('essais', $t); }
  elseif ($echec === false && isset($t[$k])) { unset($t[$k]); lyre_fichier_ecrire('essais', $t); }
  return count($t[$k] ?? []) < 8;
}
function lyre_ip() { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }
// Envoi d'un e-mail à une seule personne (mot de passe oublié…) : Brevo si une clé est réglée, sinon l'hébergement OVH.
// Expéditeur : celui des réglages des envois du site, sinon celui de config-site.php.
function lyre_mail($cfg, $to, $subject, $text, $html) {
  $p = lyre_fichier_lire('prive'); $mc = (is_array($p) && isset($p['mailCfg']) && is_array($p['mailCfg'])) ? $p['mailCfg'] : [];
  $from = trim((string)($mc['fromEmail'] ?? '')); if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = trim((string)($cfg['from_email'] ?? ''));
  $name = trim(str_replace(["\r", "\n"], ' ', (string)($mc['fromName'] ?? ''))) ?: (string)($cfg['from_name'] ?? 'La Lyre Léréenne');
  if (!filter_var($from, FILTER_VALIDATE_EMAIL) || stripos($from, 'votre-domaine') !== false) return [false, 'adresse d’expédition non réglée'];
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'adresse du destinataire invalide'];
  $key = lyre_brevo_saved(); if ($key === '') $key = (string)($cfg['brevo_key'] ?? '');
  if ($key !== '' && function_exists('curl_init')) {
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_POSTFIELDS => json_encode(['sender' => ['name' => $name, 'email' => $from], 'to' => [['email' => $to]], 'subject' => $subject, 'textContent' => $text, 'htmlContent' => $html], JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 200 && $code < 300) return [true, 'brevo'];
    $j = json_decode((string)$res, true); return [false, 'Brevo : ' . (string)($j['message'] ?? ('code ' . $code))];
  }
  $enc = function ($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }; $b = 'b_' . md5(uniqid('', true));
  $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
  $h = "From: " . $enc($name) . " <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n";
  return @mail($to, $enc($subject), $body, $h, '-f' . $from) ? [true, 'ovh'] : [false, 'l’hébergement OVH a refusé l’envoi'];
}
function lyre_norm($s) { $s = strtr(mb_strtolower(trim((string)$s), 'UTF-8'), ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe']); return preg_replace('/\s+/', ' ', $s); }

// ---- Espace membres ----
function lyre_jeton_recu() { $j = isset($_SERVER['HTTP_X_JETON']) ? (string)$_SERVER['HTTP_X_JETON'] : ''; if ($j === '' && isset($_GET['j'])) $j = (string)$_GET['j']; return $j; }
// Renvoie l'identifiant du membre connecté, ou null.
function lyre_membre() { $d = lyre_jeton_lire(lyre_jeton_recu()); return ($d && ($d['t'] ?? '') === 'm') ? (string)$d['id'] : null; }
function lyre_annuaire() { $a = lyre_fichier_lire('annuaire'); return (is_array($a) && isset($a['membres']) && is_array($a['membres'])) ? $a['membres'] : []; }
function lyre_espace_json() { $f = lyre_dossier() . '/membres.json.php'; if (!is_file($f)) return '{}'; $r = (string)file_get_contents($f); $p = strpos($r, "\n"); if ($p !== false && strpos($r, '<?php') === 0) $r = substr($r, $p + 1); return ($r !== '' && $r[0] === '{') ? $r : '{}'; }
function lyre_obj($o, $k) { if (!isset($o->$k) || !is_object($o->$k)) $o->$k = new stdClass(); return $o->$k; }
function lyre_txt($v, $max) { return mb_substr(trim(str_replace(["\r", "\0"], '', (string)$v)), 0, $max, 'UTF-8'); }
// Retire les codes personnels des données (ils sont gardés chiffrés à part). $seul = identifiant du membre autorisé, ou null pour le bureau.
function lyre_codes_extraire($data, $seul = null) {
  if (!isset($data->esp) || !is_object($data->esp) || !isset($data->esp->perso) || !is_object($data->esp->perso)) return false;
  $codes = lyre_fichier_lire('codes') ?: []; $chg = false;
  foreach ($data->esp->perso as $id => $p) { if (!is_object($p)) continue; $id = (string)$id;
    if (isset($p->code)) { if (($seul === null || $seul === $id) && is_string($p->code) && strlen($p->code) >= 4) { $codes[$id] = password_hash($p->code, PASSWORD_DEFAULT); $p->has = true; } unset($p->code); $chg = true; }
    elseif (isset($p->has) && $p->has === false && isset($codes[$id]) && ($seul === null || $seul === $id)) { unset($codes[$id]); $chg = true; } }
  if ($chg) lyre_fichier_ecrire('codes', $codes);
  return $chg;
}
// Un membre ne peut changer que ce qui le concerne : ses réponses, sa photo, son profil, ses annonces de covoiturage.
function lyre_membre_filtre($actuelJson, $nouveauJson, $moi) {
  $res = json_decode($actuelJson ?: '{}'); if (!is_object($res)) $res = new stdClass();
  $new = json_decode($nouveauJson); if (!is_object($new)) return $res;
  $esp = lyre_obj($res, 'esp'); $nesp = (isset($new->esp) && is_object($new->esp)) ? $new->esp : new stdClass();
  // réponses aux demandes de disponibilité
  $votes = lyre_obj($esp, 'votes'); $nv = (isset($nesp->votes) && is_object($nesp->votes)) ? $nesp->votes : new stdClass();
  $ids = array_unique(array_merge(array_keys(get_object_vars($votes)), array_keys(get_object_vars($nv))));
  foreach ($ids as $it) { $it = (string)$it; $v = (isset($nv->$it) && is_object($nv->$it) && isset($nv->$it->$moi)) ? (string)$nv->$it->$moi : '';
    if (in_array($v, ['Présent', 'Absent', 'Peut-être'], true)) { lyre_obj($votes, $it)->$moi = $v; }
    elseif (isset($votes->$it) && is_object($votes->$it)) { unset($votes->$it->$moi); } }
  // photo de profil
  $ph = lyre_obj($res, 'memPhotos'); $np = (isset($new->memPhotos) && is_object($new->memPhotos) && isset($new->memPhotos->$moi)) ? $new->memPhotos->$moi : null;
  if (is_string($np) && strlen($np) < 600000 && strpos($np, 'data:image/') === 0) $ph->$moi = $np; else unset($ph->$moi);
  // première connexion et code personnel
  if (isset($nesp->perso) && is_object($nesp->perso) && isset($nesp->perso->$moi) && is_object($nesp->perso->$moi)) { $p = lyre_obj(lyre_obj($esp, 'perso'), $moi); $n = $nesp->perso->$moi; $p->done = true;
    if (isset($n->code)) $p->code = $n->code; if (isset($n->has)) $p->has = (bool)$n->has; if (isset($n->setAt)) $p->setAt = lyre_txt($n->setAt, 40); }
  // demande de mise à jour du profil (appliquée ensuite par le bureau)
  if (isset($nesp->profils) && is_object($nesp->profils) && isset($nesp->profils->$moi) && is_object($nesp->profils->$moi)) { $n = $nesp->profils->$moi; $o = new stdClass();
    $o->tel = lyre_txt($n->tel ?? '', 30); $o->email = lyre_txt($n->email ?? '', 120); $o->pup = lyre_txt($n->pup ?? '', 200); $o->ens = array_values(array_map(function ($x) { return lyre_txt($x, 40); }, array_slice((array)($n->ens ?? []), 0, 6))); $o->at = gmdate('c');
    lyre_obj($esp, 'profils')->$moi = $o; }
  // covoiturage
  $cur = (isset($res->covoit) && is_array($res->covoit)) ? $res->covoit : []; $prop = (isset($new->covoit) && is_array($new->covoit)) ? $new->covoit : null;
  if ($prop !== null) { $pm = []; foreach ($prop as $c) if (is_object($c) && isset($c->id)) $pm[(string)$c->id] = $c;
    $propre = function ($c) use ($moi) { $o = new stdClass(); $o->id = lyre_txt($c->id, 40); $o->type = (($c->type ?? '') === 'demande') ? 'demande' : 'offre'; $o->authorId = $moi; $o->author = lyre_txt($c->author ?? '', 80); $o->tel = lyre_txt($c->tel ?? '', 30);
      $o->ev = lyre_txt($c->ev ?? '', 160); $o->from = lyre_txt($c->from ?? '', 120); $o->date = lyre_txt($c->date ?? '', 12); $o->time = lyre_txt($c->time ?? '', 30); $o->seats = max(1, min(9, (int)($c->seats ?? 1))); $o->note = lyre_txt($c->note ?? '', 400); return $o; };
    $out = []; $vus = [];
    foreach ($cur as $c) { if (!is_object($c) || !isset($c->id)) continue; $id = (string)$c->id; $vus[$id] = 1; $tk = (isset($c->takers) && is_array($c->takers)) ? $c->takers : [];
      if ((string)($c->authorId ?? '') === $moi) { if (!isset($pm[$id])) continue; $o = $propre($pm[$id]); $o->takers = $tk; $out[] = $o; continue; }
      if (isset($pm[$id])) { $veut = false; $nom = ''; foreach ((array)($pm[$id]->takers ?? []) as $t) if (is_object($t) && (string)($t->id ?? '') === $moi) { $veut = true; $nom = lyre_txt($t->name ?? '', 80); }
        $tk = array_values(array_filter($tk, function ($t) use ($moi) { return !(is_object($t) && (string)($t->id ?? '') === $moi); }));
        if ($veut) { $t = new stdClass(); $t->id = $moi; $t->name = $nom; $tk[] = $t; } $c->takers = $tk; }
      $out[] = $c; }
    $ajout = []; foreach ($pm as $id => $c) if (!isset($vus[$id]) && (string)($c->authorId ?? '') === $moi && count($ajout) < 5) { $o = $propre($c); $o->takers = []; $ajout[] = $o; }
    $res->covoit = array_slice(array_merge($ajout, $out), 0, 300); }
  return $res;
}

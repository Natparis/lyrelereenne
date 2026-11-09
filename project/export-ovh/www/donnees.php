<?php
// Stockage des données du site sur l'hébergement OVH (sans base de données).
// GET  donnees.php?part=public          -> lecture publique (actus, agenda, photos, tarifs…)
// GET  donnees.php?part=prive  + X-Code -> lecture réservée au bureau (membres, élèves, compta, e-mails…)
// POST donnees.php?part=public|prive + X-Code, corps {"data": {...}} -> enregistrement
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$cfgFile = __DIR__ . '/config-site.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Fichier config-site.php absent sur le serveur (voir LISEZ-MOI).']);
$cfg = require $cfgFile;
$codeOk = function () use ($cfg) {
  $given = isset($_SERVER['HTTP_X_CODE']) ? (string)$_SERVER['HTTP_X_CODE'] : '';
  $real = (string)($cfg['code_bureau'] ?? '');
  return $real !== '' && strpos($real, 'CHANGEZ-MOI') !== 0 && hash_equals($real, $given);
};

$part = isset($_GET['part']) ? $_GET['part'] : '';
if (!in_array($part, ['public', 'prive'], true)) out(400, ['ok' => false, 'error' => 'Partie inconnue.']);
$dir = __DIR__ . '/donnees-lyre';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$file = $dir . '/' . $part . '.json.php';
$GARDE = "<?php http_response_code(403); exit; ?>\n";
$lire = function ($f) use ($GARDE) { $r = file_get_contents($f); return strpos($r, $GARDE) === 0 ? substr($r, strlen($GARDE)) : $r; };

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if ($part === 'prive' && !$codeOk()) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
  if (!is_file($file)) out(200, ['ok' => true, 'data' => new stdClass(), 'at' => 0]);
  $raw = $lire($file);
  $data = json_decode($raw, true);
  out(200, ['ok' => true, 'data' => $data === null ? new stdClass() : $data, 'at' => filemtime($file)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$codeOk()) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect.']); }
  $body = json_decode(file_get_contents('php://input'), true);
  if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) out(400, ['ok' => false, 'error' => 'Données illisibles.']);
  $json = json_encode($body['data'], JSON_UNESCAPED_UNICODE);
  if ($json === false) out(400, ['ok' => false, 'error' => 'Données illisibles.']);
  // Sauvegarde de la version précédente (30 dernières gardées).
  if (is_file($file)) {
    $bdir = $dir . '/sauvegardes';
    if (!is_dir($bdir)) @mkdir($bdir, 0750, true);
    @copy($file, $bdir . '/' . $part . '-' . date('Ymd-His') . '.json.php');
    $old = glob($bdir . '/' . $part . '-*.json.php'); sort($old);
    while (count($old) > 30) @unlink(array_shift($old));
  }
  $tmp = $file . '.tmp.php';
  if (file_put_contents($tmp, $GARDE . $json, LOCK_EX) === false || !rename($tmp, $file)) out(500, ['ok' => false, 'error' => "Écriture impossible sur le serveur (droits du dossier donnees-lyre)."]);
  out(200, ['ok' => true, 'at' => filemtime($file)]);
}
out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);

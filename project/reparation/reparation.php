<?php
// Réparation unique (25/11/2026) : remet les rubriques du bureau effacées par une version incomplète du site
// (paiements de cotisation, tenues, historique des prêts, fonctions), à partir des sauvegardes automatiques.
// À envoyer dans « www », ouvrir une fois, puis SUPPRIMER du serveur.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$cfg = @include __DIR__ . '/config-site.php';
$real = is_array($cfg) ? (string)($cfg['code_bureau'] ?? '') : '';
$dir = __DIR__ . '/donnees-lyre';
$GARDE = "<?php http_response_code(403); exit; ?>\n";
function brut($f) { if (!is_file($f)) return null; $r = (string)file_get_contents($f); if (strpos($r, '<?php') === 0) { $p = strpos($r, "\n"); $r = $p === false ? '' : substr($r, $p + 1); } return $r; }
function lire($f) { $r = brut($f); if ($r === null || $r === '') return null; $d = json_decode($r); return is_object($d) ? $d : null; }
function vide($v) { return $v === null || $v === [] || (is_object($v) && !get_object_vars($v)) || $v === ''; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$lignes = []; $fait = false; $erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = (string)($_POST['code'] ?? '');
  if ($real === '' || !hash_equals($real, $code)) { sleep(2); $erreur = 'Code du serveur incorrect.'; }
  else {
    $plan = [
      'prive' => ['remettre' => ['cotPay', 'tenues', 'pretHist'], 'retirer' => ['accounts', 'connLog', 'memDir', 'esp']],
      'public' => ['remettre' => ['foncExtra', 'tenues', 'pretHist', 'cotPay'], 'retirer' => []],
    ];
    foreach ($plan as $part => $p) {
      $fichier = "$dir/$part.json.php"; $cur = lire($fichier);
      if (!$cur) { $lignes[] = "Données « $part » introuvables : rien à faire."; continue; }
      $bks = glob("$dir/sauvegardes/$part-*.json.php") ?: [];
      usort($bks, function ($a, $b) { return filemtime($b) - filemtime($a); });
      $chg = false;
      foreach ($p['remettre'] as $k) {
        if (property_exists($cur, $k) && !vide($cur->$k)) continue; // déjà présent et rempli : on n'y touche pas
        foreach ($bks as $f) { $d = lire($f); if ($d && property_exists($d, $k) && !vide($d->$k)) {
          $cur->$k = $d->$k; $chg = true; $lignes[] = "Rubrique « $k » remise depuis la sauvegarde " . basename($f) . ' (' . date('d/m/Y H:i', filemtime($f)) . ').'; break; } }
      }
      foreach ($p['retirer'] as $k) if (property_exists($cur, $k)) { unset($cur->$k); $chg = true; $lignes[] = "Rubrique « $k » ajoutée par erreur : retirée."; }
      if (!$chg) { $lignes[] = "Données « $part » : rien à réparer."; continue; }
      if (!is_dir("$dir/sauvegardes")) @mkdir("$dir/sauvegardes", 0750, true);
      @copy($fichier, "$dir/sauvegardes/$part-avant-reparation-" . date('Ymd-His') . '.json.php');
      $json = json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($json === false || file_put_contents("$fichier.tmp.php", $GARDE . $json) === false || !rename("$fichier.tmp.php", $fichier)) $lignes[] = "⚠ Écriture impossible pour « $part ».";
      else $lignes[] = "Données « $part » enregistrées.";
    }
    $fait = true;
  }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Réparation des données</title></head>
<body style="margin:0; font-family:Arial,Helvetica,sans-serif; background:#f5f4f1; color:#17161c">
<div style="max-width:560px; margin:6vh auto; background:#fff; border:1px solid #d9d6cf; border-radius:18px; padding:28px 24px">
<h1 style="font-size:22px; margin:0 0 10px">Réparation des données du bureau</h1>
<?php if ($fait): ?>
  <ul style="line-height:1.6; padding-left:20px"><?php foreach ($lignes as $l) echo '<li>' . h($l) . '</li>'; ?></ul>
  <p style="font-weight:bold; color:#2f7d3a">Terminé. Supprimez maintenant le fichier reparation.php du serveur.</p>
<?php else: ?>
  <p style="line-height:1.5">Remet les paiements de cotisation, les tenues et l'historique des prêts d'instruments depuis la sauvegarde automatique. Rien d'autre n'est modifié.</p>
  <?php if ($erreur) echo '<p style="color:#c0162b; font-weight:bold">' . h($erreur) . '</p>'; ?>
  <form method="post" style="display:flex; flex-direction:column; gap:12px">
    <label style="font-weight:bold">Code du serveur <input type="password" name="code" required style="display:block; width:100%; box-sizing:border-box; margin-top:6px; padding:10px; border:1px solid #d9d6cf; border-radius:8px; font-size:16px"></label>
    <button type="submit" style="background:#e3106e; color:#fff; border:0; padding:12px; border-radius:999px; font-size:16px; font-weight:bold">Réparer</button>
  </form>
<?php endif; ?>
</div></body></html>

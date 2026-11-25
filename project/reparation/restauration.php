<?php
// Restauration (25/11/2026) : remet les données du site (actus, agenda, galerie, membres, élèves, compta, espace membres)
// depuis la dernière sauvegarde automatique complète, après leur remplacement par les données de démonstration.
// À envoyer dans « www », ouvrir une fois, puis SUPPRIMER du serveur.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$cfg = @include __DIR__ . '/config-site.php';
$real = is_array($cfg) ? (string)($cfg['code_bureau'] ?? '') : '';
$dir = __DIR__ . '/donnees-lyre';
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ko($n) { return number_format($n / 1024, 0, ',', ' ') . ' Ko'; }
function quand($t) { return date('d/m/Y à H:i:s', $t); }
date_default_timezone_set('Europe/Paris');
// Pour chaque partie : la plus récente sauvegarde dont la taille est au moins la moitié de la plus grosse version des 3 derniers jours.
function plan($dir, $part) {
  $cur = "$dir/$part.json.php"; $curT = is_file($cur) ? filesize($cur) : 0;
  $l = [];
  foreach (glob("$dir/sauvegardes/$part-*.json.php") ?: [] as $f) { if (strpos(basename($f), 'avant-restauration') !== false) continue; if (filemtime($f) < time() - 3 * 86400) continue; $l[] = ['f' => $f, 't' => filemtime($f), 's' => filesize($f)]; }
  $max = $curT; foreach ($l as $x) $max = max($max, $x['s']);
  usort($l, function ($a, $b) { return $b['t'] - $a['t']; });
  $choix = null; foreach ($l as $x) if ($x['s'] >= 0.5 * $max) { $choix = $x; break; }
  $aFaire = $curT < 0.5 * $max && $choix;
  return ['part' => $part, 'cur' => $curT, 'curT' => is_file($cur) ? filemtime($cur) : 0, 'max' => $max, 'choix' => $choix, 'aFaire' => $aFaire, 'n' => count($l)];
}
$noms = ['public' => 'Actus, agenda, galerie, tarifs', 'prive' => 'Membres, élèves, professeurs, cotisations, comptabilité, e-mails', 'membres' => 'Espace membres (documents, réponses, covoiturage)'];
$etape = 'code'; $erreur = ''; $plans = []; $faits = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = (string)($_POST['code'] ?? '');
  if ($real === '' || !hash_equals($real, $code)) { sleep(2); $erreur = 'Code du serveur incorrect.'; }
  else {
    foreach (array_keys($noms) as $p) $plans[$p] = plan($dir, $p);
    if (($_POST['go'] ?? '') === '1') {
      $l = @fopen("$dir/verrou.lock", 'c'); if ($l) flock($l, LOCK_EX);
      foreach ($plans as $p => $x) {
        if (!$x['aFaire']) { $faits[] = $noms[$p] . ' : rien à restaurer.'; continue; }
        $cur = "$dir/$p.json.php";
        if (is_file($cur)) @copy($cur, "$dir/sauvegardes/$p-avant-restauration-" . date('Ymd-His') . '.json.php');
        $ok = @copy($x['choix']['f'], "$cur.tmp.php") && @rename("$cur.tmp.php", $cur);
        $faits[] = $noms[$p] . ($ok ? ' : restauré depuis la sauvegarde du ' . quand($x['choix']['t']) . ' (' . ko($x['choix']['s']) . ').' : ' : ⚠ écriture impossible.');
      }
      if ($l) { flock($l, LOCK_UN); fclose($l); }
      $etape = 'fait';
    } else $etape = 'plan';
  }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Restauration des données</title></head>
<body style="margin:0; font-family:Arial,Helvetica,sans-serif; background:#f5f4f1; color:#17161c">
<div style="max-width:640px; margin:5vh auto; background:#fff; border:1px solid #d9d6cf; border-radius:18px; padding:28px 24px">
<h1 style="font-size:22px; margin:0 0 10px">Restauration des données du site</h1>
<?php if ($etape === 'fait'): ?>
  <ul style="line-height:1.6; padding-left:20px"><?php foreach ($faits as $f) echo '<li>' . h($f) . '</li>'; ?></ul>
  <p style="font-weight:bold; color:#2f7d3a">Terminé. Supprimez maintenant le fichier restauration.php du serveur, puis rouvrez l'administration.</p>
<?php elseif ($etape === 'plan'): ?>
  <p style="line-height:1.5">Voici ce qui va être fait. Rien n'est encore modifié.</p>
  <ul style="line-height:1.6; padding-left:20px">
  <?php foreach ($plans as $p => $x): ?>
    <li><b><?= h($noms[$p]) ?></b> : actuellement <?= h(ko($x['cur'])) ?>.
      <?= $x['aFaire'] ? 'Sera remis depuis la sauvegarde du <b>' . h(quand($x['choix']['t'])) . '</b> (' . h(ko($x['choix']['s'])) . ').' : 'Rien à restaurer.' ?></li>
  <?php endforeach; ?>
  </ul>
  <form method="post"><input type="hidden" name="code" value="<?= h($_POST['code']) ?>"><input type="hidden" name="go" value="1">
    <button type="submit" style="background:#e3106e; color:#fff; border:0; padding:12px 22px; border-radius:999px; font-size:16px; font-weight:bold">Restaurer</button></form>
<?php else: ?>
  <p style="line-height:1.5">Remet les données du site depuis la dernière sauvegarde automatique complète du serveur. Une copie de l'état actuel est gardée.</p>
  <?php if ($erreur) echo '<p style="color:#c0162b; font-weight:bold">' . h($erreur) . '</p>'; ?>
  <form method="post" style="display:flex; flex-direction:column; gap:12px">
    <label style="font-weight:bold">Code du serveur <input type="password" name="code" required style="display:block; width:100%; box-sizing:border-box; margin-top:6px; padding:10px; border:1px solid #d9d6cf; border-radius:8px; font-size:16px"></label>
    <button type="submit" style="background:#17161c; color:#fff; border:0; padding:12px; border-radius:999px; font-size:16px; font-weight:bold">Voir ce qui sera restauré</button>
  </form>
<?php endif; ?>
</div></body></html>

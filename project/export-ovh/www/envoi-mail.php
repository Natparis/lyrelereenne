<?php
// Envoi direct des e-mails depuis l'administration du site.
// Utilise Brevo si une clé est réglée dans config-site.php, sinon l'envoi de l'hébergement OVH.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($code, $arr) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'error' => 'Méthode non prise en charge.']);
$cfgFile = __DIR__ . '/config-site.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Fichier config-site.php absent sur le serveur (voir LISEZ-MOI).']);
$cfg = require $cfgFile;
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'Demande illisible.']);
$real = (string)($cfg['code_bureau'] ?? '');
if ($real === '' || strpos($real, 'CHANGEZ-MOI') === 0 || !hash_equals($real, (string)($in['code'] ?? ''))) { sleep(1); out(401, ['ok' => false, 'error' => 'Code du serveur incorrect : saisissez-le dans le bandeau « Serveur OVH ».']); }

$clean = function ($s) { return trim(str_replace(["\r", "\n"], ' ', (string)$s)); };
$valid = function ($e) { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); };
$fromEmail = $clean($in['fromEmail'] ?? '') ?: (string)($cfg['from_email'] ?? '');
$fromName  = $clean($in['fromName'] ?? '') ?: (string)($cfg['from_name'] ?? 'La Lyre Léréenne');
$replyTo   = $clean($in['replyTo'] ?? '');
$subject   = $clean($in['subject'] ?? '') ?: '(sans objet)';
$text      = (string)($in['text'] ?? '');
$html      = (string)($in['html'] ?? '');
$bcc = array_values(array_unique(array_filter(array_map($clean, (array)($in['bcc'] ?? [])), $valid)));
$max = (int)($cfg['max_dest'] ?? 400);
if (!$valid($fromEmail)) out(400, ['ok' => false, 'error' => "Adresse d'expédition manquante ou invalide (réglages des envois ou config-site.php)."]);
if (!$bcc) out(400, ['ok' => false, 'error' => 'Aucun destinataire valide.']);
if (count($bcc) > $max) out(400, ['ok' => false, 'error' => 'Trop de destinataires (maximum ' . $max . ').']);
if ($replyTo !== '' && !$valid($replyTo)) $replyTo = '';
$att = [];
foreach ((array)($in['attachments'] ?? []) as $a) {
  $c = preg_replace('/[^A-Za-z0-9+\/=]/', '', (string)($a['content'] ?? ''));
  if ($c === '' || strlen($c) > 8000000) continue;
  $att[] = ['name' => preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($a['name'] ?? 'photo.jpg')), 'content' => $c];
}

$key = (string)($cfg['brevo_key'] ?? '');
if ($key !== '') {
  // Brevo : un seul destinataire visible (l'expéditeur), les membres en copie cachée, par lots de 90.
  foreach (array_chunk($bcc, 90) as $lot) {
    $payload = ['sender' => ['name' => $fromName, 'email' => $fromEmail], 'to' => [['email' => $replyTo ?: $fromEmail, 'name' => $fromName]],
      'bcc' => array_map(function ($e) { return ['email' => $e]; }, $lot), 'subject' => $subject, 'textContent' => $text, 'htmlContent' => $html ?: nl2br(htmlspecialchars($text))];
    if (count($bcc) === 1) { $payload['to'] = [['email' => $bcc[0]]]; unset($payload['bcc']); }
    if ($replyTo) $payload['replyTo'] = ['email' => $replyTo];
    if ($att) $payload['attachment'] = $att;
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code < 200 || $code >= 300) { $j = json_decode((string)$res, true); out(502, ['ok' => false, 'error' => 'Brevo a refusé l’envoi : ' . ($j['message'] ?? ('code ' . $code))]); }
  }
  out(200, ['ok' => true, 'count' => count($bcc), 'via' => 'brevo']);
}

// Envoi par l'hébergement OVH (fonction mail de PHP).
$enc = function ($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
$b1 = 'b1_' . md5(uniqid('', true)); $b2 = 'b2_' . md5(uniqid('', true));
$alt = "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
     . "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html ?: nl2br(htmlspecialchars($text)))) . "--$b2--\r\n";
if ($att) {
  $body = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n" . $alt;
  foreach ($att as $a) $body .= "--$b1\r\nContent-Type: image/jpeg; name=\"{$a['name']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$a['name']}\"\r\n\r\n" . chunk_split($a['content']);
  $body .= "--$b1--\r\n"; $ctype = "multipart/mixed; boundary=\"$b1\"";
} else { $body = $alt; $ctype = "multipart/alternative; boundary=\"$b2\""; }
$sent = 0; $fail = 0;
foreach (array_chunk($bcc, 50) as $lot) {
  $single = count($bcc) === 1;
  $h = "From: " . $enc($fromName) . " <$fromEmail>\r\n" . ($replyTo ? "Reply-To: $replyTo\r\n" : '') . ($single ? '' : "Bcc: " . implode(', ', $lot) . "\r\n")
     . "MIME-Version: 1.0\r\nContent-Type: $ctype\r\n";
  $ok = mail($single ? $bcc[0] : ($replyTo ?: $fromEmail), $enc($subject), $body, $h, '-f' . $fromEmail);
  if ($ok) $sent += count($lot); else $fail += count($lot);
}
if (!$sent) out(502, ['ok' => false, 'error' => "L'hébergement OVH n'a pas accepté l'envoi. Vérifiez que l'adresse d'expédition est bien une adresse de votre domaine."]);
out(200, ['ok' => true, 'count' => $sent, 'failed' => $fail, 'via' => 'ovh']);

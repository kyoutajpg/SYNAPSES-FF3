
<?php
ob_start(); error_reporting(0); ini_set('display_errors',0);
header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__.'/src/Exception.php';
require __DIR__.'/src/PHPMailer.php';
require __DIR__.'/src/SMTP.php';

/* ══ CONFIG ══════════════════════════════════════════════════════ */
$SMTP_HOST = 'smtp.gmail.com';
$SMTP_USER = 'labasynapses@gmail.com';
$SMTP_PASS = 'xdmm rpjt ktrx hpuk';   // ← App Password Gmail 16 caratteri
$DEST_MAIL = 'labasynapses@gmail.com';

$CANCELLAZIONE_MAIL = 'labasynapses@gmail.com'; // mail a cui scrivere per cancellare

/* ══ POSTI PER WORKSHOP ══════════════════════════════════════════
   Modifica i numeri liberamente. 0 = illimitato.                  */
$posti_max = [
    'Masterchef della Grafica'       => 20,
    'Fanzine AI'                     => 15,
    'Generazione Immagini Comfy'     => 15,
    'Sviluppo 3D Meshy e Stampa 3D'  => 12,
    'Pack Design'                    => 15,
    'Creare con le AI'               => 20,
    'Incisione e Stampa'             => 12,
    'Cianotipia — Vedo Blu'          => 12,
    'Serigrafia — Bestiari'          => 15,
    'Graphic Design nel Cinema'      => 20,
    'Made to Measure LABA!'          => 10,
    'Antotipia'                      => 12,
];

$FILE_JSON  = __DIR__.'/prenotazioni.json';
$FILE_LOCK  = __DIR__.'/prenotazioni.lock';

/* ══ VALIDAZIONE REQUEST ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Metodo non consentito.']); exit;
}

$nome     = trim(strip_tags($_POST['nome']     ?? ''));
$cognome  = trim(strip_tags($_POST['cognome']  ?? ''));
$email    = trim(strip_tags($_POST['email']    ?? ''));
$workshop = trim(strip_tags($_POST['workshop'] ?? ''));
$orario   = trim(strip_tags($_POST['orario']   ?? ''));
$evento   = trim(strip_tags($_POST['evento']   ?? ''));

if (!$nome || !$cognome || !$email || !$workshop || !$orario) {
    ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Compila tutti i campi.']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Email non valida.']); exit;
}
if (!array_key_exists($workshop, $posti_max)) {
    ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Workshop non riconosciuto.']); exit;
}

/* ══ GESTIONE POSTI CON LOCK FILE (race-condition safe) ════════ */
$lock = fopen($FILE_LOCK, 'c');
flock($lock, LOCK_EX);

$prenotazioni = [];
if (file_exists($FILE_JSON)) {
    $prenotazioni = json_decode(file_get_contents($FILE_JSON), true) ?? [];
}

// Controlla se questa email ha già prenotato lo stesso workshop
foreach ($prenotazioni as $p) {
    if (strtolower($p['email']) === strtolower($email) && $p['workshop'] === $workshop) {
        flock($lock, LOCK_UN); fclose($lock);
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>'Hai già prenotato questo workshop con questa email.']);
        exit;
    }
}

// Conta posti occupati per questo workshop
$max = $posti_max[$workshop];
if ($max > 0) {
    $occupati = count(array_filter($prenotazioni, fn($p) => $p['workshop'] === $workshop));
    $rimasti  = $max - $occupati;
    if ($rimasti <= 0) {
        flock($lock, LOCK_UN); fclose($lock);
        ob_end_clean();
        echo json_encode(['ok'=>false,'msg'=>'Posti esauriti per questo workshop.']);
        exit;
    }
} else {
    $rimasti = 999; // illimitato
}

// Aggiungi prenotazione
$nuova = [
    'id'        => uniqid('ws_', true),
    'timestamp' => date('Y-m-d H:i:s'),
    'workshop'  => $workshop,
    'evento'    => $evento,
    'orario'    => $orario,
    'nome'      => $nome,
    'cognome'   => $cognome,
    'email'     => $email,
];
$prenotazioni[] = $nuova;
file_put_contents($FILE_JSON, json_encode($prenotazioni, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

flock($lock, LOCK_UN);
fclose($lock);

/* ══ AGGIORNA EXCEL (CSV leggibile da Excel) ═══════════════════ */
aggiornaExcel($prenotazioni);

/* ══ INVIO EMAIL ════════════════════════════════════════════════ */
$posti_info = $max > 0 ? "$rimasti posti rimasti su $max" : "Posti illimitati";

// Email admin
$soggetto_admin = "WS Prenotazione: $workshop — $nome $cognome";
$corpo_admin  = "Nuova prenotazione ricevuta.\n\n";
$corpo_admin .= "Workshop : $workshop\n";
$corpo_admin .= "Evento   : $evento\n";
$corpo_admin .= "Orario   : $orario\n";
$corpo_admin .= "Nome     : $nome $cognome\n";
$corpo_admin .= "Email    : $email\n";
$corpo_admin .= "Posti    : $posti_info\n";
$corpo_admin .= "ID       : ".$nuova['id']."\n";

// Email utente con istruzioni cancellazione
$soggetto_utente = "Iscrizione confermata — $workshop";
$corpo_utente  = "Ciao $nome,\n\n";
$corpo_utente .= "la tua iscrizione al workshop \"$workshop\" è confermata.\n";
$corpo_utente .= "Orario: $orario\n\n";
$corpo_utente .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$corpo_utente .= "COME CANCELLARE LA PRENOTAZIONE\n";
$corpo_utente .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$corpo_utente .= "Se non riesci a partecipare, scrivici entro 48 ore prima del workshop:\n";
$corpo_utente .= "→ $CANCELLAZIONE_MAIL\n";
$corpo_utente .= "Oggetto: CANCELLAZIONE — $workshop\n";
$corpo_utente .= "Indica il tuo nome, cognome e il tuo ID prenotazione: ".$nuova['id']."\n\n";
$corpo_utente .= "Ti chiediamo di cancellare solo se strettamente necessario,\n";
$corpo_utente .= "per permettere ad altri partecipanti di prendere il tuo posto.\n\n";
$corpo_utente .= "A presto,\n";
$corpo_utente .= "Team Synapses 2026 — LABA Brescia\n";

sendMail($SMTP_HOST,$SMTP_USER,$SMTP_PASS,$DEST_MAIL,$soggetto_admin,$corpo_admin);
sendMail($SMTP_HOST,$SMTP_USER,$SMTP_PASS,$email,$soggetto_utente,$corpo_utente);

ob_end_clean();
echo json_encode([
    'ok'      => true,
    'rimasti' => max(0, $rimasti - 1),
    'max'     => $max,
]);

/* ══ FUNZIONI ═══════════════════════════════════════════════════ */
function sendMail($host,$user,$pass,$to,$subject,$body) {
    $m = new PHPMailer(true);
    try {
        $m->isSMTP(); $m->Host=$host; $m->SMTPAuth=true;
        $m->Username=$user; $m->Password=$pass;
        $m->SMTPSecure='tls'; $m->Port=587;
        $m->CharSet='UTF-8';
        $m->setFrom($user,'Synapses 2026 LABA');
        $m->addAddress($to);
        $m->Subject=$subject; $m->Body=$body;
        $m->send(); return true;
    } catch(Exception $e) { return false; }
}

function aggiornaExcel($prenotazioni) {
    $file = __DIR__.'/prenotazioni_export.csv';

    // Ordina per workshop poi timestamp
    usort($prenotazioni, function($a,$b) {
        $cmp = strcmp($a['workshop'], $b['workshop']);
        return $cmp !== 0 ? $cmp : strcmp($a['timestamp'], $b['timestamp']);
    });

    $fp = fopen($file, 'w');
    // BOM UTF-8 per Excel italiano
    fputs($fp, "\xEF\xBB\xBF");
    // Intestazione
    fputcsv($fp, ['ID','Data','Workshop','Evento','Orario','Nome','Cognome','Email'], ';');

    $ws_corrente = '';
    foreach ($prenotazioni as $p) {
        // Riga separatrice tra workshop diversi
        if ($p['workshop'] !== $ws_corrente) {
            if ($ws_corrente !== '') fputcsv($fp, ['','','','','','','',''], ';');
            $ws_corrente = $p['workshop'];
        }
        fputcsv($fp, [
            $p['id'],
            $p['timestamp'],
            $p['workshop'],
            $p['evento'] ?? '',
            $p['orario'],
            $p['nome'],
            $p['cognome'],
            $p['email'],
        ], ';');
    }
    fclose($fp);
}

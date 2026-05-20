<?php
/**
 * EventHub Pro — pdf/ticket.php  [COMPLÉTÉ - Partie 3.1]
 *
 * CHOIX DE BIBLIOTHÈQUE : TCPDF
 * Justification : TCPDF est plus adapté à la génération programmatique
 * (dessins, QR codes, primitives graphiques) tandis que Dompdf est
 * meilleur pour rendre du HTML/CSS. Pour un ticket avec bandeau coloré,
 * QR code et mise en page précise, TCPDF offre plus de contrôle.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';
require_once __DIR__ . '/../lib/phpqrcode/qrlib.php'; // Si disponible

function generateTicketPDF(
    PDO    $pdo,
    int    $registrationId,
    string $token,
    string $output   = 'D',
    string $filePath = ''
) {
    // ── Récupérer les données de l'inscription ────────────────────────
    $stmt = $pdo->prepare(
        'SELECT r.id, r.name, r.email, r.registered_at,
                e.id AS event_id, e.title, e.event_date, e.location,
                e.category, e.capacity,
                COUNT(reg2.id) AS registered_count
         FROM   registrations r
         JOIN   events e          ON e.id = r.event_id
         LEFT JOIN registrations reg2 ON reg2.event_id = e.id
         WHERE  r.id = :rid AND r.token = :token
         GROUP BY r.id'
    );
    $stmt->execute([':rid' => $registrationId, ':token' => $token]);
    $data = $stmt->fetch();

    if (!$data) {
        http_response_code(404);
        die('Inscription introuvable ou token invalide.');
    }

    // ── Couleurs par catégorie ─────────────────────────────────────────
    $categoryColors = [
        'tech'     => ['r' => 37,  'g' => 99,  'b' => 235],
        'design'   => ['r' => 124, 'g' => 58,  'b' => 237],
        'business' => ['r' => 234, 'g' => 88,  'b' => 12],
        'science'  => ['r' => 22,  'g' => 163, 'b' => 74],
    ];
    $color = $categoryColors[$data['category']] ?? ['r' => 15, 'g' => 31, 'b' => 61];

    // ── Génération QR Code ────────────────────────────────────────────
    $qrData    = $data['event_id'] . '|' . $registrationId . '|' . $token;
    $qrTmpFile = sys_get_temp_dir() . '/qr_' . $registrationId . '.png';
    $qrIncluded = false;

    if (class_exists('QRcode')) {
        QRcode::png($qrData, $qrTmpFile, 'M', 5);
        $qrIncluded = file_exists($qrTmpFile);
    }

    // ── Construction du PDF ────────────────────────────────────────────
    $pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8');
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetAuthor('ENSA Marrakech');
    $pdf->SetTitle('Ticket — ' . $data['title']);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $W = 210; // A5 paysage largeur
    $H = 148;

    // ── BANDEAU coloré en haut (élément visuel créatif) ───────────────
    $pdf->SetFillColor($color['r'], $color['g'], $color['b']);
    $pdf->Rect(0, 0, $W, 18, 'F');

    // Titre dans le bandeau
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(10, 4);
    $pdf->Cell($W - 80, 10, 'TICKET D\'INSCRIPTION — EventHub Pro', 0, 0, 'L');

    // Numéro de ticket
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY($W - 75, 4);
    $pdf->Cell(65, 10, 'N° ' . str_pad($registrationId, 5, '0', STR_PAD_LEFT), 0, 0, 'R');

    // ── Corps principal ───────────────────────────────────────────────
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetFillColor(240, 244, 255);

    // Bloc événement (gauche)
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetXY(10, 25);
    $pdf->MultiCell(120, 10, $data['title'], 0, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $dateFormatee = (new DateTime($data['event_date']))->format('d/m/Y à H\hi');
    $pdf->SetXY(10, 45);
    $pdf->Cell(120, 7, '📅  ' . $dateFormatee, 0, 1, 'L');
    $pdf->SetX(10);
    $pdf->Cell(120, 7, '📍  ' . $data['location'], 0, 1, 'L');
    $logoPath = __DIR__ . '/../assets/img/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 5, 4, 10); // Petit logo dans le bandeau à gauche
}

    // Ligne séparatrice verticale
    $pdf->SetDrawColor($color['r'], $color['g'], $color['b']);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(140, 22, 140, $H - 20);

    // Bloc QR Code (droite)
    if ($qrIncluded) {
        $pdf->Image($qrTmpFile, 148, 24, 50, 50);
        @unlink($qrTmpFile);
    } else {
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetXY(148, 44);
        $pdf->Cell(50, 10, '[QR Code]', 1, 0, 'C');
    }

    // Catégorie badge (droite, sous QR)
    $pdf->SetFillColor($color['r'], $color['g'], $color['b']);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetXY(158, 80);
    $pdf->Cell(30, 7, strtoupper($data['category']), 0, 0, 'C', true);

    // ── Bloc participant ──────────────────────────────────────────────
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect(0, $H - 45, $W, 25, 'F');

    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetXY(10, $H - 42);
    $pdf->Cell(60, 6, 'Participant :', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(80, 6, $data['name'], 0, 1, 'L');

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetX(10);
    $pdf->Cell(60, 6, 'Email :', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(80, 6, $data['email'], 0, 1, 'L');

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetX(10);
    $pdf->Cell(60, 6, 'Inscrit le :', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $inscritLe = (new DateTime($data['registered_at']))->format('d/m/Y à H\hi');
    $pdf->Cell(80, 6, $inscritLe, 0, 0, 'L');

    // ── Pied de page avec lien désinscription ─────────────────────────
    $pdf->SetFillColor($color['r'], $color['g'], $color['b']);
    $pdf->Rect(0, $H - 18, $W, 18, 'F');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetXY(10, $H - 13);
    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $unsubLink = $baseUrl . '/events/unsubscribe.php?token=' . urlencode($token);
    $pdf->Cell($W - 20, 6, 'Désinscription : ' . $unsubLink, 0, 0, 'C');

    // ── Output ─────────────────────────────────────────────────────────
    switch ($output) {
        case 'F':
            $pdf->Output($filePath, 'F');
            return $filePath;
        case 'S':
            return $pdf->Output('', 'S');
        case 'D':
        default:
            $pdf->Output('ticket_eventhub_' . $registrationId . '.pdf', 'D');
    }
}

// Point d'entrée GET
if (php_sapi_name() !== 'cli' && isset($_GET['registration_id'], $_GET['token'])) {
    $pdo = getDB();
    generateTicketPDF($pdo, (int)$_GET['registration_id'], htmlspecialchars($_GET['token']), 'D');
}

<?php
/**
 * EventHub Pro — pdf/report.php  [COMPLÉTÉ - Partie 3.2]
 *
 * CHOIX DE BIBLIOTHÈQUE : TCPDF (cohérence avec ticket.php)
 * Le graphique en barres est dessiné avec les primitives TCPDF :
 * Rect(), Line(), Cell(), SetFillColor() → sans JS ni image externe.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

function generateReportPDF(PDO $pdo, int $eventId, string $output = 'D', string $filePath = '')
{
    // ── Données de l'événement ─────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT e.*,
                COUNT(r.id)                         AS registered_count,
                (e.capacity - COUNT(r.id))          AS available_places,
                ROUND(COUNT(r.id)/e.capacity * 100) AS fill_pct
         FROM   events e
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE  e.id = :id GROUP BY e.id'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) { die('Événement introuvable.'); }

    // ── Inscrits (triés par nom) ───────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, email, registered_at
         FROM registrations WHERE event_id = :id ORDER BY name ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $registrations = $stmt->fetchAll();

    // ── Stats par jour (7 derniers jours) ─────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT DATE(registered_at) AS day, COUNT(*) AS count
         FROM registrations
         WHERE event_id = :id AND registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(registered_at) ORDER BY day ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $statsByDay = $stmt->fetchAll();

    // ── Initialisation TCPDF ───────────────────────────────────────────
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetTitle('Rapport — ' . $event['title']);
    $pdf->SetMargins(15, 28, 15);
    $pdf->SetAutoPageBreak(true, 20);

    // En-tête personnalisé sur chaque page
    $pdf->SetHeaderData('', 0, 'EventHub Pro — Rapport Organisateur', $event['title']);
    $pdf->setHeaderFont(['helvetica', 'B', 10]);
    $pdf->SetFooterData([15, 31, 61], [15, 31, 61]);
    $pdf->setFooterFont(['helvetica', 'I', 8]);

    // ════════════════════════════════════════════════════════════════════
    // PAGE 1 : Résumé exécutif
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    // Titre principal
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(0, 12, 'Résumé Exécutif', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Généré le ' . date('d/m/Y à H\hi'), 0, 1, 'C');
    $pdf->Ln(5);

    // Bloc informations événement
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(37, 99, 235);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, '  Informations de l\'événement', 0, 1, 'L', true);
    $pdf->Ln(2);

    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetFont('helvetica', '', 10);
    $infoLines = [
        'Titre'        => $event['title'],
        'Date'         => (new DateTime($event['event_date']))->format('d/m/Y à H\hi'),
        'Lieu'         => $event['location'],
        'Catégorie'    => strtoupper($event['category']),
        'Organisateur' => $event['organizer_email'],
    ];

    foreach ($infoLines as $label => $value) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(40, 7, $label . ' :', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, $value, 0, 1, 'L');
    }

    $pdf->Ln(5);

    // KPIs en 3 colonnes
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(37, 99, 235);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, '  Statistiques', 0, 1, 'L', true);
    $pdf->Ln(4);

    $kpis = [
        ['Capacité totale',     $event['capacity'] . ' places'],
        ['Inscrits',            $event['registered_count'] . ' participants'],
        ['Places disponibles',  $event['available_places'] . ' places'],
        ['Taux de remplissage', $event['fill_pct'] . '%'],
    ];

    $colW = (210 - 30) / 2;
    $pdf->SetTextColor(15, 31, 61);
    foreach (array_chunk($kpis, 2) as $row) {
        foreach ($row as $kpi) {
            $pdf->SetFillColor(240, 244, 255);
            $pdf->Rect($pdf->GetX(), $pdf->GetY(), $colW - 2, 18, 'F');
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell($colW - 2, 10, $kpi[1], 0, 0, 'C');
            $pdf->SetFont('helvetica', '', 8);
        }
        $pdf->Ln(10);
        foreach ($row as $kpi) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->Cell($colW - 2, 8, $kpi[0], 0, 0, 'C');
        }
        $pdf->Ln(12);
    }

    // ════════════════════════════════════════════════════════════════════
    // PAGE 2 : Liste des inscrits
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(37, 99, 235);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, '  Liste des inscrits (' . count($registrations) . ')', 0, 1, 'L', true);
    $pdf->Ln(3);

    // En-têtes de tableau
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->Cell(10,  8, 'N°',    1, 0, 'C', true);
    $pdf->Cell(55,  8, 'Nom',   1, 0, 'C', true);
    $pdf->Cell(70,  8, 'Email', 1, 0, 'C', true);
    $pdf->Cell(35,  8, 'Date inscription', 1, 1, 'C', true);

    // Lignes du tableau (pagination automatique TCPDF)
    $pdf->SetFont('helvetica', '', 8);
    foreach ($registrations as $i => $reg) {
        $fillRow = ($i % 2 === 0);
        $pdf->SetFillColor(248, 250, 252);
        $inscritLe = (new DateTime($reg['registered_at']))->format('d/m/Y H:i');
        $pdf->Cell(10,  7, $i + 1,           1, 0, 'C', $fillRow);
        $pdf->Cell(55,  7, $reg['name'],      1, 0, 'L', $fillRow);
        $pdf->Cell(70,  7, $reg['email'],     1, 0, 'L', $fillRow);
        $pdf->Cell(35,  7, $inscritLe,        1, 1, 'C', $fillRow);
    }

    // ════════════════════════════════════════════════════════════════════
    // PAGE 3 : Graphique en barres — DÉFI TECHNIQUE
    // Dessiné avec les primitives TCPDF (Rect, Line, Cell, SetFillColor)
    // sans JavaScript ni image externe.
    // ════════════════════════════════════════════════════════════════════
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(37, 99, 235);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, '  Inscriptions par jour (7 derniers jours)', 0, 1, 'L', true);
    $pdf->Ln(5);

    if (empty($statsByDay)) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 10, 'Aucune inscription au cours des 7 derniers jours.', 0, 1, 'C');
    } else {
        // Paramètres du graphique
        $maxCount  = max(array_column($statsByDay, 'count'));
        $chartH    = 80;   // hauteur max des barres (mm)
        $barW      = 22;   // largeur d'une barre (mm)
        $gap       = 5;    // espace entre barres (mm)
        $originX   = 25;   // marge gauche du graphique
        $originY   = $pdf->GetY() + $chartH + 10; // Y de la base des barres
        $nbBars    = count($statsByDay);

        // ── Axe Y : lignes de grille horizontales ─────────────────────
        $pdf->SetDrawColor(200, 210, 220);
        $pdf->SetLineWidth(0.2);
        $steps = 5;
        for ($s = 0; $s <= $steps; $s++) {
            $yLine = $originY - ($s / $steps) * $chartH;
            $pdf->Line($originX, $yLine, $originX + $nbBars * ($barW + $gap), $yLine);

            // Label Y
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetTextColor(100, 100, 100);
            $yVal = round(($s / $steps) * $maxCount);
            $pdf->SetXY($originX - 12, $yLine - 2);
            $pdf->Cell(10, 4, $yVal, 0, 0, 'R');
        }

        // ── Axe X et Y principaux ─────────────────────────────────────
        $pdf->SetDrawColor(15, 31, 61);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($originX, $originY - $chartH, $originX, $originY); // axe Y
        $pdf->Line($originX, $originY, $originX + $nbBars * ($barW + $gap), $originY); // axe X

        // ── Barres ────────────────────────────────────────────────────
        foreach ($statsByDay as $i => $row) {
            $barH = ($maxCount > 0) ? ($row['count'] / $maxCount) * $chartH : 0;
            $x    = $originX + $i * ($barW + $gap) + 2; // +2 pour l'offset
            $y    = $originY - $barH;

            // Couleur dégradée selon la hauteur de la barre
            $intensity = $maxCount > 0 ? ($row['count'] / $maxCount) : 0;
            $r = (int)(37  + (1 - $intensity) * 100);
            $g = (int)(99  + (1 - $intensity) * 80);
            $b = 235;

            $pdf->SetFillColor($r, $g, $b);
            $pdf->Rect($x, $y, $barW, $barH, 'F');

            // Label valeur au-dessus
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetTextColor(15, 31, 61);
            $pdf->SetXY($x, $y - 6);
            $pdf->Cell($barW, 5, $row['count'], 0, 0, 'C');

            // Label date en dessous
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetXY($x, $originY + 2);
            $dayLabel = date('d/m', strtotime($row['day']));
            $pdf->Cell($barW, 5, $dayLabel, 0, 0, 'C');
        }

        // Titre des axes
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY($originX, $originY + 10);
        $pdf->Cell(0, 5, 'Dates (dd/mm)', 0, 1, 'C');

        // Légende
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(15, 31, 61);
        $pdf->SetFillColor(37, 99, 235);
        $pdf->Rect($pdf->GetX() + 60, $pdf->GetY() + 1, 5, 5, 'F');
        $pdf->Cell(70, 7, '', 0, 0);
        $pdf->Cell(40, 7, '  Nouvelles inscriptions', 0, 1);
    }

    // ── Output ─────────────────────────────────────────────────────────
    switch ($output) {
        case 'F':
            $pdf->Output($filePath, 'F');
            return $filePath;
        case 'S':
            return $pdf->Output('', 'S');
        case 'D':
        default:
            $pdf->Output('rapport_eventhub_' . $eventId . '.pdf', 'D');
    }
}

// Point d'entrée GET
if (php_sapi_name() !== 'cli' && isset($_GET['event_id'])) {
    // TODO : vérifier session organisateur
    $pdo = getDB();
    generateReportPDF($pdo, (int)$_GET['event_id'], 'D');
}

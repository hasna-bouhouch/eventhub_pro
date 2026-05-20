<?php
/**
 * EventHub Pro — events/register.php  [COMPLÉTÉ - Parties 2.1 + 2.2 + 4.1]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../mail/SendConfirmation.php';
require_once __DIR__ . '/../mail/AlertMailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
$name    = isset($data['name'])     ? trim($data['name'])     : '';
$email   = isset($data['email'])    ? trim($data['email'])    : '';

if (!$eventId || !$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données manquantes ou invalides.']);
    exit;
}

try {
    $pdo = getDB();

    // ── Récupérer l'événement avec compteur d'inscrits ────────────────
    $stmt = $pdo->prepare(
        'SELECT e.*,
                COUNT(r.id) AS registered_count
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE e.id = :id
         GROUP BY e.id'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Événement introuvable.']);
        exit;
    }

    if ($event['registered_count'] >= $event['capacity']) {
        echo json_encode(['success' => false, 'error' => 'Événement complet.', 'full' => true]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM registrations WHERE event_id = :eid AND email = :email'
    );
    $stmt->execute([':eid' => $eventId, ':email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Vous êtes déjà inscrit(e) à cet événement.']);
        exit;
    }

    // ════════════════════════════════════════════════════════════════════
    // PARTIE 2.1 — Insérer l'inscription avec token unique
    // ════════════════════════════════════════════════════════════════════
    // bin2hex(random_bytes(32)) génère un token de 64 caractères hex
    // cryptographiquement aléatoire, impossible à deviner.
    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO registrations (event_id, name, email, token, registered_at)
         VALUES (:event_id, :name, :email, :token, NOW())'
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':name'     => $name,
        ':email'    => $email,
        ':token'    => $token,
    ]);

    $registrationId = (int)$pdo->lastInsertId();

    // ════════════════════════════════════════════════════════════════════
    // PARTIE 2.1 — Envoyer l'email de confirmation
    // ════════════════════════════════════════════════════════════════════
    // On attrape l'exception séparément : un échec email ne doit pas
    // annuler l'inscription déjà enregistrée en BD.
    $emailSent = false;
    try {
        $emailSent = SendConfirmation::send($pdo, $event, $name, $email, $token);
    } catch (Exception $e) {
        error_log('[EventHub] Confirmation email failed: ' . $e->getMessage());
    }

    // ════════════════════════════════════════════════════════════════════
    // PARTIE 2.2 — Détecter le seuil 80% et envoyer l'alerte
    //
    // SOLUTION ANTI-DOUBLON : colonne alert_sent dans la table events.
    // Avant d'envoyer, on vérifie alert_sent = 0.
    // Après envoi réussi, on le passe à 1 via UPDATE atomique.
    // Ainsi, même si 2 inscriptions arrivent simultanément, une seule
    // alerte sera envoyée (la première UPDATE qui trouve alert_sent = 0).
    // ════════════════════════════════════════════════════════════════════
    $newCount  = $event['registered_count'] + 1;
    $pct       = ($newCount / $event['capacity']) * 100;
    $alertSent = false;

    if ($pct >= 80 && $event['alert_sent'] == 0) {
        // Marquer AVANT l'envoi (verrou logique) pour éviter les doublons
        // même en cas de requêtes concurrentes.
        $lockStmt = $pdo->prepare(
            'UPDATE events SET alert_sent = 1 WHERE id = :id AND alert_sent = 0'
        );
        $lockStmt->execute([':id' => $eventId]);

        // rowCount() = 1 signifie que NOUS avons posé le verrou (personne d'autre)
        if ($lockStmt->rowCount() === 1) {
            try {
                // Mettre à jour le compteur dans $event pour AlertMailer
                $event['registered_count'] = $newCount;
                $alertSent = AlertMailer::sendCapacityAlert($pdo, $event);
            } catch (Exception $e) {
                error_log('[EventHub] Alert email failed: ' . $e->getMessage());
                // En cas d'échec d'envoi, remettre alert_sent à 0 pour pouvoir réessayer
                $pdo->prepare('UPDATE events SET alert_sent = 0 WHERE id = :id')
                    ->execute([':id' => $eventId]);
            }
        }
    }

    // ── Réponse JSON ──────────────────────────────────────────────────
    echo json_encode([
        'success'         => true,
        'registration_id' => $registrationId,
        'token'           => $token,
        'capacity_pct'    => round($pct),
        'is_full'         => ($newCount >= $event['capacity']),
        'alert_sent'      => $alertSent,
        'email_sent'      => $emailSent,
    ]);

} catch (PDOException $e) {
    error_log('[EventHub] register.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}

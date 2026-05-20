<?php
/**
 * EventHub Pro — api/stats.php  [COMPLÉTÉ - Partie 4.2]
 */

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

// Vérification session organisateur
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'organizer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès réservé aux organisateurs.']);
    exit;
}

try {
    $pdo = getDB();

    // ── Résumé global (une seule requête) ─────────────────────────────
    $summary = $pdo->query(
        'SELECT
            COUNT(DISTINCT e.id)                                        AS total_events,
            COALESCE(SUM(sub.cnt), 0)                                   AS total_registered,
            COALESCE(SUM(CASE WHEN r24.cnt IS NOT NULL THEN r24.cnt ELSE 0 END), 0) AS new_last_24h,
            COALESCE(ROUND(AVG(sub.cnt / e.capacity * 100)), 0)         AS avg_fill_pct,
            COALESCE(SUM(CASE WHEN sub.cnt / e.capacity >= 0.8 THEN 1 ELSE 0 END), 0) AS alert_count
         FROM events e
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS cnt
             FROM registrations
             GROUP BY event_id
         ) sub ON sub.event_id = e.id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS cnt
             FROM registrations
             WHERE registered_at >= NOW() - INTERVAL 24 HOUR
             GROUP BY event_id
         ) r24 ON r24.event_id = e.id'
    )->fetch();

    // ── Top 3 événements les plus remplis ─────────────────────────────
    $top3 = $pdo->query(
        'SELECT e.id, e.title, e.capacity,
                COUNT(r.id)                             AS registered,
                ROUND(COUNT(r.id) / e.capacity * 100)  AS fill_pct
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         GROUP BY e.id
         ORDER BY fill_pct DESC
         LIMIT 3'
    )->fetchAll();

    // ── Tous les événements avec leurs stats ──────────────────────────
    $perEvent = $pdo->query(
        'SELECT e.id, e.title, e.capacity,
                COUNT(r.id)                             AS registered,
                ROUND(COUNT(r.id) / e.capacity * 100)  AS fill_pct,
                (COUNT(r.id) >= e.capacity)             AS is_full
         FROM events e
         LEFT JOIN registrations r ON r.event_id = e.id
         GROUP BY e.id
         ORDER BY e.event_date ASC'
    )->fetchAll();

    // ── Inscriptions par jour — 7 derniers jours ──────────────────────
    $byDay = $pdo->query(
        'SELECT DATE(registered_at) AS day, COUNT(*) AS count
         FROM registrations
         WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY DATE(registered_at)
         ORDER BY day ASC'
    )->fetchAll();

    echo json_encode([
        'success'              => true,
        'generated_at'         => date('Y-m-d H:i:s'),
        'summary'              => $summary,
        'top3'                 => $top3,
        'per_event'            => $perEvent,
        'registrations_by_day' => $byDay,
    ]);

} catch (PDOException $e) {
    error_log('[EventHub] api/stats.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}

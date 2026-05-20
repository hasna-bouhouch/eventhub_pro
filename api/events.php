<?php
/**
 * EventHub Pro — api/events.php  [COMPLÉTÉ - Partie 1.3]
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

$params = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = file_get_contents('php://input');
    $params = json_decode($body, true) ?? [];
} else {
    $params = $_GET;
}

$keyword   = isset($params['keyword'])    ? trim($params['keyword'])    : '';
$category  = isset($params['category'])   ? trim($params['category'])   : '';
$dateFrom  = isset($params['date_from'])  ? trim($params['date_from'])  : '';
$dateTo    = isset($params['date_to'])    ? trim($params['date_to'])    : '';
$hasPlaces = isset($params['has_places']) ? (bool)$params['has_places'] : false;
$page      = isset($params['page'])       ? max(1, (int)$params['page']): 1;
$perPage   = 6;

try {
    $pdo    = getDB();
    $result = searchEvents($pdo, $keyword, $category, $dateFrom, $dateTo, $hasPlaces, $page, $perPage);

    echo json_encode([
        'success' => true,
        'data'    => $result['events'],
        'meta'    => [
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($result['total'] / $perPage),
        ]
    ]);

} catch (Exception $e) {
    error_log('[EventHub] api/events.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}


// ═════════════════════════════════════════════════════════════════════════
// PARTIE 1.3 — searchEvents() avec filtres dynamiques
//
// STRATÉGIE CHOISIE : tableau $conditions[] + tableau $bindings[]
// Justification : cette approche est la plus lisible et la plus sûre.
// Chaque filtre actif ajoute une condition SQL et une valeur liée.
// L'assemblage final avec implode(' AND ', $conditions) garantit
// qu'aucune concaténation directe de valeur utilisateur n'entre
// dans la chaîne SQL → protection totale contre l'injection SQL.
// ═════════════════════════════════════════════════════════════════════════

function searchEvents(
    PDO    $pdo,
    string $keyword   = '',
    string $category  = '',
    string $dateFrom  = '',
    string $dateTo    = '',
    bool   $hasPlaces = false,
    int    $page      = 1,
    int    $perPage   = 6
): array {

    $baseSelect = "SELECT e.id,
                          e.title,
                          e.description,
                          e.event_date,
                          e.location,
                          e.capacity,
                          e.category,
                          e.organizer_email,
                          COUNT(r.id)                             AS registered_count,
                          (e.capacity - COUNT(r.id))              AS available_places,
                          ROUND(COUNT(r.id) / e.capacity * 100)   AS fill_percentage
                   FROM   events e
                   LEFT JOIN registrations r ON r.event_id = e.id";

    $conditions = [];
    $bindings   = [];

    // Filtre mot-clé : recherche dans titre ET description
    if ($keyword !== '') {
        $conditions[]          = '(e.title LIKE :keyword OR e.description LIKE :keyword)';
        $bindings[':keyword']  = '%' . $keyword . '%';
    }

    // Filtre catégorie : correspondance exacte
    if ($category !== '') {
        $conditions[]          = 'e.category = :category';
        $bindings[':category'] = $category;
    }

    // Filtre date de début
    if ($dateFrom !== '') {
        $conditions[]           = 'e.event_date >= :date_from';
        $bindings[':date_from'] = $dateFrom . ' 00:00:00';
    }

    // Filtre date de fin
    if ($dateTo !== '') {
        $conditions[]         = 'e.event_date <= :date_to';
        $bindings[':date_to'] = $dateTo . ' 23:59:59';
    }

    // Filtre places disponibles : exclure les événements complets
    // Note : HAVING est nécessaire car available_places est calculé par GROUP BY
    // On utilise donc une sous-requête COUNT pour pouvoir filtrer avant GROUP BY
    // Alternative propre : on ajoute la condition dans HAVING après GROUP BY
    $havingConditions = [];

    if ($hasPlaces) {
        // Cette condition est sur une valeur agrégée → HAVING, pas WHERE
        $havingConditions[] = 'available_places > 0';
    }

    // Assemblage WHERE
    $sql = $baseSelect;
    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' GROUP BY e.id';

    // Assemblage HAVING
    if (!empty($havingConditions)) {
        $sql .= ' HAVING ' . implode(' AND ', $havingConditions);
    }

    $sql .= ' ORDER BY e.event_date ASC';

    // ── Requête COUNT pour la pagination (sans LIMIT) ─────────────────
    // On enveloppe la requête principale dans un COUNT(*) pour obtenir
    // le total réel, nécessaire au calcul du nombre de pages.
    $countSql  = "SELECT COUNT(*) FROM ($sql) AS subcount";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($bindings);
    $total = (int)$stmtCount->fetchColumn();

    // ── Pagination : LIMIT + OFFSET ───────────────────────────────────
    $offset = ($page - 1) * $perPage;
    $sql   .= ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);

    // On bind les filtres manuellement pour pouvoir ajouter LIMIT/OFFSET en entier
    foreach ($bindings as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();

    return ['events' => $events, 'total' => $total];
}

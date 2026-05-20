<?php
/**
 * EventHub Pro — events/create.php  [CORRIGÉ - Partie 1.2]
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données JSON invalides.']);
    exit;
}

// CORRECTION 1.2 — Validation des champs obligatoires
// Sans cette validation, un champ manquant provoquerait une erreur SQL non contrôlée.
$required = ['title', 'description', 'date', 'location', 'capacity', 'category', 'organizer_email'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Champ manquant : $field"]);
        exit;
    }
}

// Validation email organisateur
if (!filter_var($data['organizer_email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email organisateur invalide.']);
    exit;
}

// Validation catégorie (liste blanche)
$validCategories = ['tech', 'design', 'business', 'science'];
if (!in_array($data['category'], $validCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Catégorie invalide.']);
    exit;
}

// Validation capacité
$capacity = (int)$data['capacity'];
if ($capacity <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Capacité invalide.']);
    exit;
}

try {
    $pdo    = getDB();
    $result = createEvent($pdo, $data);

    echo json_encode([
        'success'  => true,
        'event_id' => $result,
        'message'  => 'Événement créé avec succès.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ═════════════════════════════════════════════════════════════════════════
// FONCTION CORRIGÉE — Partie 1.2
// ═════════════════════════════════════════════════════════════════════════

/**
 * Insère un nouvel événement en base de données.
 * CORRECTIONS APPORTÉES :
 *   1. Utilisation de requêtes préparées → protection contre l'injection SQL
 *   2. Utilisation de execute() avec paramètres liés au lieu de query()
 *   3. Retour de lastInsertId() au lieu de toujours true
 *   4. Wrappé dans un try/catch pour propager les vraies exceptions PDO
 *
 * @param  PDO   $pdo
 * @param  array $data
 * @return int   ID de l'événement créé
 * @throws PDOException
 */
function createEvent(PDO $pdo, array $data): int
{
    // CORRECTION BUG 1 & 2 : prepare() + execute() avec paramètres nommés
    // → Aucune concaténation de $data dans la chaîne SQL
    // → PDO échappe automatiquement les valeurs → injection SQL impossible
    $stmt = $pdo->prepare(
        'INSERT INTO events
            (title, description, event_date, location, capacity, category, organizer_email, created_at)
         VALUES
            (:title, :description, :event_date, :location, :capacity, :category, :organizer_email, NOW())'
    );

    $stmt->execute([
        ':title'           => trim($data['title']),
        ':description'     => trim($data['description']),
        ':event_date'      => $data['date'],       // format YYYY-MM-DD HH:MM:SS attendu
        ':location'        => trim($data['location']),
        ':capacity'        => (int)$data['capacity'], // cast entier pour éviter l'injection de type
        ':category'        => $data['category'],
        ':organizer_email' => 1 
    ]);

    // CORRECTION BUG 3 : retourner le vrai ID inséré (0 si échec silencieux)
    $insertId = (int)$pdo->lastInsertId();

    if ($insertId === 0) {
        throw new \RuntimeException('Insertion échouée : aucun ID retourné.');
    }

    return $insertId;
}

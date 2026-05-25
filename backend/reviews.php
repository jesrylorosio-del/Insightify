<?php
// ═══════════════════════════════════════════════════
//  Insightify — api/reviews.php
//  Place this in: /Insightify/api/reviews.php
// ═══════════════════════════════════════════════════

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://insightify.page.gd');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── DB — same credentials as your insights.php ──────
try {
    $pdo = new PDO(
    "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
    "if0_41842235",
    "4FCv5M0ZuyFQR7"
);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
    exit;
}

// ── Auto-create reviews table if it doesn't exist ───
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `reviews` (
        `id`           INT(11)      NOT NULL AUTO_INCREMENT,
        `full_name`    VARCHAR(100) NOT NULL,
        `position`     VARCHAR(100) NOT NULL,
        `location`     VARCHAR(100) NOT NULL,
        `review_text`  TEXT         NOT NULL,
        `rating`       TINYINT(1)   NOT NULL DEFAULT 5,
        `is_approved`  TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: return all approved 5-star reviews ──────────
if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT id, full_name, position, location, review_text, rating, created_at
        FROM reviews
        WHERE rating = 5 AND is_approved = 1
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'reviews' => $rows]);
    exit;
}

// ── POST: save a new review ──────────────────────────
if ($method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true);
    $full_name   = trim($body['full_name']   ?? '');
    $position    = trim($body['position']    ?? '');
    $location    = trim($body['location']    ?? '');
    $review_text = trim($body['review_text'] ?? '');
    $rating      = intval($body['rating']    ?? 0);

    if (!$full_name || !$position || !$location || !$review_text) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    if ($rating < 1 || $rating > 5) {
        echo json_encode(['success' => false, 'message' => 'Please select a rating.']);
        exit;
    }
    if (strlen($review_text) < 10) {
        echo json_encode(['success' => false, 'message' => 'Review is too short (min 10 characters).']);
        exit;
    }

    $full_name   = htmlspecialchars($full_name,   ENT_QUOTES, 'UTF-8');
    $position    = htmlspecialchars($position,    ENT_QUOTES, 'UTF-8');
    $location    = htmlspecialchars($location,    ENT_QUOTES, 'UTF-8');
    $review_text = htmlspecialchars($review_text, ENT_QUOTES, 'UTF-8');

    // 5-star = show publicly, anything else = saved but hidden
    $is_approved = ($rating === 5) ? 1 : 0;

    $stmt = $pdo->prepare("
        INSERT INTO reviews (full_name, position, location, review_text, rating, is_approved)
        VALUES (:full_name, :position, :location, :review_text, :rating, :is_approved)
    ");
    $stmt->execute([
        ':full_name'   => $full_name,
        ':position'    => $position,
        ':location'    => $location,
        ':review_text' => $review_text,
        ':rating'      => $rating,
        ':is_approved' => $is_approved,
    ]);

    $newId = $pdo->lastInsertId();

    if ($rating === 5) {
        echo json_encode([
            'success' => true,
            'message' => 'Thank you! Your review is now live.',
            'review'  => [
                'id'          => $newId,
                'full_name'   => $full_name,
                'position'    => $position,
                'location'    => $location,
                'review_text' => $review_text,
                'rating'      => $rating,
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Thank you for your feedback! Only 5-star reviews appear on the site.'
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
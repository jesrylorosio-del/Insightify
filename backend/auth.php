<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Access-Control-Allow-Origin: https://insightify.page.gd');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
header('Content-Type: application/json');

try {
    $pdo = new PDO(
        "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
        "if0_41842235",
        "4FCv5M0ZuyFQR7"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'DB: ' . $e->getMessage()]);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

// ── SIGNUP ────────────────────────────────────────────────────
if ($action === 'signup') {
    $firstname = trim($body['firstname'] ?? '');
    $lastname  = trim($body['lastname']  ?? '');
    $email     = trim($body['email']     ?? '');
    $password  = trim($body['password']  ?? '');

    if (!$firstname || !$lastname || !$email || !$password) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }
    if (strlen($password) < 6) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Email already registered. Please login.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, 'user')");
    $stmt->execute([$firstname, $lastname, $email, hash('sha256', $password)]);
    $newId = $pdo->lastInsertId();

    // Store full session data
    $_SESSION['user_id']        = $newId;
    $_SESSION['user_name']      = $firstname . ' ' . $lastname;
    $_SESSION['user_firstname'] = $firstname;
    $_SESSION['user_lastname']  = $lastname;

    ob_clean();
    echo json_encode([
        'success' => true,
        'user' => [
            'id'        => $newId,
            'name'      => $firstname . ' ' . $lastname,
            'firstname' => $firstname,
            'lastname'  => $lastname,
            'email'     => $email,
            'role'      => 'user',
        ]
    ]);
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($body['email']    ?? '');
    $password = trim($body['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['password'] !== hash('sha256', $password)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

    // Store full session data including firstname/lastname
    $_SESSION['user_id']        = $user['id'];
    $_SESSION['user_name']      = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_firstname'] = $user['first_name'];
    $_SESSION['user_lastname']  = $user['last_name'];

    ob_clean();
    echo json_encode([
        'success' => true,
        'user' => [
            'id'        => $user['id'],
            'name'      => $user['first_name'] . ' ' . $user['last_name'],
            'firstname' => $user['first_name'],
            'lastname'  => $user['last_name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ]
    ]);
    exit;
}

// ── CHECK SESSION ─────────────────────────────────────────────
// FIX: Was merged with logout — this was destroying the session on every page load.
// Now it only reads the session, never destroys it.
if ($action === 'check') {
    if (isset($_SESSION['user_id'])) {
        ob_clean();
        echo json_encode([
            'success'   => true,
            'logged_in' => true,
            'user' => [
                'id'        => $_SESSION['user_id'],
                'name'      => $_SESSION['user_name']      ?? '',
                'firstname' => $_SESSION['user_firstname'] ?? '',
                'lastname'  => $_SESSION['user_lastname']  ?? '',
            ]
        ]);
    } else {
        ob_clean();
        echo json_encode(['success' => true, 'logged_in' => false]);
    }
    exit;
}

// ── LOGOUT ────────────────────────────────────────────────────
// FIX: Now a separate block — only logout destroys the session.
if ($action === 'logout') {
    session_unset();
    session_destroy();
    ob_clean();
    echo json_encode(['success' => true, 'logged_in' => false]);
    exit;
}

ob_clean();
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
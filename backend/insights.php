<?php

session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://insightify.page.gd');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

// ── LOGOUT ────────────────────────────────────────────────────
if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
    exit;
}

// ── DB CONNECTION ─────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
        "if0_41842235",
        "4FCv5M0ZuyFQR7"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // FIX: Enable emulated prepares so named params work correctly in all queries
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

// ── AUTH CHECK ────────────────────────────────────────────────
$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── IMPORT ────────────────────────────────────────────────────
if ($action === 'import') {
    $input = json_decode(file_get_contents('php://input'), true);
    $rows  = $input['rows'] ?? [];

    if (empty($rows)) {
        echo json_encode(['success' => false, 'error' => 'No rows provided']);
        exit;
    }

    $inserted = 0;
    $errors   = [];

    $stmt = $pdo->prepare("
        INSERT INTO transactions
            (user_id, product_id, customer_name, product_name, total_amount, quantity, status, payment_method, created_at)
        VALUES
            (:user_id, NULL, :customer_name, :product_name, :amount, 1, :status, :method, :created_at)
    ");

    foreach ($rows as $row) {
        try {
            $raw    = $row['formatted_date'] ?? '';
            $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : date('Y-m-d');
            $status = strtolower(trim($row['status'] ?? 'completed'));

            // Map payment methods to allowed ENUM values
            $rawMethod = strtolower(trim($row['payment_method'] ?? ''));
            if (str_contains($rawMethod, 'credit') || str_contains($rawMethod, 'visa') || str_contains($rawMethod, 'card')) {
                $method = 'Credit Card';
            } elseif (str_contains($rawMethod, 'bank') || str_contains($rawMethod, 'transfer')) {
                $method = 'Bank Transfer';
            } elseif (str_contains($rawMethod, 'digital') || str_contains($rawMethod, 'wallet') || str_contains($rawMethod, 'paypal') || str_contains($rawMethod, 'gcash')) {
                $method = 'Digital Wallet';
            } else {
                $method = 'Other';
            }

            $stmt->execute([
                ':user_id'       => $uid,
                ':customer_name' => $row['customer_name'] ?? 'Unknown',
                ':product_name'  => $row['product_name']  ?? 'N/A',
                ':amount'        => floatval($row['total_amount']),
                ':status'        => $status,
                ':method'        => $method,
                ':created_at'    => $date . ' 00:00:00',
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $errors[] = $e->getMessage();
        }
    }

    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'errors'   => $errors,
        'message'  => 'Imported ' . $inserted . ' transactions'
    ]);
    exit;
}

// ── REVENUE KPI ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE
            WHEN MONTH(created_at) = MONTH(CURDATE())
             AND YEAR(created_at)  = YEAR(CURDATE())
            THEN total_amount END), 0) AS this_month,
        COALESCE(SUM(CASE
            WHEN MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
             AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)
            THEN total_amount END), 0) AS last_month
    FROM transactions
    WHERE status = 'completed' AND user_id = :uid
");
$stmt->execute([':uid' => $uid]);
$rev = $stmt->fetch(PDO::FETCH_ASSOC);
$curr_rev   = (float) $rev['this_month'];
$prev_rev   = (float) $rev['last_month'];
$rev_change = $prev_rev > 0
    ? round((($curr_rev - $prev_rev) / $prev_rev) * 100, 1)
    : 0;

// ── AVG LTV ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(total_amount), 0) AS total_rev,
        COUNT(DISTINCT user_id)        AS unique_customers
    FROM transactions
    WHERE status = 'completed' AND user_id = :uid
");
$stmt->execute([':uid' => $uid]);
$ltv_row = $stmt->fetch(PDO::FETCH_ASSOC);
$avg_ltv = $ltv_row['unique_customers'] > 0
    ? round($ltv_row['total_rev'] / $ltv_row['unique_customers'], 2)
    : 0;

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) THEN total_amount END), 0) /
        NULLIF(COUNT(DISTINCT CASE WHEN MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) THEN user_id END), 0) AS this_ltv,
        COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(CURDATE()-INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(CURDATE()-INTERVAL 1 MONTH) THEN total_amount END), 0) /
        NULLIF(COUNT(DISTINCT CASE WHEN MONTH(created_at)=MONTH(CURDATE()-INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(CURDATE()-INTERVAL 1 MONTH) THEN user_id END), 0) AS last_ltv
    FROM transactions WHERE status='completed' AND user_id = :uid
");
$stmt->execute([':uid' => $uid]);
$ltv_row2   = $stmt->fetch(PDO::FETCH_ASSOC);
$this_ltv   = (float)($ltv_row2['this_ltv'] ?? 0);
$last_ltv   = (float)($ltv_row2['last_ltv'] ?? 0);
$ltv_change = $last_ltv > 0 ? round((($this_ltv - $last_ltv) / $last_ltv) * 100, 1) : 0;

// ── CHURN ─────────────────────────────────────────────────────
// FIX: Rewrote churn queries to avoid duplicate named params (:uid/:uid2 in same execute()).
// Now uses a single :uid per query — safer and cleaner.

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) AS last_month_customers
    FROM transactions
    WHERE status = 'completed'
      AND user_id = :uid
      AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
      AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)
");
$stmt->execute([':uid' => $uid]);
$last_m_cust = (int) $stmt->fetch(PDO::FETCH_ASSOC)['last_month_customers'];

// Use a subquery with $uid embedded safely via prepare — no duplicate params
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) AS churned
    FROM transactions
    WHERE status = 'completed'
      AND user_id = :uid
      AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
      AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)
      AND user_id NOT IN (
          SELECT DISTINCT user_id
          FROM transactions
          WHERE status = 'completed'
            AND user_id = :uid
            AND MONTH(created_at) = MONTH(CURDATE())
            AND YEAR(created_at)  = YEAR(CURDATE())
      )
");
// FIX: With ATTR_EMULATE_PREPARES = true (set above), the same named param
// can appear multiple times in one query safely.
$stmt->execute([':uid' => $uid]);
$churned = (int) $stmt->fetch(PDO::FETCH_ASSOC)['churned'];

$churn_pct = $last_m_cust > 0
    ? round(($churned / $last_m_cust) * 100, 1)
    : 0;

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) AS prev_churned
    FROM transactions
    WHERE status = 'completed'
      AND user_id = :uid
      AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 2 MONTH)
      AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 2 MONTH)
      AND user_id NOT IN (
          SELECT DISTINCT user_id
          FROM transactions
          WHERE status = 'completed'
            AND user_id = :uid
            AND MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
            AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)
      )
");
$stmt->execute([':uid' => $uid]);
$prev_churned = (int) $stmt->fetch(PDO::FETCH_ASSOC)['prev_churned'];
$churn_change = $prev_churned > 0
    ? round((($churned - $prev_churned) / $prev_churned) * 100, 1)
    : 0;

// ── ACTIVE SESSIONS ───────────────────────────────────────────
$stmt = $pdo->query("
    SELECT COUNT(*) AS active
    FROM users
    WHERE last_login >= NOW() - INTERVAL 30 MINUTE
");
$active_sessions = (int) $stmt->fetch(PDO::FETCH_ASSOC)['active'];

// ── SALES TREND ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(created_at, '%b %Y') AS month_label,
        DATE_FORMAT(created_at, '%Y-%m') AS month_sort,
        ROUND(SUM(total_amount), 2)       AS total
    FROM transactions
    WHERE status = 'completed'
      AND user_id = :uid
      AND created_at >= NOW() - INTERVAL 12 MONTH
    GROUP BY month_sort, month_label
    ORDER BY month_sort ASC
");
$stmt->execute([':uid' => $uid]);
$sales_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

$trend_with_target = [];
foreach ($sales_trend as $i => $row) {
    $target = $i > 0
        ? round((float)$sales_trend[$i - 1]['total'] * 1.10, 2)
        : round((float)$row['total'] * 0.95, 2);
    $trend_with_target[] = [
        'label'  => $row['month_label'],
        'actual' => (float) $row['total'],
        'target' => $target,
    ];
}

// ── PAYMENT DISTRIBUTION ──────────────────────────────────────
$payment_dist = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(payment_method, 'Other') AS method,
            ROUND(SUM(total_amount), 2)        AS total,
            COUNT(*)                           AS count
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $stmt->execute([':uid' => $uid]);
    $raw_pay = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pay_sum = array_sum(array_column($raw_pay, 'total'));
    foreach ($raw_pay as $p) {
        $payment_dist[] = [
            'method' => $p['method'],
            'total'  => (float) $p['total'],
            'count'  => (int)   $p['count'],
            'pct'    => $pay_sum > 0 ? round(($p['total'] / $pay_sum) * 100, 1) : 0,
        ];
    }
} catch (PDOException $e) {
    $payment_dist = [];
}

// ── RECENT TRANSACTIONS ───────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        t.id,
        t.total_amount,
        t.status,
        t.created_at,
        t.quantity,
        t.payment_method,
        COALESCE(t.customer_name, CONCAT(u.first_name, ' ', u.last_name), u.email, 'Unknown') AS customer_name,
        COALESCE(t.product_name, p.name, 'N/A') AS product_name,
        COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.email, 'Unknown') AS user_name
    FROM transactions t
    LEFT JOIN users    u ON t.user_id    = u.id
    LEFT JOIN products p ON t.product_id = p.id
    WHERE t.user_id = :uid
    ORDER BY t.created_at DESC
    LIMIT 999999
");
$stmt->execute([':uid' => $uid]);
$recent_txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent_txns as &$tx) {
    $tx['total_amount']   = (float) $tx['total_amount'];
    $tx['quantity']       = (int)   $tx['quantity'];
    $tx['tx_ref']         = 'TXN-' . str_pad($tx['id'], 13, '0', STR_PAD_LEFT) . '-' . ($tx['id'] - 1);
    $tx['formatted_date'] = date('Y-m-d', strtotime($tx['created_at']));
}
unset($tx);

// ── TOP PRODUCTS ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        COALESCE(p.name, t.product_name, 'Unknown') AS name,
        SUM(t.quantity)     AS units_sold,
        SUM(t.total_amount) AS revenue
    FROM transactions t
    LEFT JOIN products p ON t.product_id = p.id
    WHERE t.status = 'completed' AND t.user_id = :uid
    GROUP BY name
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([':uid' => $uid]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$max_rev = !empty($top_products) ? (float)$top_products[0]['revenue'] : 1;
foreach ($top_products as &$pr) {
    $pr['units_sold'] = (int)   $pr['units_sold'];
    $pr['revenue']    = (float) $pr['revenue'];
    $pr['pct']        = round(($pr['revenue'] / $max_rev) * 100);
}
unset($pr);

// ── EXTRA STATS ───────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE DATE(created_at)=CURDATE() AND status='completed' AND user_id = :uid");
$stmt->execute([':uid' => $uid]);
$txns_today = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

$stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE last_login >= NOW()-INTERVAL 7 DAY OR created_at >= NOW()-INTERVAL 7 DAY");
$active_users = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

$stmt = $pdo->prepare("SELECT COALESCE(AVG(total_amount),0) AS a FROM transactions WHERE status='completed' AND user_id = :uid AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$stmt->execute([':uid' => $uid]);
$avg_order = round((float) $stmt->fetch(PDO::FETCH_ASSOC)['a'], 2);

$stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='returned') AS returned FROM transactions WHERE user_id = :uid");
$stmt->execute([':uid' => $uid]);
$r        = $stmt->fetch(PDO::FETCH_ASSOC);
$ret_rate = $r['total'] > 0 ? round(($r['returned'] / $r['total']) * 100, 1) : 0;

$stmt = $pdo->prepare("SELECT HOUR(created_at) AS h, COUNT(*) AS c FROM transactions WHERE DATE(created_at)=CURDATE() AND user_id = :uid GROUP BY h ORDER BY c DESC LIMIT 1");
$stmt->execute([':uid' => $uid]);
$peak      = $stmt->fetch(PDO::FETCH_ASSOC);
$peak_hour = $peak ? date('g:i A', mktime($peak['h'], 0, 0)) : 'N/A';

$stmt        = $pdo->query("SELECT COUNT(*) AS c FROM users");
$total_users = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

// ── OUTPUT ────────────────────────────────────────────────────
echo json_encode([
    'stats' => [
        'user_name'    => $_SESSION['user_name'] ?? 'Analyst User',
        'txns_today'   => $txns_today,
        'active_users' => $active_users,
        'avg_order'    => $avg_order,
        'return_rate'  => $ret_rate,
        'peak_hour'    => $peak_hour,
        'total_users'  => $total_users,
    ],
    'kpi' => [
        'revenue' => [
            'total'      => $curr_rev,
            'formatted'  => '$' . number_format($curr_rev / 1000, 1) . 'k',
            'change_pct' => $rev_change,
            'direction'  => $rev_change >= 0 ? 'up' : 'down',
        ],
        'avg_ltv' => [
            'total'      => $avg_ltv,
            'formatted'  => '$' . number_format($avg_ltv, 0),
            'change_pct' => $ltv_change,
            'direction'  => $ltv_change >= 0 ? 'up' : 'down',
        ],
        'churn' => [
            'pct'       => $churn_pct,
            'change'    => $churn_change,
            'direction' => $churn_change >= 0 ? 'up' : 'down',
        ],
        'active_sessions' => $active_sessions,
    ],
    'sales_trend'  => $trend_with_target,
    'payment_dist' => $payment_dist,
    'recent_txns'  => $recent_txns,
    'top_products' => $top_products,
], JSON_PRETTY_PRINT);
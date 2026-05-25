<?php
ini_set('display_errors', 0);
error_reporting(0);

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

try {
    $pdo = new PDO(
        "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
        "if0_41842235",
        "4FCv5M0ZuyFQR7"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'product_performance') {

    // ── Product Leaderboard ───────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(product_name, 'Unknown') AS product_name,
            SUM(quantity)                      AS units_sold,
            ROUND(SUM(total_amount), 2)        AS revenue,
            COUNT(*)                           AS order_count,
            ROUND(AVG(total_amount), 2)        AS avg_order_value
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY product_name
        ORDER BY revenue DESC
    ");
    $stmt->execute([':uid' => $uid]);
    $products = $stmt->fetchAll();

    $totalRev = array_sum(array_column($products, 'revenue'));
    foreach ($products as &$p) {
        $p['revenue']         = (float) $p['revenue'];
        $p['units_sold']      = (int)   $p['units_sold'];
        $p['order_count']     = (int)   $p['order_count'];
        $p['avg_order_value'] = (float) $p['avg_order_value'];
        $p['revenue_share']   = $totalRev > 0
            ? round(($p['revenue'] / $totalRev) * 100, 1)
            : 0;
    }
    unset($p);

    // ── Trending / Declining / Stable ─────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(product_name, 'Unknown') AS product_name,
            ROUND(SUM(CASE
                WHEN MONTH(created_at) = MONTH(CURDATE())
                 AND YEAR(created_at)  = YEAR(CURDATE())
                THEN total_amount END), 2) AS this_month,
            ROUND(SUM(CASE
                WHEN MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
                 AND YEAR(created_at)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)
                THEN total_amount END), 2) AS last_month
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY product_name
    ");
    $stmt->execute([':uid' => $uid]);
    $monthlyStatus = $stmt->fetchAll();
    $statusMap = [];
    foreach ($monthlyStatus as $ms) {
        $curr = (float)($ms['this_month'] ?? 0);
        $prev = (float)($ms['last_month'] ?? 0);
        if ($prev == 0 && $curr > 0) {
            $statusMap[$ms['product_name']] = 'trending';
        } elseif ($prev > 0 && $curr == 0) {
            $statusMap[$ms['product_name']] = 'declining';
        } elseif ($prev > 0) {
            $changePct = (($curr - $prev) / $prev) * 100;
            $statusMap[$ms['product_name']] = $changePct >= 10 ? 'trending' : ($changePct <= -10 ? 'declining' : 'stable');
        } else {
            $statusMap[$ms['product_name']] = 'stable';
        }
    }
    foreach ($products as &$p) {
        $p['status'] = $statusMap[$p['product_name']] ?? 'stable';
    }
    unset($p);

    // ── Monthly Trend ─────────────────────────────────────
    $topProducts = array_slice(array_column($products, 'product_name'), 0, 5);
    if (empty($topProducts)) {
        echo json_encode(['products' => [], 'monthly_trend' => [], 'total_revenue' => 0.0]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($topProducts), '?'));
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(product_name, 'Unknown')  AS product_name,
            DATE_FORMAT(created_at, '%b %Y')   AS month_label,
            DATE_FORMAT(created_at, '%Y-%m')   AS month_sort,
            ROUND(SUM(total_amount), 2)         AS revenue
        FROM transactions
        WHERE status = 'completed'
          AND user_id = ?
          AND created_at >= NOW() - INTERVAL 6 MONTH
          AND product_name IN ($placeholders)
        GROUP BY product_name, month_sort, month_label
        ORDER BY month_sort ASC, revenue DESC
    ");
    $stmt->execute(array_merge([$uid], $topProducts));
    $monthlyTrend = $stmt->fetchAll();
    foreach ($monthlyTrend as &$m) {
        $m['revenue'] = (float) $m['revenue'];
    }
    unset($m);

    echo json_encode([
        'products'      => $products,
        'monthly_trend' => $monthlyTrend,
        'total_revenue' => (float) $totalRev,
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'sales_intelligence') {

    // ── KPIs ──────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                    AS total_orders,
            ROUND(SUM(total_amount), 2) AS total_revenue,
            ROUND(AVG(total_amount), 2) AS avg_order_value,
            COUNT(DISTINCT customer_name) AS total_customers
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
    ");
    $stmt->execute([':uid' => $uid]);
    $kpis = $stmt->fetch();

    // ── Best Product ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT product_name, SUM(quantity) AS units_sold
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY product_name
        ORDER BY units_sold DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $uid]);
    $best = $stmt->fetch();
    $kpis['best_product']  = $best['product_name'] ?? null;
    $kpis['best_units']    = (int)($best['units_sold'] ?? 0);

    // ── Revenue by Product ────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(product_name, 'Unknown') AS product_name,
            ROUND(SUM(total_amount), 2)        AS revenue
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY product_name
        ORDER BY revenue DESC
    ");
    $stmt->execute([':uid' => $uid]);
    $revenueByProduct = $stmt->fetchAll();
    foreach ($revenueByProduct as &$r) $r['revenue'] = (float)$r['revenue'];
    unset($r);

    // ── Order Status ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS count
        FROM transactions
        WHERE user_id = :uid
        GROUP BY status
    ");
    $stmt->execute([':uid' => $uid]);
    $orderStatus = $stmt->fetchAll();
    foreach ($orderStatus as &$o) $o['count'] = (int)$o['count'];
    unset($o);

    // ── Sales by Day of Week ──────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            DAYNAME(created_at)             AS day_name,
            DAYOFWEEK(created_at)           AS day_num,
            ROUND(AVG(total_amount), 2)     AS avg_revenue
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY day_name, day_num
        ORDER BY day_num ASC
    ");
    $stmt->execute([':uid' => $uid]);
    $salesByDay = $stmt->fetchAll();
    foreach ($salesByDay as &$d) $d['avg_revenue'] = (float)$d['avg_revenue'];
    unset($d);

    // ── Top Customers ─────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(customer_name, 'Unknown') AS customer_name,
            ROUND(SUM(total_amount), 2)         AS total_revenue,
            COUNT(*)                            AS order_count
        FROM transactions
        WHERE status = 'completed' AND user_id = :uid
        GROUP BY customer_name
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $stmt->execute([':uid' => $uid]);
    $topCustomers = $stmt->fetchAll();
    foreach ($topCustomers as &$c) {
        $c['total_revenue'] = (float)$c['total_revenue'];
        $c['order_count']   = (int)$c['order_count'];
    }
    unset($c);

    echo json_encode([
        'kpis'              => $kpis,
        'revenue_by_product'=> $revenueByProduct,
        'order_status'      => $orderStatus,
        'sales_by_day'      => $salesByDay,
        'top_customers'     => $topCustomers,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . $action]);
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

try {
    $pdo = new PDO(
    "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
    "if0_41842235",
    "4FCv5M0ZuyFQR7"
);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function si($v) { return (int)   ($v ?? 0); }
function sf($v) { return (float) ($v ?? 0); }

$action = $_GET['action'] ?? '';

if ($action === 'reports_overview') {

    // ── Scorecard ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status='completed' THEN total_amount END), 0) AS total_revenue,
            COUNT(*) AS total_orders,
            COUNT(CASE WHEN status='completed' THEN 1 END) AS completed_orders
        FROM transactions WHERE user_id = :uid
    ");
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch();

    $totalUsers    = si($pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c']);
	$activeUsers   = si($pdo->query("SELECT COUNT(*) AS c FROM users WHERE last_login >= NOW() - INTERVAL 7 DAY")->fetch()['c']);
	$stmt = $pdo->prepare("SELECT COUNT(DISTINCT product_name) AS c FROM transactions WHERE user_id = :uid AND product_name IS NOT NULL AND product_name != ''");
	$stmt->execute([':uid' => $uid]);
	$totalProducts = si($stmt->fetch()['c']);

	$scorecard = [
    	'total_revenue'    => sf($row['total_revenue']),
   		'total_orders'     => si($row['total_orders']),
    	'completed_orders' => si($row['completed_orders']),
    	'total_products'   => $totalProducts,
    	'total_users'      => $totalUsers,
    	'active_users'     => $activeUsers,
	];

    // ── Recent Activity ───────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT t.status, t.total_amount, t.created_at,
            COALESCE(t.customer_name, CONCAT(u.first_name,' ',u.last_name), u.email, 'Unknown') AS customer_name,
            COALESCE(t.product_name, p.name, 'Unknown Product') AS product_name
        FROM transactions t
        LEFT JOIN users    u ON t.user_id    = u.id
        LEFT JOIN products p ON t.product_id = p.id
        WHERE t.user_id = :uid
        ORDER BY t.created_at DESC LIMIT 12
    ");
    $stmt->execute([':uid' => $uid]);
    $txns = $stmt->fetchAll();

    $recentActivity = [];
    foreach ($txns as $t) {
        $amt = '$' . number_format(sf($t['total_amount']), 2);
        $c   = $t['customer_name'];
        $pr  = $t['product_name'];
        switch ($t['status']) {
            case 'completed': $msg = "{$c} purchased {$pr} for {$amt}"; break;
            case 'failed':    $msg = "Payment failed — {$c} · {$pr} ({$amt})"; break;
            case 'pending':   $msg = "Order pending — {$c} · {$pr} ({$amt})"; break;
            default:          $msg = "Transaction ({$t['status']}) — {$c} · {$pr} ({$amt})";
        }
        $recentActivity[] = ['type' => $t['status'], 'message' => $msg, 'created_at' => $t['created_at']];
    }

    $newUsers = $pdo->query("SELECT CONCAT(first_name,' ',last_name) AS full_name, email, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();
    foreach ($newUsers as $u) {
        $name = trim($u['full_name']) ?: $u['email'];
        $recentActivity[] = ['type' => 'new_user', 'message' => "New user registered: {$name}", 'created_at' => $u['created_at']];
    }
    usort($recentActivity, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    $recentActivity = array_slice($recentActivity, 0, 15);

    // ── Weekly Highlights ─────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY AND status='completed' THEN total_amount END), 0) AS this_week_revenue,
            COALESCE(SUM(CASE WHEN created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY - INTERVAL 7 DAY AND created_at < DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY AND status='completed' THEN total_amount END), 0) AS last_week_revenue,
            COUNT(CASE WHEN created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY AND status='completed' THEN 1 END) AS this_week_orders,
            COUNT(CASE WHEN created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY - INTERVAL 7 DAY AND created_at < DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY AND status='completed' THEN 1 END) AS last_week_orders
        FROM transactions WHERE user_id = :uid
    ");
    $stmt->execute([':uid' => $uid]);
    $wk = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COALESCE(product_name,'Unknown') AS product_name, SUM(total_amount) AS rev FROM transactions WHERE status='completed' AND user_id=:uid AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY product_name ORDER BY rev DESC LIMIT 1");
    $stmt->execute([':uid' => $uid]);
    $bestProd = $stmt->fetch();

    $thisRev = sf($wk['this_week_revenue']); $lastRev = sf($wk['last_week_revenue']);
    $thisOrders = si($wk['this_week_orders']); $lastOrders = si($wk['last_week_orders']);
    $newUsersWk = si($pdo->query("SELECT COUNT(*) AS c FROM users WHERE created_at >= DATE(NOW()) - INTERVAL WEEKDAY(NOW()) DAY")->fetch()['c']);

    $weekly = [
        'revenue'        => $thisRev,
        'revenue_change' => $lastRev    > 0 ? round((($thisRev    - $lastRev)    / $lastRev)    * 100, 1) : 0,
        'orders'         => $thisOrders,
        'orders_change'  => $lastOrders > 0 ? round((($thisOrders - $lastOrders) / $lastOrders) * 100, 1) : 0,
        'new_users'      => $newUsersWk,
        'best_product'   => $bestProd ? $bestProd['product_name'] : null,
    ];

    // ── Daily Revenue ─────────────────────────────────────
    $stmt = $pdo->prepare("SELECT DATE(created_at) AS date, ROUND(SUM(total_amount),2) AS revenue FROM transactions WHERE status='completed' AND user_id=:uid AND created_at >= NOW() - INTERVAL 14 DAY GROUP BY DATE(created_at) ORDER BY date ASC");
    $stmt->execute([':uid' => $uid]);
    $rawDaily = $stmt->fetchAll();
    $dailyMap = [];
    foreach ($rawDaily as $r) $dailyMap[$r['date']] = sf($r['revenue']);
    $dailyRevenue = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $dailyRevenue[] = ['date' => $d, 'revenue' => $dailyMap[$d] ?? 0];
    }

    // ── Top Products This Week ────────────────────────────
    $stmt = $pdo->prepare("SELECT COALESCE(product_name,'Unknown') AS product_name, ROUND(SUM(total_amount),2) AS revenue, SUM(quantity) AS units_sold FROM transactions WHERE status='completed' AND user_id=:uid AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY product_name ORDER BY revenue DESC LIMIT 5");
    $stmt->execute([':uid' => $uid]);
    $topProductsWeek = $stmt->fetchAll();
    foreach ($topProductsWeek as &$p) { $p['revenue']=sf($p['revenue']); $p['units_sold']=si($p['units_sold']); } unset($p);

    // ── Data Health ───────────────────────────────────────
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid"); $stmt->execute([':uid'=>$uid]); $totalTxns=si($stmt->fetch()['c']);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid AND status='failed'"); $stmt->execute([':uid'=>$uid]); $failedTxns=si($stmt->fetch()['c']);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid AND status='pending'"); $stmt->execute([':uid'=>$uid]); $pendingTxns=si($stmt->fetch()['c']);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid AND (product_name IS NULL OR product_name='')"); $stmt->execute([':uid'=>$uid]); $nullProdTxns=si($stmt->fetch()['c']);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid AND (customer_name IS NULL OR customer_name='')"); $stmt->execute([':uid'=>$uid]); $nullCustTxns=si($stmt->fetch()['c']);
    $neverLogged = si($pdo->query("SELECT COUNT(*) AS c FROM users WHERE last_login IS NULL")->fetch()['c']);

    $health = [
        'total_transactions'   => $totalTxns,
        'total_users'          => $totalUsers,
        'total_products'       => $totalProducts,
        'failed_transactions'  => $failedTxns,
        'pending_transactions' => $pendingTxns,
        'null_product_txns'    => $nullProdTxns,
        'null_customer_txns'   => $nullCustTxns,
        'users_never_logged_in'=> $neverLogged,
    ];

    echo json_encode(['scorecard'=>$scorecard,'recent_activity'=>$recentActivity,'weekly'=>$weekly,'daily_revenue'=>$dailyRevenue,'top_products_week'=>$topProductsWeek,'health'=>$health], JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'export') {
    $type = $_GET['type'] ?? '';
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

    if ($type === 'transactions') {
        $stmt = $pdo->prepare("SELECT CONCAT('#TX-',LPAD(t.id,5,'0')) AS tx_ref, COALESCE(t.customer_name,CONCAT(u.first_name,' ',u.last_name),'Unknown') AS customer_name, COALESCE(t.product_name,p.name,'N/A') AS product_name, DATE_FORMAT(t.created_at,'%Y-%m-%d') AS formatted_date, t.status, COALESCE(t.payment_method,'Other') AS payment_method, ROUND(t.total_amount,2) AS total_amount FROM transactions t LEFT JOIN users u ON t.user_id=u.id LEFT JOIN products p ON t.product_id=p.id WHERE t.user_id=:uid AND DATE(t.created_at) BETWEEN :from AND :to ORDER BY t.created_at DESC");
        $stmt->execute([':uid'=>$uid,':from'=>$from,':to'=>$to]);
        $rows=$stmt->fetchAll(); foreach($rows as &$r) $r['total_amount']=sf($r['total_amount']); unset($r);
        echo json_encode(['rows'=>$rows]); exit;
    }

    if ($type === 'products') {
        $stmt = $pdo->prepare("SELECT COALESCE(t.product_name,p.name,'Unknown') AS product_name, SUM(t.quantity) AS units_sold, ROUND(SUM(t.total_amount),2) AS revenue, ROUND(AVG(t.total_amount),2) AS avg_order_value FROM transactions t LEFT JOIN products p ON t.product_id=p.id WHERE t.status='completed' AND t.user_id=:uid AND DATE(t.created_at) BETWEEN :from AND :to GROUP BY product_name ORDER BY revenue DESC");
        $stmt->execute([':uid'=>$uid,':from'=>$from,':to'=>$to]);
        $rows=$stmt->fetchAll(); $totalRev=array_sum(array_column($rows,'revenue'));
        foreach($rows as &$r){$r['revenue']=sf($r['revenue']);$r['avg_order_value']=sf($r['avg_order_value']);$r['units_sold']=si($r['units_sold']);$r['revenue_share']=$totalRev>0?round(($r['revenue']/$totalRev)*100,1):0;} unset($r);
        echo json_encode(['rows'=>$rows]); exit;
    }

    if ($type === 'revenue') {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label, DATE_FORMAT(created_at,'%Y-%m') AS month_sort, COUNT(*) AS order_count, ROUND(SUM(total_amount),2) AS revenue, ROUND(AVG(total_amount),2) AS avg_order FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :from AND :to GROUP BY month_sort, month_label ORDER BY month_sort ASC");
        $stmt->execute([':uid'=>$uid,':from'=>$from,':to'=>$to]);
        $rows=$stmt->fetchAll(); foreach($rows as &$r){$r['revenue']=sf($r['revenue']);$r['avg_order']=sf($r['avg_order']);$r['order_count']=si($r['order_count']);} unset($r);
        echo json_encode(['rows'=>$rows]); exit;
    }

    if ($type === 'summary') {
        $p1=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS r FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t"); $p1->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $sumRev=sf($p1->fetch()['r']);
        $p2=$pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE user_id=:uid AND DATE(created_at) BETWEEN :f AND :t"); $p2->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $sumOrders=si($p2->fetch()['c']);
        $p3=$pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t"); $p3->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $sumComp=si($p3->fetch()['c']);
        $p4=$pdo->prepare("SELECT COUNT(*) AS c FROM transactions WHERE status='failed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t"); $p4->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $sumFail=si($p4->fetch()['c']);
        $p5=$pdo->prepare("SELECT COUNT(DISTINCT customer_name) AS c FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t"); $p5->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $uniqCust=si($p5->fetch()['c']);
        $p6=$pdo->prepare("SELECT COALESCE(product_name,'Unknown') AS pn, SUM(total_amount) AS r FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t GROUP BY pn ORDER BY r DESC LIMIT 1"); $p6->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $topProd=$p6->fetch();
        $p7=$pdo->prepare("SELECT COALESCE(customer_name,'Unknown') AS cn, SUM(total_amount) AS r FROM transactions WHERE status='completed' AND user_id=:uid AND DATE(created_at) BETWEEN :f AND :t GROUP BY cn ORDER BY r DESC LIMIT 1"); $p7->execute([':uid'=>$uid,':f'=>$from,':t'=>$to]); $topCust=$p7->fetch();
        $avgOrd=$sumComp>0?round($sumRev/$sumComp,2):0;
        echo json_encode(['rows'=> [
            ['metric'=>'Report Period','value'=>"$from to $to"],
            ['metric'=>'Total Revenue','value'=>'$'.number_format($sumRev,2)],
            ['metric'=>'Total Orders','value'=>$sumOrders],
            ['metric'=>'Completed Orders','value'=>$sumComp],
            ['metric'=>'Failed Orders','value'=>$sumFail],
            ['metric'=>'Avg Order Value','value'=>'$'.number_format($avgOrd,2)],
            ['metric'=>'Unique Customers','value'=>$uniqCust],
            ['metric'=>'Top Product','value'=>$topProd?$topProd['pn']:'N/A'],
            ['metric'=>'Top Product Revenue','value'=>$topProd?'$'.number_format(sf($topProd['r']),2):'$0.00'],
            ['metric'=>'Top Customer','value'=>$topCust?$topCust['cn']:'N/A'],
            ['metric'=>'Top Customer Spend','value'=>$topCust?'$'.number_format(sf($topCust['r']),2):'$0.00'],
        ]]); exit;
    }

    echo json_encode(['error'=>'Unknown export type: '.$type]); exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Unknown action: ' . $action]); 


<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://insightify.page.gd');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Buffer all output so errors don't break JSON
ob_start();

session_start();
header('Content-Type: application/json');

// Test DB
try {
    $pdo = new PDO(
    "mysql:host=sql103.infinityfree.com;dbname=if0_41842235_insightify;charset=utf8mb4",
    "if0_41842235",
    "4FCv5M0ZuyFQR7"
);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? 'import';

if ($action === 'preview') {
    ob_end_clean();
    handlePreview($pdo);
} elseif ($action === 'confirm') {
    ob_end_clean();
    handleConfirm($pdo);
} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}


/**
 * Insightify — import_transactions.php
 * Place this file in the same /api/ folder as insights.php
 * Requires: composer require phpoffice/phpspreadsheet
 * Or use the manual install instructions in the README comment below.
 *
 * ── INSTALL PhpSpreadsheet (one-time) ──────────────────────────────────────
 *   cd /path/to/your/project
 *   composer require phpoffice/phpspreadsheet
 * ──────────────────────────────────────────────────────────────────────────
 */

ini_set('display_errors', 0);
error_reporting(0);

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── DB connection (same as insights.php) ─────────────────────────────────
try {
    $pdo = new PDO("mysql:host=localhost;dbname=insightify;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
    exit;
}

// ── Action router ─────────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'import';

if ($action === 'preview') {
    handlePreview($pdo);
} elseif ($action === 'confirm') {
    handleConfirm($pdo);
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

// ═════════════════════════════════════════════════════════════════════════
//  PREVIEW — parse the Excel file and return rows for the user to review
// ═════════════════════════════════════════════════════════════════════════
function handlePreview($pdo) {
    ob_clean();
    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
        return;
    }

    $file     = $_FILES['file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['xlsx', 'xls', 'csv'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Only .xlsx, .xls, or .csv files are allowed.']);
        return;
    }

    if ($ext === 'csv') {
        $rows = parseCsv($file['tmp_name']);
    } else {
        $rows = parseExcel($file['tmp_name'], $ext);
    }

    if (isset($rows['error'])) {
        echo json_encode(['success' => false, 'error' => $rows['error']]);
        return;
    }

    // Validate & enrich each row
    $required = ['customer_name', 'product_name', 'date', 'status', 'amount'];
    $valid    = [];
    $errors   = [];

    foreach ($rows as $i => $row) {
        $rowNum = $i + 1;
        $rowErrors = [];

        // Normalize keys
        $r = [];
        foreach ($row as $k => $v) {
            $r[strtolower(trim($k))] = trim((string)$v);
        }

        // Check required fields
        foreach ($required as $field) {
            if (!isset($r[$field]) || $r[$field] === '') {
                $rowErrors[] = "Missing '$field'";
            }
        }

        // Validate status
        $validStatuses = ['completed', 'pending', 'failed', 'returned'];
        if (isset($r['status']) && !in_array(strtolower($r['status']), $validStatuses)) {
            $rowErrors[] = "Invalid status '{$r['status']}' (allowed: " . implode(', ', $validStatuses) . ")";
        }

        // Validate amount
        if (isset($r['amount']) && !is_numeric($r['amount'])) {
            $rowErrors[] = "Amount must be a number";
        }

        // Validate date
        if (isset($r['date']) && $r['date'] !== '') {
            $d = date_create($r['date']);
            if (!$d) {
                $rowErrors[] = "Invalid date format (use YYYY-MM-DD)";
            } else {
                $r['date'] = date_format($d, 'Y-m-d H:i:s');
            }
        }

        // Check duplicate tx_id in DB
        $dupWarning = null;
        if (!empty($r['tx_id'])) {
            // Strip #TX- prefix to get numeric id if present
            $txNum = preg_replace('/[^0-9]/', '', $r['tx_id']);
            if ($txNum !== '') {
                $stmt = $pdo->prepare("SELECT id FROM transactions WHERE id = ?");
                $stmt->execute([$txNum]);
                if ($stmt->fetch()) {
                    $dupWarning = "TX ID already exists — will be skipped";
                }
            }
        }

        if (!empty($rowErrors)) {
            $errors[] = ['row' => $rowNum, 'errors' => $rowErrors, 'data' => $r];
        } else {
            $valid[] = [
                'row'            => $rowNum,
                'tx_id'          => $r['tx_id'] ?? '',
                'customer_name'  => $r['customer_name'],
                'product_name'   => $r['product_name'],
                'date'           => $r['date'],
                'status'         => strtolower($r['status']),
                'payment_method' => $r['payment_method'] ?? 'Other',
                'amount'         => (float) $r['amount'],
                'dup_warning'    => $dupWarning,
            ];
        }
    }

    echo json_encode([
        'success'      => true,
        'total'        => count($rows),
        'valid_count'  => count($valid),
        'error_count'  => count($errors),
        'valid_rows'   => $valid,
        'error_rows'   => $errors,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════
//  CONFIRM — insert the validated rows into the DB
// ═════════════════════════════════════════════════════════════════════════
function handleConfirm($pdo) {
    ob_clean();  
    $input = json_decode(file_get_contents('php://input'), true);
    $rows  = $input['rows'] ?? [];

    if (empty($rows)) {
        echo json_encode(['success' => false, 'error' => 'No rows to import.']);
        return;
    }

    // Look up user_id and product_id from names
    $inserted = 0;
    $skipped  = 0;
    $failLog  = [];

    $stmtInsert = $pdo->prepare("
        INSERT INTO transactions
            (user_id, product_id, total_amount, status, payment_method, quantity, created_at)
        VALUES
            (:user_id, :product_id, :amount, :status, :payment_method, 1, :created_at)
    ");

    $stmtUser    = $pdo->prepare("SELECT id FROM users WHERE CONCAT(first_name,' ',last_name) = ? OR email = ? LIMIT 1");
    $stmtProduct = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");

    foreach ($rows as $row) {
        // Skip duplicates flagged during preview
        if (!empty($row['dup_warning'])) {
            $skipped++;
            continue;
        }

        // Resolve user
        $stmtUser->execute([$row['customer_name'], $row['customer_name']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Auto-create a minimal user record so the import doesn't fail
            $nameParts = explode(' ', $row['customer_name'], 2);
            $pdo->prepare("INSERT INTO users (first_name, last_name, email, created_at) VALUES (?,?,?,NOW())")
                ->execute([
                    $nameParts[0],
                    $nameParts[1] ?? '',
                    strtolower(str_replace(' ', '.', $row['customer_name'])) . '@imported.local'
                ]);
            $userId = $pdo->lastInsertId();
        } else {
            $userId = $user['id'];
        }

        // Resolve product
        $stmtProduct->execute([$row['product_name']]);
        $product = $stmtProduct->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            // Auto-create product
            $pdo->prepare("INSERT INTO products (name, price, created_at) VALUES (?,?,NOW())")
                ->execute([$row['product_name'], $row['amount']]);
            $productId = $pdo->lastInsertId();
        } else {
            $productId = $product['id'];
        }

        try {
            $stmtInsert->execute([
                ':user_id'        => $userId,
                ':product_id'     => $productId,
                ':amount'         => $row['amount'],
                ':status'         => $row['status'],
                ':payment_method' => $row['payment_method'],
                ':created_at'     => $row['date'],
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++;
            $failLog[] = "Row {$row['row']}: " . $e->getMessage();
        }
    }

    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => $failLog,
        'message'  => "$inserted transaction(s) imported successfully." . ($skipped > 0 ? " $skipped skipped." : ''),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════
//  PARSERS
// ═════════════════════════════════════════════════════════════════════════
function parseExcel($tmpPath, $ext) {
    if ($ext === 'xlsx') {
        return parseXlsxNative($tmpPath);
    }
    return ['error' => 'Legacy .xls format is not supported. Please save as .xlsx or .csv and try again.'];
}

/**
 * Native xlsx parser (no dependencies) — reads shared strings + sheet XML
 */
function parseXlsxNative($tmpPath) {
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        return ['error' => 'Cannot open xlsx file.'];
    }

    // Shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->si as $si) {
            $sharedStrings[] = (string)$si->t ?? implode('', array_map(fn($r) => (string)$r->t, $si->r ?? []));
        }
    }

    // Sheet1
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if (!$sheetXml) return ['error' => 'Cannot read sheet data from xlsx.'];

    $sheet = simplexml_load_string($sheetXml);
    $rawRows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowArr = [];
        foreach ($row->c as $cell) {
            $t = (string)($cell['t'] ?? '');
            $v = (string)($cell->v ?? '');
            if ($t === 's') $v = $sharedStrings[(int)$v] ?? '';
            $rowArr[] = $v;
        }
        $rawRows[] = $rowArr;
    }

    return rowsFromArray($rawRows);
}

function rowsFromArray(array $data): array {
    // Find header row (skip info/blank rows at top)
    $headerRow = null;
    $startIdx  = 0;
    $requiredHeaders = ['customer_name', 'amount', 'status'];

    foreach ($data as $i => $row) {
        $lower = array_map(fn($c) => strtolower(trim((string)$c)), $row);
        $matches = array_intersect($requiredHeaders, $lower);
        if (count($matches) >= 2) {
            $headerRow = $lower;
            $startIdx  = $i + 1;
            break;
        }
    }

    if (!$headerRow) return ['error' => 'Could not find header row. Make sure your file uses the Insightify template headers.'];

    $rows = [];
    for ($i = $startIdx; $i < count($data); $i++) {
        $row = $data[$i];
        // Skip fully empty rows
        $vals = array_filter(array_map('trim', array_map('strval', $row)));
        if (empty($vals)) continue;

        $mapped = [];
        foreach ($headerRow as $col => $header) {
            $mapped[$header] = isset($row[$col]) ? trim((string)$row[$col]) : '';
        }
        $rows[] = $mapped;
    }
    return $rows;
}

function parseCsv($tmpPath): array {
    $handle = fopen($tmpPath, 'r');
    if (!$handle) return ['error' => 'Cannot open CSV file.'];

    $headers = null;
    $rows    = [];
    while (($line = fgetcsv($handle)) !== false) {
        if (!$headers) {
            $headers = array_map(fn($h) => strtolower(trim($h)), $line);
            continue;
        }
        if (count(array_filter($line)) === 0) continue;
        $rows[] = array_combine($headers, array_pad($line, count($headers), ''));
    }
    fclose($handle);
    return $rows;
}
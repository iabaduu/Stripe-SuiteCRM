<?php
/**
 * Webhook per Stripe -> SuiteCRM
 * Versione: 3.1 (Fix Utente Database + Fix Tabelle Custom)
 */

// --- CONFIGURAZIONE AGGIORNATA ---
$config = [
    'db_host' => 'localhost',
    'db_name' => 'iabasuite2',          // Nome DB Corretto
    'db_user' => 'iabaduu2',            // <--- CORRETTO: Era 'iabaduu', ora è 'iabaduu2'
    'db_password' => 'Ciapalacadrega2025',
    'api_key' => '4e9a8f2c7b1d6e0c5a3b9d8f0a7e1c2b4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f90', 
    'default_user_id' => '1',
    'currency_id' => '-99' 
];

// --- HELPER FUNCTIONS ---
function writeLog($message) {
    $date = date('Y-m-d H:i:s');
    $logFile = __DIR__ . '/stripe_receiver_log.txt';
    file_put_contents($logFile, "[$date] $message" . PHP_EOL, FILE_APPEND);
}

function generateUuid() {
    if (function_exists('random_bytes')) { $data = random_bytes(16); } 
    else { $data = openssl_random_pseudo_bytes(16); }
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// --- INIZIO ESECUZIONE ---
$headers = getallheaders();
$apiKeyInput = $_GET['api_key'] ?? $headers['X-Api-Key'] ?? null;

if ($apiKeyInput !== $config['api_key']) {
    writeLog("Tentativo non autorizzato. IP: " . $_SERVER['REMOTE_ADDR']);
    sendJsonResponse(['error' => 'Unauthorized'], 401);
}

try {
    $input = file_get_contents('php://input');
    $event = json_decode($input, true);

    if (!$event) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo "Webhook attivo su iabasuite2."; exit; }
        throw new Exception("Payload JSON vuoto.");
    }

    if (isset($event['type']) && $event['type'] !== 'invoice.payment_succeeded') {
        writeLog("Evento ignorato: " . $event['type']);
        sendJsonResponse(['message' => 'Event ignored'], 200);
    }

    // --- ESTRAZIONE DATI ---
    $dataObject = $event['data']['object'];
    
    $stripeEmail = $dataObject['customer_email'] ?? null;
    $stripeName  = $dataObject['customer_name'] ?? $stripeEmail;
    $amountPaid  = $dataObject['amount_paid'] ?? 0; 
    
    // Logic Date: se scadenza <= inizio, forza +1 anno
    $periodStartUnix = $dataObject['lines']['data'][0]['period']['start'] ?? time();
    $periodEndUnix   = $dataObject['lines']['data'][0]['period']['end'] ?? 0;
    
    if ($periodEndUnix <= $periodStartUnix) {
        $periodEndUnix = strtotime('+1 year', $periodStartUnix);
    }
    
    $startDate = date('Y-m-d', $periodStartUnix);
    $endDate   = date('Y-m-d', $periodEndUnix);
    
    $contractValue = $amountPaid / 100;

    writeLog("Processing: $stripeEmail | DB: {$config['db_name']} | User: {$config['db_user']}");

    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = date('Y-m-d H:i:s');
    $userId = $config['default_user_id'];
    $accountId = null;
    $contactId = null;

    // --- 1. ACCOUNT ---
    $stmt = $db->prepare("SELECT id FROM accounts WHERE name = ? AND deleted = 0");
    $stmt->execute([$stripeName]);
    $accountRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($accountRow) {
        $accountId = $accountRow['id'];
        writeLog("Account esistente: $accountId");
    } else {
        $accountId = generateUuid();
        writeLog("Nuovo Account: $stripeName");
        $stmt = $db->prepare("INSERT INTO accounts (id, name, date_entered, date_modified, created_by, assigned_user_id, deleted) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $stmt->execute([$accountId, $stripeName, $now, $now, $userId, $userId]);
        
        // Fix Custom Fields Table
        try { $db->prepare("INSERT INTO accounts_cstm (id_c) VALUES (?)")->execute([$accountId]); } catch(Exception $e){}
    }

    // --- 2. CONTACT ---
    if ($stripeEmail) {
        $stmt = $db->prepare("SELECT id FROM email_addresses WHERE email_address = ? AND deleted = 0");
        $stmt->execute([$stripeEmail]);
        $emailRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emailRow) {
            $emailId = $emailRow['id'];
            $stmt = $db->prepare("SELECT bean_id FROM email_addr_bean_rel WHERE email_address_id = ? AND bean_module = 'Contacts' AND deleted = 0");
            $stmt->execute([$emailId]);
            $relRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($relRow) $contactId = $relRow['bean_id'];
        }

        if (!$contactId) {
            $contactId = generateUuid();
            writeLog("Nuovo Contatto: $stripeEmail");
            
            $parts = explode(' ', $stripeName, 2);
            $fName = $parts[0];
            $lName = $parts[1] ?? '-';

            $stmt = $db->prepare("INSERT INTO contacts (id, first_name, last_name, date_entered, date_modified, created_by, assigned_user_id, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$contactId, $fName, $lName, $now, $now, $userId, $userId]);
            
            // Fix Custom Fields Table
            try { $db->prepare("INSERT INTO contacts_cstm (id_c) VALUES (?)")->execute([$contactId]); } catch(Exception $e){}

            if (!$emailRow) {
                $emailId = generateUuid();
                $stmt = $db->prepare("INSERT INTO email_addresses (id, email_address, email_address_caps, date_created, deleted) VALUES (?, ?, ?, ?, 0)");
                $stmt->execute([$emailId, $stripeEmail, strtoupper($stripeEmail), $now]);
            } else {
                $emailId = $emailRow['id'];
            }

            $relId = generateUuid();
            $stmt = $db->prepare("INSERT INTO email_addr_bean_rel (id, email_address_id, bean_id, bean_module, primary_address, date_created, deleted) VALUES (?, ?, ?, 'Contacts', 1, ?, 0)");
            $stmt->execute([$relId, $emailId, $contactId, $now]);
        }
    }

    // --- 3. LINK ACCOUNT-CONTACT ---
    if ($accountId && $contactId) {
        $stmt = $db->prepare("SELECT id FROM accounts_contacts WHERE account_id = ? AND contact_id = ? AND deleted = 0");
        $stmt->execute([$accountId, $contactId]);
        if (!$stmt->fetch()) {
            $acRelId = generateUuid();
            $stmt = $db->prepare("INSERT INTO accounts_contacts (id, account_id, contact_id, date_modified, deleted) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([$acRelId, $accountId, $contactId, $now]);
        }
    }

    // --- 4. CONTRACT ---
    $contractId = generateUuid();
    $contractName = "SaaS Subscription - " . date('Y') . " - " . $stripeName;
    $contractType = 'Service'; 
    $status = 'Signed';
    $renewalDate = date('Y-m-d', strtotime($endDate . ' -30 days'));

    $sqlContract = "INSERT INTO aos_contracts 
        (id, name, date_entered, date_modified, modified_user_id, created_by, assigned_user_id, deleted,
         total_contract_value, start_date, end_date, status, renewal_reminder_date, 
         currency_id, contract_account_id, contact_id, contract_type)
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $db->prepare($sqlContract);
    $stmt->execute([
        $contractId, $contractName, $now, $now, $userId, $userId, $userId,
        $contractValue, $startDate, $endDate, $status, $renewalDate,
        $config['currency_id'], $accountId, $contactId, $contractType
    ]);
    
    // Fix Custom Fields Table
    try {
        $db->prepare("INSERT INTO aos_contracts_cstm (id_c) VALUES (?)")->execute([$contractId]);
    } catch (Exception $e) {
        // Ignora se la tabella non esiste
    }

    writeLog("Contratto creato su $config[db_name]: $contractId");

    sendJsonResponse(['success' => true, 'contract_id' => $contractId]);

} catch (Exception $e) {
    writeLog("CRITICAL ERROR: " . $e->getMessage());
    sendJsonResponse(['error' => $e->getMessage()], 500);
}
?>

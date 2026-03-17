<?php
/**
 * Webhook per Stripe -> SuiteCRM
 * Versione: 6.1 (Single Event, Trial = Lead, Paid = Customer + Campo Custom Contatto)
 */

// --- CONFIGURAZIONE ---
$config = [
    'db_host' => 'localhost',
    'db_name' => 'iabaduu',          
    'db_user' => 'iabaduu',            
    'db_password' => 'iabaduu',
    'api_key' => 'iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2iabaduu2', 
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
    sendJsonResponse(['error' => 'Unauthorized'], 401);
}

try {
    $input = file_get_contents('php://input');
    $event = json_decode($input, true);

    if (!$event) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo "Webhook attivo v6.1"; exit; }
        throw new Exception("Payload JSON vuoto.");
    }

    // Ascoltiamo SOLO invoice.payment_succeeded
    if (isset($event['type']) && $event['type'] !== 'invoice.payment_succeeded') {
        sendJsonResponse(['message' => 'Event ignored'], 200);
    }

    $db = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = date('Y-m-d H:i:s');
    $userId = $config['default_user_id'];
    $dataObject = $event['data']['object'];

    // --- ESTRAZIONE DATI COMUNI DALL'INVOICE ---
    $amountPaid = $dataObject['amount_paid'] ?? 0; 
    
    $stripeEmail = $dataObject['customer_email'] ?? null;
    $stripeName  = $dataObject['customer_name'] ?? $stripeEmail;
    $stripeCustomerId = $dataObject['customer'] ?? '';
    $phone = $dataObject['customer_phone'] ?? $dataObject['metadata']['telefono'] ?? '';

    // Estrazione Nome e Cognome
    $firstName = $dataObject['metadata']['nome'] ?? '';
    $lastName  = $dataObject['metadata']['cognome'] ?? '';
    if (empty($firstName) && empty($lastName)) {
        $parts = explode(' ', $stripeName, 2);
        $firstName = $parts[0];
        $lastName = $parts[1] ?? 'Sconosciuto';
    }

    // Estrazione Indirizzo (Nell'invoice si trova in customer_address)
    $address = $dataObject['customer_address'] ?? [];
    $city = $address['city'] ?? $dataObject['metadata']['city'] ?? '';
    $state = $address['state'] ?? $dataObject['metadata']['provincia'] ?? '';
    $postal_code = $address['postal_code'] ?? '';
    $country = $address['country'] ?? $dataObject['metadata']['country'] ?? '';
    $address_line = $address['line1'] ?? $dataObject['metadata']['line1'] ?? '';


    // =====================================================================================
    // RAMO 1: IMPORTO ZERO (TRIAL) -> CREA LEAD
    // =====================================================================================
    if ($amountPaid == 0) {
        writeLog("Trial rilevato (Importo 0). Elaborazione Lead: $stripeName | Email: $stripeEmail");
        $leadId = null;

        if ($stripeEmail) {
            $stmt = $db->prepare("SELECT id FROM email_addresses WHERE email_address = ? AND deleted = 0");
            $stmt->execute([$stripeEmail]);
            $emailRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($emailRow) {
                $emailId = $emailRow['id'];
                $stmt = $db->prepare("SELECT bean_id FROM email_addr_bean_rel WHERE email_address_id = ? AND bean_module = 'Leads' AND deleted = 0");
                $stmt->execute([$emailId]);
                $relRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($relRow) $leadId = $relRow['bean_id'];
            }

            if (!$leadId) {
                $leadId = generateUuid();
                
                $sqlLead = "INSERT INTO leads 
                    (id, first_name, last_name, phone_work, primary_address_street, primary_address_city, primary_address_state, primary_address_postalcode, primary_address_country, date_entered, date_modified, created_by, assigned_user_id, deleted, status, lead_source) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'New', 'Web Site')";
                
                $stmt = $db->prepare($sqlLead);
                $stmt->execute([
                    $leadId, $firstName, $lastName, $phone, $address_line, $city, $state, $postal_code, $country, 
                    $now, $now, $userId, $userId
                ]);
                
                try { $db->prepare("INSERT INTO leads_cstm (id_c) VALUES (?)")->execute([$leadId]); } catch(Exception $e){}

                if (!$emailRow) {
                    $emailId = generateUuid();
                    $stmt = $db->prepare("INSERT INTO email_addresses (id, email_address, email_address_caps, date_created, deleted) VALUES (?, ?, ?, ?, 0)");
                    $stmt->execute([$emailId, $stripeEmail, strtoupper($stripeEmail), $now]);
                }

                $relId = generateUuid();
                $stmt = $db->prepare("INSERT INTO email_addr_bean_rel (id, email_address_id, bean_id, bean_module, primary_address, date_created, deleted) VALUES (?, ?, ?, 'Leads', 1, ?, 0)");
                $stmt->execute([$relId, $emailId, $leadId, $now]);

                writeLog("Lead creato: $leadId");
            } else {
                writeLog("Lead già esistente.");
            }
        }
        sendJsonResponse(['success' => true, 'type' => 'lead_created', 'lead_id' => $leadId]);
    }

    // =====================================================================================
    // RAMO 2: IMPORTO > 0 (PAGAMENTO REALE) -> CREA ACCOUNT/CONTACT/CONTRACT
    // =====================================================================================
    else {
        
        $sdi = $dataObject['metadata']['codice_sdi'] ?? '';
        $piva = $dataObject['metadata']['piva'] ?? $dataObject['metadata']['vat_number'] ?? '';
        
        if (empty($piva) && !empty($dataObject['customer_tax_ids'])) {
            $piva = $dataObject['customer_tax_ids'][0]['value'] ?? '';
        }

        $periodStartUnix = $dataObject['lines']['data'][0]['period']['start'] ?? time();
        $periodEndUnix   = $dataObject['lines']['data'][0]['period']['end'] ?? 0;
        if ($periodEndUnix <= $periodStartUnix) {
            $periodEndUnix = strtotime('+1 year', $periodStartUnix);
        }
        $startDate = date('Y-m-d', $periodStartUnix);
        $endDate   = date('Y-m-d', $periodEndUnix);
        $contractValue = $amountPaid / 100;

        writeLog("Pagamento reale ({$contractValue} EUR). Elaborazione Cliente: $stripeName");

        $accountId = null;
        $contactId = null;

        // --- 1. ACCOUNT ---
        $stmt = $db->prepare("
            SELECT accounts.id 
            FROM accounts 
            LEFT JOIN accounts_cstm ON accounts.id = accounts_cstm.id_c
            WHERE (accounts.name = ? OR accounts_cstm.stripe_customer_id_c = ?) 
            AND accounts.deleted = 0 
            LIMIT 1
        ");
        $stmt->execute([$stripeName, $stripeCustomerId]);
        $accountRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($accountRow) {
            $accountId = $accountRow['id'];
        } else {
            $accountId = generateUuid();
            $stmt = $db->prepare("INSERT INTO accounts (id, name, billing_address_street, billing_address_city, billing_address_state, billing_address_postalcode, billing_address_country, date_entered, date_modified, created_by, assigned_user_id, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$accountId, $stripeName, $address_line, $city, $state, $postal_code, $country, $now, $now, $userId, $userId]);
        }

        $sqlCstm = "INSERT INTO accounts_cstm (id_c, sdi_c, piva_c, stripe_customer_id_c) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    sdi_c = VALUES(sdi_c), piva_c = VALUES(piva_c), stripe_customer_id_c = VALUES(stripe_customer_id_c)";
        $db->prepare($sqlCstm)->execute([$accountId, $sdi, $piva, $stripeCustomerId]);

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
                $stmt = $db->prepare("INSERT INTO contacts (id, first_name, last_name, phone_work, primary_address_street, primary_address_city, primary_address_state, primary_address_postalcode, primary_address_country, date_entered, date_modified, created_by, assigned_user_id, deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$contactId, $firstName, $lastName, $phone, $address_line, $city, $state, $postal_code, $country, $now, $now, $userId, $userId]);
                
                // === MODIFICA INSERITA QUI ===
                // Inseriamo il campo custom cliente_iabaduu_c = 'MedStock'
                try { 
                    $db->prepare("INSERT INTO contacts_cstm (id_c, cliente_iabaduu_c) VALUES (?, 'MedStock')")->execute([$contactId]); 
                } catch(Exception $e){}

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

        // --- 3. LINK ACCOUNT/CONTACT ---
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
        
        $sqlContract = "INSERT INTO aos_contracts 
            (id, name, date_entered, date_modified, modified_user_id, created_by, assigned_user_id, deleted,
             total_contract_value, start_date, end_date, status, renewal_reminder_date, 
             currency_id, contract_account_id, contact_id, contract_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 'Signed', ?, ?, ?, ?, 'Service')";

        $renewalDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
        $db->prepare($sqlContract)->execute([
            $contractId, $contractName, $now, $now, $userId, $userId, $userId,
            $contractValue, $startDate, $endDate, $renewalDate,
            $config['currency_id'], $accountId, $contactId
        ]);
        
        try { $db->prepare("INSERT INTO aos_contracts_cstm (id_c) VALUES (?)")->execute([$contractId]); } catch (Exception $e) {}

        writeLog("Contratto creato: $contractId");
        sendJsonResponse(['success' => true, 'type' => 'customer_created', 'contract_id' => $contractId]);
    }

} catch (Exception $e) {
    writeLog("CRITICAL ERROR: " . $e->getMessage());
    sendJsonResponse(['error' => $e->getMessage()], 500);
}
?>

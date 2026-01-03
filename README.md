Stripe to SuiteCRM Webhook Integration
Questo progetto fornisce un endpoint PHP leggero ("Bare Metal") per sincronizzare automaticamente i pagamenti di Stripe con SuiteCRM (v7/v8).

Il webhook intercetta i pagamenti avvenuti (invoice.payment_succeeded) e gestisce automaticamente l'intero ciclo di vita del cliente e del contratto nel database MySQL del CRM.

🚀 Funzionalità
Quando un pagamento viene completato su Stripe, lo script esegue sequenzialmente:

Deduplicazione Intelligente:

Cerca l'Account (Azienda) tramite Stripe Customer ID (se presente) o per Nome.

Cerca il Contact (Persona) tramite Email.

Creazione/Aggiornamento Anagrafiche:

Crea Account e Contact se non esistono.

Popola i campi fiscali italiani personalizzati: SDI, P.IVA e Stripe ID.

Collega automaticamente Persona e Azienda (Relazione Many-to-Many).

Generazione Contratto (AOS_Contracts):

Crea un nuovo contratto di tipo "Service".

Calcola automaticamente la durata (annuale) se non specificata da Stripe.

Imposta un promemoria di rinnovo (-30 giorni dalla scadenza).

Fix Visibilità CRM:

Scrive direttamente nelle tabelle custom (_cstm) per evitare il problema dei "Ghost Records" in SuiteCRM.

🛠 Requisiti
PHP 7.4 o superiore

Estensione PHP pdo_mysql

SuiteCRM (testato su v7 e v8)

Accesso diretto al Database MySQL/MariaDB del CRM

⚙️ Configurazione
Copia il file stripe_webhook_receiver.php nella root del tuo server web (o in una cartella accessibile pubblicamente).

Modifica la sezione CONFIGURAZIONE all'inizio del file:

$config = [
    'db_host' => 'localhost',
    'db_name' => 'tuo_database_crm',
    'db_user' => 'tuo_utente_db',
    'db_password' => 'tua_password',
    'api_key' => 'genera_una_stringa_random_sicura', // Es. sha256
    'default_user_id' => '1', // ID dell'utente CRM a cui assegnare i record
    'currency_id' => '-99' // ID valuta (di solito -99 per quella di default)
];


Assicurati che i campi custom esistano in SuiteCRM (Modulo Accounts):

sdi_c (Codice SDI)

piva_c (Partita IVA)

stripe_customer_id_c (ID Cliente Stripe)

🔗 Setup su Stripe
Vai nella Stripe Dashboard > Developers > Webhooks.

Aggiungi un nuovo Endpoint.

URL Endpoint: https://tuo-crm.com/stripe_webhook_receiver.php?api_key=LA_TUA_API_KEY_QUI

Eventi da ascoltare: Seleziona invoice.payment_succeeded.

📦 Mappatura Dati (Metadati)
Per popolare correttamente P.IVA e SDI, assicurati di passare questi metadati nell'oggetto Customer o Invoice su Stripe:

Campo SuiteCRM,Fonte Stripe (Priorità)
Nome Account,customer_name
Email,customer_email
SDI (sdi_c),metadata['codice_sdi']
P.IVA (piva_c),metadata['piva'] o metadata['vat_number'] o customer_tax_ids
Stripe ID,customer.id (es. cus_xxxx)

📝 Logica Date (Smart Renewal)
Lo script analizza period_start e period_end della fattura Stripe.

Nota: Se Stripe invia una fattura "one-off" (dove data inizio == data fine), lo script imposta automaticamente la scadenza del contratto a +1 Anno.

🐛 Debugging
Lo script genera un file di log nella stessa directory chiamato stripe_receiver_log.txt. Controllalo per verificare errori di connessione al DB o payload non validi.


Disclaimer: Questo script interagisce direttamente con il database di SuiteCRM. Si consiglia di testarlo prima in ambiente di staging. Effettuare sempre backup regolari.

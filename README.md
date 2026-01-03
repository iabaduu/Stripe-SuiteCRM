# Stripe to SuiteCRM Webhook Integration

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Stripe](https://img.shields.io/badge/Stripe-Integration-008CDD?style=for-the-badge&logo=stripe&logoColor=white)
![SuiteCRM](https://img.shields.io/badge/SuiteCRM-v7%20%2F%20v8-FF7F50?style=for-the-badge)
![MySQL](https://img.shields.io/badge/MySQL-DB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)

Un endpoint PHP leggero ("Bare Metal") per sincronizzare automaticamente i pagamenti di **Stripe** con **SuiteCRM** (v7/v8).

Il webhook intercetta i pagamenti avvenuti (`invoice.payment_succeeded`) e gestisce automaticamente l'intero ciclo di vita del cliente e del contratto nel database del CRM, bypassando la complessità delle API per massimizzare la performance.

---

## 📑 Indice

- [🚀 Funzionalità](#-funzionalità)
- [🛠 Requisiti](#-requisiti)
- [⚙️ Configurazione](#-configurazione)
- [🔗 Setup su Stripe](#-setup-su-stripe)
- [📦 Mappatura Dati (Metadati)](#-mappatura-dati-metadati)
- [📝 Logica Date (Smart Renewal)](#-logica-date-smart-renewal)
- [🐛 Debugging](#-debugging)

---

## 🚀 Funzionalità

Quando un pagamento viene completato su Stripe, lo script esegue sequenzialmente:

* ✅ **Deduplicazione Intelligente:**
    * Cerca l'**Account** (Azienda) tramite `Stripe Customer ID` (prioritario) o per `Nome`.
    * Cerca il **Contact** (Persona) tramite `Email`.
* ✅ **Gestione Anagrafiche:**
    * Crea Account e Contact se non esistono.
    * Popola i campi fiscali italiani: **SDI**, **P.IVA** e **Stripe ID**.
    * Crea automaticamente la relazione *Many-to-Many* tra Persona e Azienda.
* ✅ **Generazione Contratto (AOS):**
    * Crea un record nel modulo `AOS_Contracts`.
    * Calcola durata e scadenza (default 1 anno se non specificato).
    * Imposta promemoria rinnovo (-30 giorni).
* ✅ **Fix "Ghost Records":**
    * Scrive direttamente nelle tabelle custom (`_cstm`) per garantire la visibilità immediata dei dati nelle liste del CRM.

---

## 🛠 Requisiti

* **PHP:** 7.4 o superiore
* **Database:** Accesso diretto MySQL/MariaDB (driver `pdo_mysql`)
* **CRM:** SuiteCRM (testato su v7 e v8)
* **Stripe:** Account attivo con accesso Webhooks

---

## ⚙️ Configurazione

### 1. Installazione File
Copia il file `stripe_webhook_receiver.php` nella root del tuo server web o in una cartella pubblica (es. `/custom/api/`).

### 2. Configurazione Script
Modifica l'array `$config` all'inizio del file `stripe_webhook_receiver.php`:

```php
$config = [
    'db_host' => 'localhost',
    'db_name' => 'iabasuite2',          // Il nome del tuo database CRM
    'db_user' => 'iabaduu2',            // Utente DB con permessi INSERT/UPDATE
    'db_password' => 'tua_password',
    'api_key' => 'genera_una_stringa_sicura', // Es. sha256 per proteggere l'URL
    'default_user_id' => '1',           // ID dell'utente CRM assegnatario
    'currency_id' => '-99'              // ID valuta (spesso -99 è Euro/Default)
];

# Sprinter Architecture & How It Works

This document explains how the Sprinter application is built and how its different parts talk to each other.

## 🏗️ System Overview

Sprinter is a **web application** that monitors printers on your network. It stores printer details in a database and uses SNMP (Simple Network Management Protocol) to talk to printers and get their ink/toner levels.

### The Big Picture

```mermaid
graph TD
    User["User / Browser"] <-->|View Page / Click Buttons| WebServer["Web Server (Apache/PHP)"]
    
    subgraph "Docker Container: Web"
        WebServer <-->|1. Load Page| Index[index.php]
        WebServer <-->|2. AJAX Actions| API[api.php]
        
        Index -->|Get Printers| DB_Class[Database.php]
        Index -->|Get Live Status| SNMP[PrinterDiscovery.php]
        
        API -->|Add/Edit/Delete| DB_Class
        API -->|Check IP| SNMP
    end
    
    subgraph "Docker Container: DB"
        DB_Class <-->|Save/Load Data| MariaDB[("MariaDB Database")]
    end
    
    subgraph "Your Network"
        SNMP -.->|Ask: 'Ink Level?'| Printer1[Printer 1]
        SNMP -.->|Ask: 'Model Name?'| Printer2[Printer 2]
    end
```

## 📂 Key Files Explained

### 1. `index.php` (The Main Page)
*   **What it does:** This is the "face" of the application. It's what you see when you open the site.
*   **How it works:**
    1.  Connects to the database to get the list of saved printers.
    2.  For each printer, it asks `PrinterDiscovery.php` to get live data (ink levels, online status).
    3.  It generates the HTML cards (cubes) for each printer.
    4.  It handles the "Database Loading" screen if the database isn't ready yet.

### 2. `script.js` (The Brains in the Browser)
*   **What it does:** Handles everything that happens *after* the page loads without refreshing.
*   **Key jobs:**
    *   **Modals:** Opens and closes the "Add/Edit Printer" popup.
    *   **Searching:** Filters the printer list instantly when you type.
    *   **Theme:** Toggles between Dark and Light mode.
    *   **Talking to Server:** Sends background requests (AJAX) to `api.php` when you click "Save" or "Delete".

### 3. `api.php` (The Worker)
*   **What it does:** A hidden helper that processes requests from `script.js`. It doesn't show a page; it just sends back data (JSON).
*   **Actions:**
    *   `check_ip`: Asks `PrinterDiscovery` to scan an IP and return the model name.
    *   `add_printer`: Tells `Database` to save a new printer.
    *   `delete_printer`: Tells `Database` to remove a printer.

### 4. `Database.php` (The Librarian)
*   **What it does:** Manages the connection to the MariaDB database.
*   **Key jobs:**
    *   Connects securely using a username/password.
    *   Runs SQL commands (INSERT, UPDATE, DELETE, SELECT).
    *   Protects against "SQL Injection" attacks by using safe placeholders (Prepared Statements).

### 5. `PrinterDiscovery.php` (The Translator)
*   **What it does:** Speaks "SNMP" language to talk to printers.
*   **How it works:**
    *   It sends special codes (OIDs) to a printer's IP address.
    *   The printer replies with raw data (e.g., "100" for max ink, "50" for current ink).
    *   This file does the math to turn those numbers into percentages (50%).
    *   It knows the specific codes for HP, Canon, Brother, etc.

## 🔄 Common Workflows

### Adding a Printer
1.  **User** clicks `+` button -> `script.js` opens modal.
2.  **User** types IP and clicks "Check IP".
3.  `script.js` asks `api.php` -> `api.php` asks `PrinterDiscovery.php`.
4.  `PrinterDiscovery.php` talks to **Printer** -> returns "HP LaserJet".
5.  **User** clicks "Save".
6.  `script.js` sends data to `api.php` -> `api.php` saves to `Database.php` -> **MariaDB**.
7.  Page reloads to show the new printer.

### Viewing the Dashboard
1.  **Browser** requests page.
2.  `index.php` gets list from **MariaDB**.
3.  `index.php` loops through list, getting live status from **Printers**.
4.  Page sends HTML to **Browser**.

# S-Printer (Sprinter) — Technical Architecture Document

> **Purpose**: Comprehensive technical documentation for thesis writing. Covers system design, protocol theory, implementation patterns, and best practices analysis with annotated code snippets.

---

## 1. System Overview

Sprinter is a web-based printer monitoring application that queries network printers using SNMP (Simple Network Management Protocol) and displays real-time toner/ink levels on a dashboard. The application is containerized with Docker and uses a MariaDB database for persistent storage.

### 1.1 High-Level Architecture

```mermaid
graph TD
    subgraph Client["Client Layer"]
        Browser["🌐 Web Browser"]
    end

    subgraph Docker_Web["Docker: Web Container — php:8.2-apache"]
        direction TB
        subgraph Entry["Entry Points"]
            IndexPHP["index.php<br/><i>Dashboard</i>"]
            ApiPHP["api.php<br/><i>REST API</i>"]
        end
        subgraph Core["Core Services"]
            Discovery["PrinterDiscovery.php<br/><i>SNMP Engine</i>"]
            DB["Database.php<br/><i>PDO Data Access</i>"]
            Cache["CacheManager.php<br/><i>File Cache</i>"]
        end
    end

    subgraph Docker_DB["Docker: Database Container"]
        MariaDB["MariaDB 10.6"]
    end

    subgraph Network["Network Printers"]
        Printer1["Printer A"]
        Printer2["Printer B"]
        PrinterN["Printer N"]
    end

    Browser -->|"HTTP GET / AJAX"| IndexPHP
    Browser -->|"Fetch API"| ApiPHP

    IndexPHP --> Discovery
    IndexPHP --> DB
    IndexPHP --> Cache
    ApiPHP --> Discovery
    ApiPHP --> DB

    DB -->|"PDO<br/>TCP:3306"| MariaDB
    Discovery -->|"SNMPv2c<br/>UDP:161"| Printer1
    Discovery -->|"SNMPv2c<br/>UDP:161"| Printer2
    Discovery -->|"SNMPv2c<br/>UDP:161"| PrinterN
```

### 1.2 Request Lifecycle

```mermaid
graph TD
    A["🌐 HTTP GET /"] --> B["Load DB records<br/>printers, floors, settings"]
    B --> C["Render card shells<br/>+ level JSON data attributes"]
    C --> D["Bootstrap settings/floors<br/>window.__SPRINTER_BOOTSTRAP__"]
    D --> E["script.js hydrates<br/>initial level sections"]
    E --> F["refreshAllPrinters()<br/><i>concurrency limit: 4</i>"]
    F --> G["api.php?action=refresh_printer"]
    G --> H["DB lookup by IP"]
    H --> I["SNMP query<br/><i>See 1.2.1 below</i>"]
    I --> J["Return JSON<br/>status + levels"]
    J --> K["DOM renderer updates<br/>card status and levels"]
```

#### 1.2.1 SNMP Query Detail (per printer)

```mermaid
graph TD
    A["getPrinterData(ip)"] --> B["snmp2_get(sysDescr OID)<br/><i>Detect vendor from response</i>"]
    B --> C["Phase A: Try vendor-specific OIDs<br/><i>Direct OID map — fast</i>"]
    C --> D{"Data returned?"}

    D -->|"Yes"| E["Return status + levels"]
    D -->|"No / empty"| F["Phase B: Standard MIB walk<br/>snmp2_real_walk(supply table)<br/><i>RFC 3805 — universal fallback</i>"]

    F --> G["Parse supply descriptions<br/>Match colors by text<br/>Calculate percentages"]
    G --> E
```

---

## 2. SNMP Protocol Foundation

### 2.1 What is SNMP

Simple Network Management Protocol (SNMP) is an Internet Standard protocol defined by the IETF for monitoring and managing network devices. It operates on the application layer over UDP port 161.

**Key concepts:**
- **Agent**: Software on the managed device (printer) that responds to queries
- **Manager**: The monitoring application (Sprinter) that sends queries
- **Community String**: Authentication credential (default: `"public"`, read-only)
- **OID**: Object Identifier — a unique address for each data point on the device

```mermaid
graph LR
    subgraph Manager["Manager (Sprinter)"]
        App["PHP snmp2_get()"]
    end

    subgraph Agent["Agent (Printer)"]
        MIB["MIB Data Store<br/><i>OID → value mappings</i>"]
    end

    App -->|"GetRequest<br/>UDP:161<br/>community='public'"| MIB
    MIB -->|"GetResponse<br/>OID value"| App
```

### 2.2 OID Tree Structure (RFC 3805 — Printer MIB v2)

RFC 3805, titled "Printer MIB v2," defines the standard Management Information Base for printers. The supply-related OIDs used by Sprinter are:

```
iso(1).org(3).dod(6).internet(1).mgmt(2).mib-2(1).printmib(43)
├── .5.1.1  — General Printer Info
│   ├── .16.1  — prtGeneralPrinterName
│   └── .17.1  — prtGeneralSerialNumber
├── .11.1.1 — Marker Supplies Table
│   ├── .6.1.X — prtMarkerSuppliesDescription (e.g., "Black Toner")
│   ├── .7.1.X — prtMarkerSuppliesSupplyUnit
│   ├── .8.1.X — prtMarkerSuppliesMaxCapacity
│   └── .9.1.X — prtMarkerSuppliesLevel (current level)
└── .12.1.1 — Marker Colorant Table
    └── .4.1.X — prtMarkerColorantValue (color name)
```

Where `X` is the supply index (1 = first supply, 2 = second, etc.).

### 2.3 SNMP Queries in Practice

Sprinter uses the PHP `snmp2_get()` and `snmp2_real_walk()` functions to query printers:

```php
// Single OID query — ask for one specific value
$value = snmp2_get(
    $ip,              // Printer IP address
    $this->community, // Community string ("public")
    $oid,             // OID to query
    $this->timeout,   // Microseconds to wait (500000 = 0.5s)
    $this->retries    // Retry count if timeout
);

// Walk — enumerate all entries under an OID subtree
$supplyTable = snmp2_real_walk(
    $ip,
    $this->community,
    '.1.3.6.1.2.1.43.11.1.1', // Walk entire supply table
    $this->timeout,
    $this->retries
);
```

### 2.4 Supply Level Calculation

Toner percentage is calculated from two OID values:

```php
// OID .8.1.X = maximum capacity
// OID .9.1.X = current level
$percentage = round(($current / $max) * 100, 2);
```

**Special SNMP return codes (RFC 3805 Section 2):**
| Value | Meaning |
|-------|---------|
| `-1`  | Other / unknown |
| `-2`  | Unknown status |
| `-3`  | Supply is above threshold — exact level not available |

```mermaid
stateDiagram-v2
    [*] --> Querying : SNMP request sent

    Querying --> Online : Valid level returned
    Querying --> Offline : snmp2_get() timeout / false
    Querying --> Unknown : Returns −1 or −2

    Online --> OK : Level > 10%
    Online --> Low : Level ≤ 10%
    Online --> AboveThreshold : Returns −3

    OK --> [*]
    Low --> [*]
    AboveThreshold --> [*]
    Offline --> [*]
    Unknown --> [*]
```

### 2.5 Vendor-Specific OID Challenges

While RFC 3805 defines a standard, manufacturers implement OIDs differently:

| Challenge | Example | Sprinter's Solution |
|-----------|---------|-------------------|
| Supply index varies | Brother mono: index 1 = Drum, index 2 = Toner | Standard MIB walk reads description text |
| Proprietary OIDs | Brother inkjet uses `.1.3.6.1.4.1.2435.2.3.9...` | Dedicated `getBrotherInkjetLevels()` method |
| Hex-encoded responses | Pantum serial returns `Hex-STRING: 00 00 00 00` | `sanitizeSnmpValue()` converts hex to ASCII |
| Color OID ordering | Toshiba e-STUDIO409S does not follow standard order | Model-specific OID override |

---

## 3. PHP 8 Implementation & Best Practices

```mermaid
classDiagram
    class PrinterDiscovery {
        -string community
        -int timeout
        -int retries
        +detectPrinter(string ip) ?array
        +getPrinterData(array printer) array
        -getSysInfo(string ip) array
        -getPrinterInfo(string ip) array
        -getSupplyLevels(string ip) array
        -getDirectSupplyLevels(string ip) array
        -detectVendor(string description) string
        -determineColor(string description) string
        -sanitizeSnmpValue(string value) string
        -getBrotherInkjetLevels(string ip) array
    }

    class Database {
        -?PDO pdo
        -?string lastError
        +getConnection() ?PDO
        -initSchema() void
        +getPrinters() array
        +addPrinter(array data) bool
        +updatePrinter(int id, array data) bool
        +updatePrinterLevels(int id, array levels) bool
        +deletePrinter(int id) bool
        +getPrinterByIp(string ip) ?array
        +getFloors() array
        +addFloor(string name, int sortOrder) int|false
        +updateFloor(int id, string name, ?int sortOrder) bool
        +deleteFloor(int id) bool
        +getSettings() array
        +getSetting(string key, mixed default) mixed
        +setSetting(string key, string value) bool
        +getLastError() ?string
    }

    class CacheManager {
        -string cacheDir
        -int cacheTtl
        +get(string key) mixed
        +set(string key, mixed data) bool
        +delete(string key) bool
        +clear() bool
        -getCacheFilePath(string key) string
    }

    PrinterDiscovery ..> Database : reads printer list
    PrinterDiscovery ..> CacheManager : cached by
    Database ..> MariaDB : PDO connection

    class MariaDB {
        <<external>>
        TCP port 3306
    }
```

### 3.1 Strict Type Declarations

Every PHP file begins with `declare(strict_types=1)`, enforcing that function arguments and return values match their declared types exactly. This prevents PHP's default implicit type coercion.

```php
<?php
declare(strict_types=1);

class PrinterDiscovery {
    private string $community;  // Typed properties (PHP 7.4+)
    private int $timeout;
    private int $retries;

    public function __construct(
        string $community = 'public',
        int $timeout = 500000,
        int $retries = 2
    ) {
        $this->community = $community;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }
}
```

**Best practice applied**: Typed properties (`private string $community`) ensure class fields cannot hold incorrect types, catching bugs at assignment time rather than at runtime usage.

### 3.2 SQL Injection Prevention with PDO Prepared Statements

All database queries use PDO prepared statements with named parameters:

```php
public function addPrinter(array $data): bool {
    $sql = "INSERT INTO printers
            (ip, name, description, purchase_url, serial, model, vendor, floor_id)
            VALUES (:ip, :name, :description, :purchase_url,
                    :serial, :model, :vendor, :floor_id)";

    $stmt = $this->pdo->prepare($sql);
    return $stmt->execute([
        ':ip'           => $data['ip'],
        ':name'         => $data['name'] ?? null,
        ':description'  => $data['description'] ?? null,
        ':purchase_url' => $data['purchase_url'] ?? null,
        ':serial'       => $data['serial'] ?? null,
        ':model'        => $data['model'] ?? null,
        ':vendor'       => $data['vendor'] ?? null,
        ':floor_id'     => !empty($data['floor_id'])
                           ? (int)$data['floor_id']
                           : null,
    ]);
}
```

**Key security configurations in the PDO constructor:**

```php
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,  // Use native prepared statements
];
$this->pdo = new PDO($dsn, $user, $pass, $options);
```

Setting `ATTR_EMULATE_PREPARES` to `false` forces the database driver to use native prepared statements rather than client-side emulation, providing stronger SQL injection protection.

### 3.3 Nullable Return Types and Null Coalescing

```php
// Nullable return type  —  returns array or null
public function detectPrinter(string $ip): ?array {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    // ...
}

// Null coalescing operator (??) for safe defaults
$printerData['info']['full_name'] =
    $dbPrinter['name']
    ?: ($dbPrinter['model'] ?: 'Unknown Printer');
```

### 3.4 Error Handling Strategy

The application uses a layered error handling approach:

```mermaid
graph TD
    A["Incoming operation"] --> B{"Layer 1:<br/>SNMP query"}
    B -->|"snmp2_get() returns false"| C["Return null<br/><i>Printer unreachable — expected</i>"]
    B -->|"Success"| D{"Layer 2:<br/>Database operation"}
    D -->|"PDOException"| E["Catch → error_log()<br/><i>Store in lastError</i>"]
    D -->|"Success"| F{"Layer 3:<br/>API response"}
    F -->|"Error"| G["sendError()<br/><i>Generic message to user</i><br/><i>Details in server log</i>"]
    F -->|"Success"| H["sendResponse()<br/><i>JSON: success + data</i>"]
```

```php
// Layer 1: SNMP errors suppressed with @ operator (expected failures)
$sysDescr = @snmp2_get($ip, $this->community, $oid, ...);
if ($sysDescr === false) {
    return null;  // Printer not reachable — not an error
}

// Layer 2: Database errors caught and stored
try {
    $this->pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    $this->lastError = "Connection failed: " . $e->getMessage();
    error_log($this->lastError);
}

// Layer 3: API returns structured JSON errors with 'never' return type (PHP 8.1+)
// Detailed errors logged server-side; generic messages returned to user (OWASP)
function sendError(string $message): never {
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function sendResponse(mixed $data = null): never {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}
```

The `never` return type explicitly documents that these functions halt execution, aiding static analysis tools and making the control flow clear to other developers.

### 3.5 Output Escaping (XSS Prevention)

All user-controlled data rendered in HTML is escaped:

```php
<!-- Correct: htmlspecialchars prevents script injection -->
<?= htmlspecialchars($data['info']['full_name'] ?? 'N/A') ?>

<!-- For attributes with JSON data -->
onclick="openEditModal(<?= htmlspecialchars(json_encode($data['info'])) ?>)"
```

---

## 4. JavaScript Implementation & Best Practices

### 4.1 Async/Await Pattern for API Calls

All server communication uses the modern `async/await` syntax with the Fetch API:

```javascript
async function checkIp() {
    const ip = document.getElementById('printerIp').value;
    const statusSpan = document.getElementById('ipStatus');

    try {
        const response = await fetch('api.php?action=check_ip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ip: ip })
        });
        const result = await response.json();

        if (result.success) {
            statusSpan.textContent = 'Printer found! ' + result.data.full_name;
            statusSpan.className = 'status-text success';
        } else {
            statusSpan.textContent = result.error || 'Not found';
            statusSpan.className = 'status-text error';
        }
    } catch (e) {
        statusSpan.textContent = 'Network error';
        statusSpan.className = 'status-text error';
    }
}
```

### 4.2 Parallel Initialization with Promise.all

On page load, independent API calls run concurrently:

```javascript
document.addEventListener('DOMContentLoaded', async function () {
    const [settingsRes, floorsRes] = await Promise.all([
        fetch('api.php?action=get_settings'),
        fetch('api.php?action=get_floors')
    ]);
    // Both requests complete before proceeding
    window.appSettings = (await settingsRes.json()).data;
    window.appFloors = (await floorsRes.json()).data;
});
```

### 4.3 Event Delegation for Dynamic Elements

Floor management uses event delegation — a single listener on the parent handles all child button clicks:

```javascript
floorList.addEventListener('click', async function (e) {
    const btn = e.target.closest('button');     // Find clicked button
    if (!btn) return;

    const li = btn.closest('.floor-list-item'); // Find parent list item
    const floorId = li.dataset.floorId;         // Read data-* attribute

    if (btn.classList.contains('save-floor-btn')) {
        // Handle save
    } else if (btn.classList.contains('delete-floor-btn')) {
        // Handle delete
    }
});
```

**Advantage over individual listeners**: Works automatically for dynamically-added floor items without re-binding events.

### 4.4 DOM Manipulation with classList

CSS class manipulation uses the modern `classList` API instead of string concatenation:

```javascript
// Toggle visibility with CSS classes + animations
cube.classList.remove('hidden-by-filter');
cube.classList.add('visible-by-filter');
cube.style.animationDelay = `${delay * 0.05}s`;  // Stagger effect
```

### 4.5 localStorage for State Persistence

User preferences persist across sessions without server roundtrips:

```javascript
// Theme preference
const currentTheme = localStorage.getItem('theme') || 'dark';
if (currentTheme === 'light') {
    document.documentElement.classList.add('light-theme');
}

// Floor selection
const savedFloor = localStorage.getItem('activeFloor') || 'all';
setActiveFloor(savedFloor);
```

---

## 5. Docker Containerization

### 5.1 Container Architecture

```mermaid
graph LR
    subgraph Host["Host Machine"]
        subgraph DockerNet["Docker Network (bridge)"]
            Web["🌐 web container<br/><b>php:8.2-apache</b><br/>Port 8080 → 80"]
            DB["🗄️ db container<br/><b>mariadb:10.6</b><br/>Port 3306 (internal)"]
        end
        Volume["💾 db_data<br/><i>Named Volume</i><br/>Persistent storage"]
        Src["📁 ./src<br/><i>Bind Mount</i><br/>Live code editing"]
    end

    Web -->|"PDO / TCP:3306"| DB
    DB --- Volume
    Src -.->|"Volume mount<br/>→ /var/www/html"| Web
```

### 5.2 Dockerfile Analysis

```dockerfile
FROM php:8.2-apache

# Single RUN command reduces image layers
# --no-install-recommends minimizes package footprint
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    libsnmp-dev snmp && \
    docker-php-ext-install snmp pdo_mysql && \
    a2enmod rewrite headers && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Security: disable directory listing
# apache-vhost.conf sets Options -Indexes
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Container health monitoring
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/ || exit 1
```

**Best practices applied:**
| Practice | Implementation |
|----------|---------------|
| Single RUN layer | All `apt-get` and extensions in one `RUN` |
| Cache cleanup | `apt-get clean && rm -rf /var/lib/apt/lists/*` |
| Health check | `HEALTHCHECK` instruction for container orchestration |
| Pinned base image | `php:8.2-apache` (not `latest`) |
| Minimal packages | `--no-install-recommends` flag |
| `.dockerignore` | Excludes `.git`, `*.md`, IDE files, OS files from build context |

### 5.3 Docker Compose — Service Orchestration

```yaml
services:
  web:
    build: .
    ports:
      - "8080:80"
    volumes:
      - ./src:/var/www/html     # Bind mount for development
    environment:
      - DB_HOST=db              # Service name as hostname
      - SNMP_COMMUNITY=public
    depends_on:
      - db                      # Start database first

  db:
    image: mariadb:10.6
    volumes:
      - db_data:/var/lib/mysql  # Named volume for persistence
    restart: unless-stopped

volumes:
  db_data:                      # Survives container recreation
```

**Key design decisions:**
- `depends_on` ensures the database container starts before the web container (though the app handles connection failures gracefully with auto-retry)
- Bind mount (`./src:/var/www/html`) enables live code editing during development
- Named volume (`db_data`) ensures database data survives `docker-compose down`

---

## 6. Security Implementation

```mermaid
graph TD
    A["Incoming HTTP request"] --> B["Security headers<br/><i>CSP, X-Frame-Options, nosniff</i>"]
    B --> C{"POST request?"}
    C -->|"Yes"| D{"Content-Type =<br/>application/json?"}
    D -->|"No"| E["415 Unsupported<br/>Media Type"]
    D -->|"Yes"| F["Input validation<br/><i>filter_var, whitelist</i>"]
    C -->|"GET"| F
    F -->|"Invalid"| G["400 / 405<br/>Error response"]
    F -->|"Valid"| H["PDO prepared statement<br/><i>Named parameters</i>"]
    H --> I["Output escaping<br/><i>htmlspecialchars (server)</i><br/><i>textContent (client)</i>"]
    I --> J["Response to user<br/><i>Generic errors only</i>"]
```

### 6.1 HTTP Security Headers

Applied at both Apache and PHP levels for defense in depth:

```
X-Content-Type-Options: nosniff      — Prevents MIME type sniffing
X-Frame-Options: DENY                — Blocks iframe embedding (clickjacking)
X-XSS-Protection: 1; mode=block     — Legacy XSS filter
Referrer-Policy: same-origin         — Limits referrer leakage
Content-Security-Policy:             — Controls resource loading:
  default-src 'self';                  Only same-origin resources
  script-src 'self' 'unsafe-inline';   Scripts from same origin
  style-src 'self' 'unsafe-inline';    Styles from same origin
  img-src 'self' data:;               Images + data URIs
  connect-src 'self'                   AJAX to same origin only
```

### 6.2 Input Validation

```php
// IP address validation before SNMP queries
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    return null;
}

// API parameter validation
if (empty($input['ip'])) {
    sendError('IP address is required');
}

// Settings whitelist — only approved keys can be saved
if (in_array($key, ['hide_ip', 'show_serial'])) {
    $db->setSetting($key, (string)$value);
}
```

### 6.3 CSRF Prevention via Content-Type Enforcement

The API uses a lightweight CSRF prevention strategy recommended by OWASP for JSON APIs. Since all API calls use `Content-Type: application/json`, which triggers a CORS preflight for cross-origin requests, a full CSRF token system is unnecessary. Instead, strict Content-Type validation on the server prevents attackers from bypassing preflight with `text/plain` form submissions:

```php
// Strict Content-Type validation for POST requests (OWASP CSRF prevention)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415); // Unsupported Media Type (RFC 7231)
        sendError('Content-Type must be application/json');
    }
}
```

### 6.4 HTTP Method Enforcement

All write actions enforce POST-only access, returning HTTP 405 Method Not Allowed for other methods:

```php
case 'add_printer':
case 'edit_printer':
case 'delete_printer':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        sendError('Method not allowed');
    }
    // ... handle action
```

### 6.5 Error Message Sanitization

Detailed database errors are logged server-side via `error_log()`, while users receive generic messages. This prevents information disclosure per OWASP guidelines:

```php
// Detailed error logged for administrators
error_log('Add printer failed: ' . $db->getLastError());
// Generic message returned to client
sendError('Failed to add printer. The IP may already exist.');
```

### 6.6 Client-Side XSS Prevention

```javascript
// Safe DOM construction for dynamic content
function createTextMessage(className, text) {
    const message = document.createElement('div');
    message.className = className;
    message.textContent = text; // textContent never parses HTML
    return message;
}

function renderLevelsSection(levelsSection, status, levels, lastLevels) {
    const fragment = document.createDocumentFragment();
    // append level/message nodes with DOM APIs...
    levelsSection.replaceChildren(fragment);
}
```

---

## 7. Design Patterns

```mermaid
graph LR
    subgraph Patterns["Design patterns used in Sprinter"]
        A["Strategy<br/><i>Vendor OID maps</i>"]
        B["Template Method<br/><i>Two-phase detection</i>"]
        C["Cache-Aside<br/><i>Read/write split</i>"]
    end
    A -.->|"§7.1"| D["PrinterDiscovery"]
    B -.->|"§7.2"| D
    C -.->|"§7.3"| E["CacheManager +<br/>index.php / api.php"]
```

### 7.1 Strategy Pattern — Vendor-Specific SNMP Handling

The `getDirectSupplyLevels()` method uses a strategy-like approach where the OID mapping changes based on the detected vendor:

```mermaid
graph TD
    A["detectVendor(sysDescr)"] --> B{"Vendor match?"}
    B -->|"Contains 'HP'"| C["HP OID map<br/><i>index 1 = black</i>"]
    B -->|"Contains 'BROTHER'"| D["Brother OID map<br/><i>index 1 = black</i>"]
    B -->|"Contains 'TOSHIBA'"| E["Toshiba OID map<br/><i>index 2 = black</i>"]
    B -->|"Contains 'PANTUM'"| F["Pantum OID map<br/><i>mono only</i>"]
    B -->|"No match"| G["GENERIC OID map<br/><i>standard indices</i>"]
    C --> H["Query printer with<br/>selected OID mapping"]
    D --> H
    E --> H
    F --> H
    G --> H
```

```php
$oidMappings = [
    'HP'      => ['max_black' => '...8.1.1', 'black' => '...9.1.1', ...],
    'TOSHIBA' => ['max_black' => '...8.1.2', 'black' => '...9.1.2', ...],
    'BROTHER' => ['max_black' => '...8.1.1', 'black' => '...9.1.1', ...],
    // ...
];

$oids = $oidMappings[$vendorName] ?? $oidMappings['GENERIC'];
```

### 7.2 Template Method — Two-Phase Supply Detection

Supply level retrieval follows a template method pattern with two phases:

```
1. Try vendor-specific direct OIDs  (fast, may fail)
2. Fall back to standard MIB walk   (universal, slower)
```

```php
private function getSupplyLevels(string $ip): array {
    // Phase 1: Direct vendor OIDs
    $directLevels = $this->getDirectSupplyLevels($ip);
    if (!empty($directLevels)) {
        return $directLevels;
    }

    // Phase 2: Standard MIB walk fallback
    $supplyTable = snmp2_real_walk($ip, ..., '.1.3.6.1.2.1.43.11.1.1', ...);
    // Parse descriptions, calculate percentages...
}
```

### 7.3 Caching Strategy — Cache-Aside Pattern with Write Invalidation

```mermaid
graph TD
    subgraph Read["Read path (index.php)"]
        R1["Request arrives"] --> R2{"Cache has<br/>printers_data?"}
        R2 -->|"Hit"| R3["Use cached data"]
        R2 -->|"Miss"| R4["Query all printers<br/>via SNMP"]
        R4 --> R5["Store in cache<br/><i>TTL = 5 minutes</i>"]
        R5 --> R3
    end

    subgraph Write["Write path (api.php)"]
        W1["Add / edit / delete<br/>printer"] --> W2["Mutate database"]
        W2 --> W3["Invalidate cache<br/>delete('printers_data')"]
        W3 --> W4["Next request<br/>triggers fresh SNMP"]
    end
```

```php
// Read path: check cache first (index.php)
$cachedData = $cacheManager->get($cacheKey);
if (!empty($cachedData)) {
    $printers = $cachedData;  // Use cached SNMP data
}

// Only query printers if cache missed
if (empty($printers)) {
    foreach ($dbPrinters as $dbPrinter) {
        $data = $discovery->getPrinterData($dbPrinter);
        $printers[$dbPrinter['ip']] = $data;
    }
    $cacheManager->set($cacheKey, $printers);  // Store for 5 minutes
}

// Write path: invalidate cache after mutations (api.php)
// Ensures dashboard shows changes immediately
if ($db->addPrinter($input)) {
    (new CacheManager('cache'))->delete('printers_data');
    sendResponse();
}
```

---

## 8. Database Schema

```mermaid
erDiagram
    FLOORS ||--o{ PRINTERS : "has"
    FLOORS {
        int id PK
        varchar name
        int sort_order
        timestamp created_at
    }
    PRINTERS {
        int id PK
        varchar ip UK
        varchar name
        text description
        text purchase_url
        varchar serial
        varchar model
        varchar vendor
        int floor_id FK
        json custom_oids
        json last_levels
        timestamp created_at
        timestamp updated_at
    }
    SETTINGS {
        varchar setting_key PK
        text setting_value
        timestamp updated_at
    }
```

**Schema migration pattern** — The application handles upgrades gracefully:

```php
// Check if column exists before adding
$check = $this->pdo->query("SHOW COLUMNS FROM printers LIKE 'last_levels'");
if ($check->rowCount() === 0) {
    $this->pdo->exec("ALTER TABLE printers ADD COLUMN last_levels JSON ...");
}
```

---

## 9. CSS Architecture

```mermaid
graph TD
    subgraph CSS["Stylesheet modules"]
        direction TB
        A["base.css<br/><i>Design tokens, resets</i>"]
        B["layout.css<br/><i>Grid, sidebar</i>"]
        C["components.css<br/><i>Cards, bars, buttons</i>"]
        D["modals.css<br/><i>Dialogs, forms</i>"]
        E["responsive.css<br/><i>Media queries</i>"]
    end

    A -->|"Variables cascade to"| B
    A -->|"Variables cascade to"| C
    A -->|"Variables cascade to"| D
    E -->|"Overrides at breakpoints"| B
    E -->|"Overrides at breakpoints"| C
    E -->|"Overrides at breakpoints"| D

    subgraph Theme["Theme switching"]
        T1[":root — Everforest Dark"]
        T2[".light-theme — Light override"]
    end

    T1 -.->|"CSS custom properties"| A
    T2 -.->|"Overrides --bg, --fg, etc."| A
```

The stylesheet is modularized into five files following separation of concerns:

| File | Responsibility | Key Features |
|------|---------------|--------------|
| `base.css` | Design tokens, theme variables, resets | CSS custom properties for dark/light themes |
| `layout.css` | Page structure, grid, sidebar | CSS Grid for printer card layout |
| `components.css` | Printer cards, buttons, level bars | Animated toner bars, status indicators |
| `modals.css` | Dialog windows, forms, settings | Overlay with backdrop blur |
| `responsive.css` | Media queries, mobile adjustments | Breakpoints for tablet/mobile |

**Theme system using CSS Custom Properties:**

```css
:root {
    /* Everforest Dark Theme */
    --bg: #2d353b;
    --fg: #d3c6aa;
    --primary: #7fbbb3;
    --cube-bg: #475258;
    --cyan: #7fbbb3;
    --magenta: #d699b6;
    --yellow: #dbbc7f;
}

.light-theme {
    --bg: #f8f0dc;
    --fg: #5c6a72;
    --primary: #8da101;
    --cube-bg: #e9e5cf;
}
```

---

## 10. Naming Conventions

The codebase follows these conventions consistently:

| Context | Convention | Examples |
|---------|-----------|----------|
| PHP classes | PascalCase | `PrinterDiscovery`, `CacheManager` |
| PHP methods | camelCase | `getPrinterData()`, `detectVendor()` |
| PHP variables | camelCase | `dbPrinter`, `sysDescr`, `vendorName` |
| JS functions | camelCase | `setupSearch()`, `checkIp()`, `deletePrinter()` |
| JS DOM IDs | camelCase | `printerSearch`, `settingsBtn`, `floorList` |
| CSS classes | kebab-case | `level-fill`, `cube-footer`, `settings-nav-item` |
| DB columns | snake_case | `floor_id`, `purchase_url`, `last_levels` |
| API actions | snake_case | `check_ip`, `add_printer`, `export_printers` |

No Hungarian notation is used anywhere in the codebase.

---

## 11. Resolved Bugs & Root Cause Analysis

### Bug 1: Brother Mono Laser Shows "Cyan" Instead of "Black" ✅

**Affected models**: DCP-L, HL-L, MFC-L series (monochrome lasers)

```mermaid
graph TD
    subgraph Before["Before fix — hardcoded mapping"]
        A1["Brother OID index 1"] -->|"Mapped to"| B1["'Black'"]
        A2["Brother OID index 2"] -->|"Mapped to"| B2["'Cyan'"]
    end

    subgraph Actual["Mono laser reality"]
        C1["Index 1 = Drum Unit<br/><i>returns −3</i>"]
        C2["Index 2 = Black Toner<br/><i>actual toner level</i>"]
    end

    B2 -.->|"Mislabeled!"| C2

    subgraph After["After fix — MIB walk"]
        D1["Skip direct OIDs"] --> D2["snmp2_real_walk()"]
        D2 --> D3["Read supply description text<br/><i>'Black Toner' vs 'Drum Unit'</i>"]
        D3 --> D4["Correct label assigned"]
    end
```

**Root cause**: The hardcoded BROTHER OID mapping assumes index 1 = Black, index 2 = Cyan. On mono laser printers, index 1 = Drum Unit (returns `-3`), index 2 = Black Toner. The code maps index 2 to "cyan" by name.

**Fix applied**: Brother mono lasers now skip direct OIDs; the standard MIB walk reads supply descriptions to correctly identify toner vs drum.

### Bug 2: Pantum Printers Not Recognized ✅

**Root cause**: `detectVendor()` had no `'PANTUM'` entry. Fell to `GENERIC`.

**Fix applied**: Added `'PANTUM' => ['pantum']` to vendor detection and mono-only OID mapping.

### Bug 3: Serial Shows "Hex-STRING: 00 00 00 00" ✅

**Root cause**: `sanitizeSnmpValue()` stripped `STRING:`, `INTEGER:`, `Counter32:`, `Gauge32:`, `Timeticks:` prefixes but not `Hex-STRING:`.

**Fix applied**: `Hex-STRING:` values are now decoded from hex bytes to ASCII. All-null values return `N/A`.

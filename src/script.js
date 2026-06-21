/**
 * @fileoverview Sprinter - Client-side functionality
 * Handles theme switching, search, modals, and API interactions.
 * @author Sprinter Team
 */

const MAX_IMPORT_FILE_BYTES = 2097152; // Keep client import cap aligned with api.php
let isRefreshingAllPrinters = false;
let activeRefreshController = null;
const activeCardRefreshTokens = new WeakMap();

function loadBootstrapData() {
    const bootstrapElement = document.getElementById('sprinterBootstrap');
    if (!bootstrapElement) return {};

    try {
        const parsed = JSON.parse(bootstrapElement.textContent || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
        return {};
    }
}

window.__SPRINTER_BOOTSTRAP__ = loadBootstrapData();

function getAuthState() {
    const bootstrap = window.__SPRINTER_BOOTSTRAP__ || {};
    return bootstrap.auth || {
        csrfToken: bootstrap.csrfToken || '',
        currentUser: bootstrap.currentUser || null,
        permissions: bootstrap.currentPermissions || {}
    };
}

function hasPermission(permission) {
    const permissions = getAuthState().permissions || {};
    return Boolean(permissions.can_manage_users || permissions[permission]);
}

function apiFetch(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }
    headers.set('X-CSRF-Token', getAuthState().csrfToken || '');
    return fetch(url, { ...options, headers });
}

function setCsrfToken(token) {
    if (!token) return;
    window.__SPRINTER_BOOTSTRAP__ = window.__SPRINTER_BOOTSTRAP__ || {};
    window.__SPRINTER_BOOTSTRAP__.csrfToken = token;
    window.__SPRINTER_BOOTSTRAP__.auth = window.__SPRINTER_BOOTSTRAP__.auth || {};
    window.__SPRINTER_BOOTSTRAP__.auth.csrfToken = token;
    document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
        input.value = token;
    });
}

function getActiveFloorId() {
    const activeFloor = document.querySelector('.floor-item.active');
    return activeFloor?.dataset.floorId || localStorage.getItem('activeFloor') || 'all';
}

function matchesActiveFloor(cube, floorId = getActiveFloorId()) {
    return floorId === 'all' || (cube.dataset.floorId || '') === floorId;
}


async function initializeAppData() {
    const bootstrap = window.__SPRINTER_BOOTSTRAP__ || {};
    const hasSettings = bootstrap.settings && typeof bootstrap.settings === 'object';
    const hasFloors = Array.isArray(bootstrap.floors);

    window.appSettings = hasSettings ? bootstrap.settings : {};
    window.appFloors = hasFloors ? bootstrap.floors : [];

    if (hasSettings && hasFloors) {
        return;
    }

    try {
        const settingsRes = await apiFetch('api.php?action=get_settings');
        const floorsRes = await apiFetch('api.php?action=get_floors');
        const settingsData = await settingsRes.json();
        const floorsData = await floorsRes.json();

        window.appSettings = settingsData.success ? settingsData.data : window.appSettings;
        window.appFloors = floorsData.success ? floorsData.data : window.appFloors;
    } catch (e) {
        // Keep bootstrap/default values if fallback API requests fail.
    }
}

function parseJsonDataAttribute(element, attributeName, fallback) {
    const raw = element.dataset[attributeName];
    if (!raw) return fallback;

    try {
        const parsed = JSON.parse(raw);
        return parsed === null ? fallback : parsed;
    } catch (e) {
        return fallback;
    }
}

function hydrateInitialLevelSections() {
    document.querySelectorAll('.levels-section').forEach(levelsSection => {
        const status = levelsSection.dataset.initialStatus || 'loading';
        const levels = parseJsonDataAttribute(levelsSection, 'initialLevels', {});
        const lastLevels = parseJsonDataAttribute(levelsSection, 'initialLastLevels', null);
        renderLevelsSection(levelsSection, status, levels, lastLevels);
    });
}

function createFloorListItem(id, name) {
    const li = document.createElement('li');
    li.className = 'floor-list-item';
    li.dataset.floorId = String(id);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'floor-name-input';
    input.value = name;

    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'save-floor-btn secondary-btn';
    saveButton.title = 'Save';
    saveButton.textContent = '✓';

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'delete-floor-btn danger-btn';
    deleteButton.title = 'Delete';
    deleteButton.textContent = '×';

    li.append(input, saveButton, deleteButton);
    return li;
}

/**
 * Initialize all functionality when DOM is ready.
 * Uses server bootstrap data first, then hydrates JS-owned level sections.
 */
document.addEventListener('DOMContentLoaded', async function () {
    // Wire critical header controls before any awaited initialization so visible controls are immediately clickable.
    setupThemeToggle();
    setupAccountMenu();
    setupSettings();

    // Use server bootstrap data first; fall back to API only when bootstrap is missing.
    await initializeAppData();

    // JS owns initial level rendering so PHP and JS templates cannot drift.
    hydrateInitialLevelSections();

    // Search functionality
    setupSearch();

    // Modal functionality
    setupModal();

    // Import/Export functionality
    setupImportExport();

    // Delegated printer card actions
    setupPrinterActions();

    // Form submission
    setupForm();

    // Floor filter sidebar
    setupFloorFilter();

    // Apply display settings
    applyDisplaySettings();

    // Start background refresh
    refreshAllPrinters();

    // Wire reactive refresh button
    const refreshBtn = document.getElementById('refreshDataBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshAllPrinters);
    }
});

/**
 * Sets up the dark/light theme toggle functionality.
 * Reads saved preference from localStorage and applies it.
 * Dispatches 'themeChanged' custom event when theme changes.
 * @returns {void}
 */
function setupThemeToggle() {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;

    // Check for saved theme preference or default to 'dark'
    const currentTheme = localStorage.getItem('theme') || 'dark';

    // Apply the saved theme on initial load
    if (currentTheme === 'light') {
        document.documentElement.classList.add('light-theme');
    }

    // Toggle theme on button click
    themeToggle.addEventListener('click', function () {
        // Toggle the class
        const isLightTheme = document.documentElement.classList.toggle('light-theme');

        // Save the preference
        localStorage.setItem('theme', isLightTheme ? 'light' : 'dark');

        // Dispatch a custom event for other scripts that might need to know about theme changes
        document.dispatchEvent(new CustomEvent('themeChanged', {
            detail: { theme: isLightTheme ? 'light' : 'dark' }
        }));
    });
}

function setupAccountMenu() {
    const accountMenu = document.getElementById('accountMenu');
    const accountButton = document.getElementById('accountMenuBtn');
    const accountDropdown = document.getElementById('accountDropdown');
    const togglePasswordBtn = document.getElementById('togglePasswordChange');
    const changePasswordForm = document.getElementById('changePasswordForm');
    const passwordStatus = document.getElementById('accountPasswordStatus');

    if (!accountMenu || !accountButton || !accountDropdown) return;

    const setOpen = (open) => {
        accountDropdown.hidden = !open;
        accountButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    accountButton.addEventListener('click', function (event) {
        event.stopPropagation();
        setOpen(accountDropdown.hidden);
    });

    accountDropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function () {
        setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setOpen(false);
    });

    if (togglePasswordBtn && changePasswordForm) {
        togglePasswordBtn.addEventListener('click', function () {
            changePasswordForm.hidden = !changePasswordForm.hidden;
            if (!changePasswordForm.hidden) {
                changePasswordForm.querySelector('input')?.focus();
            }
        });
    }

    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const formData = new FormData(changePasswordForm);
            const payload = Object.fromEntries(formData.entries());
            if (passwordStatus) {
                passwordStatus.textContent = 'Saving...';
                passwordStatus.className = 'account-status';
            }

            try {
                const response = await apiFetch('api.php?action=change_own_password', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (result.success) {
                    setCsrfToken(result.data?.csrfToken);
                    changePasswordForm.reset();
                    if (passwordStatus) {
                        passwordStatus.textContent = 'Password changed.';
                        passwordStatus.className = 'account-status success';
                    }
                } else if (passwordStatus) {
                    passwordStatus.textContent = result.error || 'Could not change password.';
                    passwordStatus.className = 'account-status error';
                }
            } catch (e) {
                if (passwordStatus) {
                    passwordStatus.textContent = 'Network error';
                    passwordStatus.className = 'account-status error';
                }
            }
        });
    }
}

/**
 * Sets up the real-time search functionality for filtering printer cards.
 * Creates a "No Results" element and handles filtering/sorting of cards.
 * @returns {void}
 */
function setupSearch() {
    const searchInput = document.getElementById('printerSearch');
    if (!searchInput) return;

    // Create "No Results" element once (only if there are printers)
    const container = document.querySelector('.container');
    const hasStaticEmptyState = document.querySelector('.static-empty-state');

    // Don't create search no-results if no printers exist
    const noResultsElement = document.createElement('div');
    noResultsElement.className = 'empty-state-box no-results search-no-results';
    const noResultsTitle = document.createElement('strong');
    noResultsTitle.textContent = 'No printers found';
    const noResultsHint = document.createElement('p');
    noResultsHint.textContent = 'No printers match your search criteria.';
    const noResultsClearHint = document.createElement('p');
    noResultsClearHint.textContent = 'Try using different keywords or clear the search.';
    noResultsElement.append(noResultsTitle, noResultsHint, noResultsClearHint);
    noResultsElement.style.display = 'none'; // Hide initially

    // Only add to container if there are printers to search
    if (container && !hasStaticEmptyState) {
        container.appendChild(noResultsElement);
    }

    searchInput.addEventListener('input', function (e) {
        const searchTerm = e.target.value.toLowerCase();
        const container = document.querySelector('.container');
        if (!container) return;

        const activeFloorId = getActiveFloorId();
        const cubes = Array.from(container.getElementsByClassName('cube')).filter(cube =>
            !cube.classList.contains('no-results') && !cube.classList.contains('floor-empty-state')
        );

        // Count visible cubes to check if we need to show "no results"
        let visibleCount = 0;
        let delay = 0;

        // Sort cubes based on match relevance within the active floor.
        cubes.sort((a, b) => {
            const aText = a.textContent.toLowerCase();
            const bText = b.textContent.toLowerCase();

            const aIncludes = matchesActiveFloor(a, activeFloorId) && aText.includes(searchTerm);
            const bIncludes = matchesActiveFloor(b, activeFloorId) && bText.includes(searchTerm);

            // Prioritize cubes that include the search term
            if (aIncludes && !bIncludes) return -1;
            if (!aIncludes && bIncludes) return 1;
            return 0; // Maintain original order if both or neither include the term
        });

        // Reorder and filter cubes based on active floor + search term with animations
        cubes.forEach(cube => {
            const cubeText = cube.textContent.toLowerCase();
            const floorMatch = matchesActiveFloor(cube, activeFloorId);
            const hasMatch = floorMatch && (searchTerm === '' || cubeText.includes(searchTerm));

            if (hasMatch) {
                // Force animation replay: remove class, trigger reflow, then add
                cube.classList.remove('hidden-by-filter');
                cube.classList.remove('visible-by-filter');
                void cube.offsetWidth; // Trigger reflow to reset animation
                cube.classList.add('visible-by-filter');
                cube.style.animationDelay = `${delay * 0.05}s`; // Smooth stagger
                cube.style.order = '0';
                delay++;
                visibleCount++;
            } else {
                // Hide cube
                cube.classList.remove('visible-by-filter');
                cube.classList.add('hidden-by-filter');
                cube.style.order = '1';
            }
        });

        // Show/hide no results message based on visible count
        if (searchTerm !== '' && visibleCount === 0) {
            noResultsElement.style.display = 'block';
            noResultsElement.style.gridColumn = '1 / -1';
            noResultsElement.style.justifySelf = 'center';
            noResultsElement.style.maxWidth = '400px';
            noResultsElement.style.margin = '0 auto';
        } else {
            noResultsElement.style.display = 'none';
        }
    });
}

/** @type {HTMLElement|null} Modal dialog element for printer add/edit */
const PrinterModal = document.getElementById('printerModal');

/** @type {HTMLElement|null} Floating Action Button to add printer */
const AddPrinterBtn = document.getElementById('addPrinterBtn');

/** @type {HTMLElement|null} Close button for modal */
const CloseModalBtn = document.getElementsByClassName('close-modal')[0];

/** @type {HTMLFormElement|null} The printer form element */
const PrinterForm = document.getElementById('printerForm');

/**
 * Sets up the modal functionality for adding/editing printers.
 * Handles opening the modal, closing it, and closing when clicking outside.
 * Also attaches the check IP functionality to its button.
 * @returns {void}
 */
function setupModal() {
    if (!PrinterModal || !CloseModalBtn) return;

    // Open modal when 'Add Printer' button is clicked
    if (AddPrinterBtn && hasPermission('can_add_printer')) {
        AddPrinterBtn.onclick = function () {
            resetForm(); // Clear and reset the form fields
            document.getElementById('modalTitle').textContent = 'Add Printer'; // Set modal title
            PrinterModal.classList.add('show'); // Show the modal
            trapFocus(PrinterModal);
        };
    }

    // Close modal when the 'x' button is clicked
    CloseModalBtn.onclick = function () {
        PrinterModal.classList.remove('show'); // Hide the modal
        if (activeModalTrap) activeModalTrap.deactivate();
    }

    // Close modal if user clicks outside of the modal content
    window.addEventListener('click', function (event) {
        if (event.target == PrinterModal) {
            PrinterModal.classList.remove('show'); // Hide the modal
            if (activeModalTrap) activeModalTrap.deactivate();
        }
    });

    // Attach event listener for the 'Check IP' button
    const checkIpBtn = document.getElementById('checkIpBtn');
    if (checkIpBtn) {
        checkIpBtn.onclick = checkIp;
    }
}

/**
 * Resets the printer form to its initial state.
 * Clears all input fields and removes any status messages.
 * @returns {void}
 */
function resetForm() {
    PrinterForm.reset();
    document.getElementById('printerId').value = '';
    document.getElementById('ipStatus').textContent = '';
    document.getElementById('ipStatus').className = 'status-text';
    document.getElementById('printerIp').readOnly = false;
}

/**
 * Opens the modal in edit mode with pre-filled printer data.
 * @param {Object} printer - The printer data object
 * @param {number} printer.id - The printer ID
 * @param {string} printer.ip - The printer IP address
 * @param {string} [printer.full_name] - The printer's full name
 * @param {string} [printer.name] - The printer's name
 * @param {string} [printer.description] - The printer's description/location
 * @param {string} [printer.purchase_url] - URL to order supplies
 * @param {number|null} [printer.floor_id] - The floor ID
 * @returns {void}
 */
function openEditModal(printer) {
    if (!hasPermission('can_edit_printer')) return;
    resetForm();
    document.getElementById('modalTitle').textContent = 'Edit Printer';
    document.getElementById('printerId').value = printer.id;
    document.getElementById('printerIp').value = printer.ip;
    document.getElementById('printerIp').readOnly = true; // Cannot change IP on edit

    document.getElementById('printerName').value = printer.full_name || printer.name || '';
    document.getElementById('printerDesc').value = printer.description || '';
    document.getElementById('printerUrl').value = printer.purchase_url || '';

    // Set floor dropdown
    const floorSelect = document.getElementById('printerFloor');
    if (floorSelect) {
        floorSelect.value = printer.floor_id || '';
    }

    PrinterModal.classList.add('show');
}

/**
 * Checks if a printer exists at the given IP address via SNMP.
 * Makes an API call to verify the printer and auto-fills form fields if found.
 * @async
 * @returns {Promise<void>}
 */
async function checkIp() {
    if (!hasPermission('can_add_printer')) return;
    const ipInput = document.getElementById('printerIp');
    const statusSpan = document.getElementById('ipStatus');
    const ip = ipInput.value;

    if (!ip) {
        statusSpan.textContent = 'Please enter an IP address';
        statusSpan.className = 'status-text error';
        return;
    }

    statusSpan.textContent = 'Checking...';
    statusSpan.className = 'status-text';

    try {
        const response = await apiFetch('api.php?action=check_ip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ip: ip })
        });

        const result = await response.json();

        if (result.success) {
            statusSpan.textContent = 'Printer found! ' + (result.data.full_name || result.data.model);
            statusSpan.className = 'status-text success';

            // Auto-fill fields
            if (result.data.full_name) document.getElementById('printerName').value = result.data.full_name;
            if (result.data.model) document.getElementById('printerModel').value = result.data.model;
            if (result.data.serial_number) document.getElementById('printerSerial').value = result.data.serial_number;
            if (result.data.vendor) document.getElementById('printerVendor').value = result.data.vendor;
        } else {
            statusSpan.textContent = result.error || 'Printer not found or offline';
            statusSpan.className = 'status-text error';
        }
    } catch (e) {
        statusSpan.textContent = 'Error checking IP';
        statusSpan.className = 'status-text error';
    }
}

/**
 * Sets up the form submission handler for adding/editing printers.
 * Handles both add and edit operations based on whether an ID is present.
 * @returns {void}
 */
function setupForm() {
    if (!PrinterForm) return;

    PrinterForm.onsubmit = async function (e) {
        e.preventDefault();

        const formData = new FormData(PrinterForm);
        const data = Object.fromEntries(formData.entries());
        const id = document.getElementById('printerId').value;
        const action = id ? 'edit_printer' : 'add_printer';
        if ((action === 'edit_printer' && !hasPermission('can_edit_printer')) ||
            (action === 'add_printer' && !hasPermission('can_add_printer'))) {
            alert('You do not have permission to save printers.');
            return;
        }

        try {
            const response = await apiFetch(`api.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                PrinterModal.classList.remove('show');
                location.reload(); // Simple reload to show changes
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Network error');
        }
    }
}

/**
 * Deletes a printer from the database after user confirmation.
 * Removes the printer card from DOM on success.
 * @async
 * @param {number} id - The printer ID to delete
 * @returns {Promise<void>}
 */
async function deletePrinter(id) {
    if (!hasPermission('can_delete_printer')) return;
    if (!confirm('Delete this printer? This cannot be undone.')) {
        return;
    }

    try {
        const response = await apiFetch('api.php?action=delete_printer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });

        const result = await response.json();

        if (result.success) {
            // Remove from DOM
            const cube = document.querySelector(`.cube[data-id="${id}"]`);
            if (cube) cube.remove();

            // If no cubes left, show reload message or reload
            if (document.querySelectorAll('.cube').length === 0) {
                location.reload();
            }
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error');
    }
}

function setupPrinterActions() {
    const dashboardContainer = document.querySelector('.main-layout');
    if (!dashboardContainer) return;

    dashboardContainer.addEventListener('click', function (event) {
        const actionButton = event.target.closest('[data-action]');
        if (!actionButton || !dashboardContainer.contains(actionButton)) return;

        const action = actionButton.dataset.action;
        if (action === 'delete-printer') {
            const id = Number(actionButton.dataset.printerId || 0);
            if (Number.isInteger(id) && id > 0) {
                deletePrinter(id);
            }
            return;
        }

        if (action === 'edit-printer') {
            const printerInfo = parseJsonDataAttribute(actionButton, 'printerInfo', null);
            if (printerInfo && typeof printerInfo === 'object') {
                openEditModal(printerInfo);
            }
        }
    });
}

/**
 * Sets up import/export functionality for printer data backup.
 * Export includes printers, floors, and settings.
 * Import supports both new format (with floors) and old format (printers only).
 * @returns {void}
 */
function setupImportExport() {
    if (!hasPermission('can_import_export')) return;
    const exportBtn = document.getElementById('exportBtn');
    const importBtn = document.getElementById('importBtn');
    const importFile = document.getElementById('importFile');

    if (!exportBtn || !importBtn || !importFile) return;

    /**
     * Handles the export button click.
     * Exports all printers, floors, and settings as JSON.
     */
    exportBtn.onclick = async function () {
        try {
            const response = await apiFetch('api.php?action=export_printers');
            const result = await response.json();

            if (result.success) {
                // Create filename with date
                const date = new Date().toISOString().slice(0, 10);
                const filename = `sprinter_backup_${date}.json`;

                const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(result.data, null, 2));
                const downloadAnchorNode = document.createElement('a');
                downloadAnchorNode.setAttribute("href", dataStr);
                downloadAnchorNode.setAttribute("download", filename);
                document.body.appendChild(downloadAnchorNode);
                downloadAnchorNode.click();
                downloadAnchorNode.remove();
            } else {
                alert('Export failed: ' + (result.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Export failed: Network error');
        }
    };

    /**
     * Handles the import button click.
     * Triggers the hidden file input.
     */
    importBtn.onclick = function () {
        importFile.click();
    };

    /**
     * Handles file selection for import.
     * Parses the JSON file and sends it to the API.
     * Supports both new format (object with printers/floors) and old format (array of printers).
     * @param {Event} event - The file input change event
     */
    importFile.onchange = function (event) {
        const file = event.target.files[0];
        if (!file) return;

        if (file.size > MAX_IMPORT_FILE_BYTES) {
            alert('Import file is too large. Maximum allowed size is 2 MiB.');
            importFile.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = async function (e) {
            try {
                const data = JSON.parse(e.target.result);
                let payload;

                // Handle both new format (object) and old format (array)
                if (Array.isArray(data)) {
                    // Old format: direct array of printers
                    payload = { printers: data };
                } else if (data.printers && Array.isArray(data.printers)) {
                    // New format: object with printers, floors, settings
                    payload = data;
                } else {
                    alert('Invalid file format: Expected a Sprinter backup file.');
                    return;
                }

                const response = await apiFetch('api.php?action=import_printers', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (result.success) {
                    let msg = `Import complete!\n\n`;
                    msg += `• Printers: ${result.data.printerCount || 0}\n`;
                    msg += `• Floors: ${result.data.floorCount || 0}`;

                    if (result.data.errors && result.data.errors.length > 0) {
                        msg += `\n\nWarnings:\n` + result.data.errors.join('\n');
                    }
                    alert(msg);
                    location.reload();
                } else {
                    alert('Import failed: ' + (result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Error parsing JSON file. Please ensure it is a valid Sprinter backup.');
            }
            // Reset file input so same file can be selected again if needed
            importFile.value = '';
        };
        reader.readAsText(file);
    };
}

// ==================== SETTINGS MODAL ====================

/**
 * Sets up the settings modal functionality.
 * Handles opening/closing, tab switching, floor management, and display settings.
 * @returns {void}
 */
function setupSettings() {
    const settingsBtn = document.getElementById('settingsBtn');
    const settingsModal = document.getElementById('settingsModal');
    const closeSettingsBtn = document.getElementById('closeSettingsModal');

    if (!settingsBtn || !settingsModal) return;

    // Open settings modal
    settingsBtn.onclick = function () {
        settingsModal.classList.add('show');
        trapFocus(settingsModal);
    };

    // Close settings modal (no reload - use Save buttons)
    if (closeSettingsBtn) {
        closeSettingsBtn.onclick = function () {
            settingsModal.classList.remove('show');
            if (activeModalTrap) activeModalTrap.deactivate();
        };
    }

    // Close when clicking outside (no reload - use Save buttons)
    window.addEventListener('click', function (event) {
        if (event.target === settingsModal) {
            settingsModal.classList.remove('show');
            if (activeModalTrap) activeModalTrap.deactivate();
        }
    });

    // Tab switching
    const navItems = document.querySelectorAll('.settings-nav-item');
    const tabs = document.querySelectorAll('.settings-tab');

    navItems.forEach(item => {
        item.onclick = function () {
            const tabId = this.dataset.tab;

            // Update nav
            navItems.forEach(n => n.classList.remove('active'));
            this.classList.add('active');

            // Update tabs
            tabs.forEach(t => t.classList.remove('active'));
            const tab = document.getElementById(tabId + 'Tab');
            if (tab) tab.classList.add('active');
        };
    });

    // Floor management
    setupFloorManagement();

    // User management
    setupUserManagement();

    // Save display settings
    const saveSettingsBtn = document.getElementById('saveSettingsBtn');
    if (saveSettingsBtn) {
        saveSettingsBtn.onclick = saveDisplaySettings;
    }

    // Save floors and close (reload page to apply changes)
    const saveFloorsBtn = document.getElementById('saveFloorsBtn');
    if (saveFloorsBtn) {
        saveFloorsBtn.onclick = function () {
            settingsModal.classList.remove('show');
            location.reload();
        };
    }
}

function collectPermissionToggles(scope) {
    const permissions = {};
    scope.querySelectorAll('[data-permission]').forEach(input => {
        permissions[input.dataset.permission] = input.checked;
    });
    return permissions;
}

function setupUserManagement() {
    if (!hasPermission('can_manage_users')) return;

    const userCreateForm = document.getElementById('userCreateForm');
    const userList = document.getElementById('userList');

    if (userCreateForm) {
        userCreateForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const username = document.getElementById('newUsername')?.value.trim() || '';
            const password = document.getElementById('newUserPassword')?.value || '';
            const permissions = collectPermissionToggles(userCreateForm);

            try {
                const response = await apiFetch('api.php?action=create_user', {
                    method: 'POST',
                    body: JSON.stringify({ username, password, permissions, is_active: true })
                });
                const result = await response.json();
                if (result.success) {
                    alert('User created. Reloading user list.');
                    location.reload();
                } else {
                    alert('Create user failed: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Network error');
            }
        });
    }

    if (userList) {
        userList.addEventListener('click', async function (event) {
            const button = event.target.closest('.save-user-btn');
            if (!button) return;

            const row = button.closest('.user-row');
            if (!row) return;
            const id = parseInt(row.dataset.userId, 10);
            const permissions = collectPermissionToggles(row);
            const activeInput = row.querySelector('[data-field="is_active"]');
            const passwordInput = row.querySelector('.user-password-input');
            const payload = {
                id,
                permissions,
                is_active: activeInput ? activeInput.checked : true
            };
            if (passwordInput && passwordInput.value) {
                payload.password = passwordInput.value;
            }

            try {
                const response = await apiFetch('api.php?action=update_user_permissions', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (result.success) {
                    if (passwordInput) passwordInput.value = '';
                    alert('User saved.');
                } else {
                    alert('Update user failed: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Network error');
            }
        });
    }
}

/**
 * Sets up floor management (add, edit, delete floors).
 * @returns {void}
 */
function setupFloorManagement() {
    if (!hasPermission('can_manage_floors')) return;
    const addFloorBtn = document.getElementById('addFloorBtn');
    const newFloorInput = document.getElementById('newFloorName');
    const floorList = document.getElementById('floorList');

    // Add new floor
    if (addFloorBtn && newFloorInput) {
        addFloorBtn.onclick = async function () {
            const name = newFloorInput.value.trim();
            if (!name) return;

            try {
                const response = await apiFetch('api.php?action=add_floor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name })
                });
                const result = await response.json();

                if (result.success) {
                    // Add to list without parsing user-controlled text as HTML
                    const li = createFloorListItem(result.data.id, name);
                    floorList.appendChild(li);

                    // Clear input
                    newFloorInput.value = '';

                    // Hide "no floors" message
                    const noFloorsMsg = document.querySelector('.no-floors-msg');
                    if (noFloorsMsg) noFloorsMsg.style.display = 'none';

                    // Floor added - no reload, user can add more
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Network error');
            }
        };
    }

    // Delegate events for floor list (save/delete buttons)
    if (floorList) {
        floorList.addEventListener('click', async function (e) {
            const btn = e.target.closest('button');
            if (!btn) return;

            const li = btn.closest('.floor-list-item');
            const floorId = li.dataset.floorId;
            const input = li.querySelector('.floor-name-input');

            if (btn.classList.contains('save-floor-btn')) {
                // Save floor name
                const newName = input.value.trim();
                if (!newName) return;

                try {
                    const response = await apiFetch('api.php?action=edit_floor', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: floorId, name: newName })
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Floor saved - no reload until modal closes
                    } else {
                        alert('Error: ' + (result.error || 'Unknown error'));
                    }
                } catch (e) {
                    alert('Network error');
                }
            } else if (btn.classList.contains('delete-floor-btn')) {
                // Delete floor
                if (!confirm('Delete this floor? Printers assigned to it will become unassigned.')) {
                    return;
                }

                try {
                    const response = await apiFetch('api.php?action=delete_floor', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: floorId })
                    });
                    const result = await response.json();

                    if (result.success) {
                        li.remove();
                        // Floor deleted - no reload until modal closes
                    } else {
                        alert('Error: ' + (result.error || 'Unknown error'));
                    }
                } catch (e) {
                    alert('Network error');
                }
            }
        });
    }
}

/**
 * Saves display settings to the server.
 * @returns {Promise<void>}
 */
async function saveDisplaySettings() {
    if (!hasPermission('can_manage_settings')) return;
    const hideIp = document.getElementById('settingHideIp').checked;
    const showSerial = document.getElementById('settingShowSerial').checked;

    try {
        const response = await apiFetch('api.php?action=save_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hide_ip: hideIp ? '1' : '0',
                show_serial: showSerial ? '1' : '0'
            })
        });
        const result = await response.json();

        if (result.success) {
            // Update global settings before applying them to the DOM
            window.appSettings = {
                hide_ip: hideIp ? '1' : '0',
                show_serial: showSerial ? '1' : '0'
            };

            // Apply settings immediately
            applyDisplaySettings();

            alert('Settings saved!');
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error');
    }
}

// ==================== FLOOR FILTER ====================

/**
 * Sets up the floor filter sidebar functionality.
 * Stores active floor in localStorage and filters printer cards.
 * @returns {void}
 */
function setupFloorFilter() {
    const floorItems = document.querySelectorAll('.floor-item');
    if (floorItems.length === 0) return;

    // Restore saved floor selection
    const savedFloor = localStorage.getItem('activeFloor') || 'all';
    setActiveFloor(savedFloor);

    // Handle floor clicks
    floorItems.forEach(item => {
        item.onclick = function () {
            const floorId = this.dataset.floorId;
            setActiveFloor(floorId);
            localStorage.setItem('activeFloor', floorId);
        };
    });
}

/**
 * Sets the active floor and filters printer cards.
 * @param {string} floorId - The floor ID to filter by, or 'all'
 * @returns {void}
 */
function setActiveFloor(floorId) {
    // Clear search input to prevent confusion
    const searchInput = document.getElementById('printerSearch');
    if (searchInput) {
        searchInput.value = '';
    }

    // Hide search "no results" message
    const searchNoResults = document.querySelector('.search-no-results');
    if (searchNoResults) {
        searchNoResults.style.display = 'none';
    }

    // Update sidebar
    document.querySelectorAll('.floor-item').forEach(item => {
        item.classList.toggle('active', item.dataset.floorId === floorId);
    });

    // Filter cards with smooth animation
    const cubes = document.querySelectorAll('.cube:not(.no-results):not(.floor-empty-state)');
    let visibleCount = 0;
    let delay = 0;

    cubes.forEach(cube => {
        const cubeFloorId = cube.dataset.floorId || '';
        const shouldShow = (floorId === 'all') || (cubeFloorId === floorId);

        if (shouldShow) {
            // Force animation replay: remove class, trigger reflow, then add
            cube.classList.remove('hidden-by-filter');
            cube.classList.remove('visible-by-filter');
            void cube.offsetWidth; // Trigger reflow to reset animation
            cube.classList.add('visible-by-filter');
            cube.style.animationDelay = `${delay * 0.08}s`; // Slower stagger
            delay++;
            visibleCount++;
        } else {
            // Hide cube
            cube.classList.remove('visible-by-filter');
            cube.classList.add('hidden-by-filter');
        }
    });

    // Show/hide floor empty state message
    let floorEmptyMsg = document.querySelector('.floor-empty-state');

    if (floorId !== 'all' && visibleCount === 0) {
        // Show empty floor message
        if (!floorEmptyMsg) {
            floorEmptyMsg = document.createElement('div');
            floorEmptyMsg.className = 'empty-state-box floor-empty-state';
            const title = document.createElement('strong');
            title.textContent = 'No printers on this floor';
            const hint = document.createElement('p');
            hint.textContent = "This floor doesn't have any printers assigned yet.";
            const addHint = document.createElement('p');
            addHint.append('Click the ');
            const plus = document.createElement('strong');
            plus.textContent = '+';
            addHint.append(plus, ' button to add a printer, then assign it to this floor.');
            floorEmptyMsg.append(title, hint, addHint);
            const container = document.querySelector('.container');
            if (container) container.appendChild(floorEmptyMsg);
        }
        floorEmptyMsg.style.display = 'block';
    } else {
        // Hide empty floor message
        if (floorEmptyMsg) {
            floorEmptyMsg.style.display = 'none';
        }
    }
}

// ==================== DISPLAY SETTINGS ====================

/**
 * Applies display settings (hide IP, show serial) to the page.
 * @returns {void}
 */
function applyDisplaySettings() {
    const settings = window.appSettings || {};

    // Hide IP addresses
    const hideIp = settings.hide_ip === '1';
    document.querySelectorAll('.last-updated').forEach(el => {
        if (el.textContent.includes('IP:')) {
            el.style.display = hideIp ? 'none' : 'block';
        }
    });

    // Show/hide serial numbers
    const showSerial = settings.show_serial !== '0'; // Default to showing
    document.querySelectorAll('.info-item').forEach(el => {
        const strong = el.querySelector('strong');
        if (strong && strong.textContent.includes('Serial:')) {
            el.style.display = showSerial ? 'block' : 'none';
        }
    });
}

// ==================== HELPERS ====================

/**
 * Capitalizes the first letter of a string.
 * @param {string} string - The string to capitalize
 * @returns {string} The capitalized string
 */
function capitalizeFirstLetter(string) {
    if (!string) return '';
    return string.charAt(0).toUpperCase() + string.slice(1);
}

function stableStringify(value) {
    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`;
    }

    if (value && typeof value === 'object') {
        return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
    }

    return JSON.stringify(value);
}

function getLevelsRenderSignature(status, levels, lastLevels) {
    return stableStringify({
        status,
        levels: levels || null,
        lastLevels: lastLevels || null
    });
}

function getColorToken(color) {
    return String(color).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'unknown';
}

function getLevelPresentation(level = {}) {
    const normalizedLevel = Number(level.percentage ?? 0);
    let displayPercentage = `${Number.isFinite(normalizedLevel) ? normalizedLevel : 0}%`;
    let barWidth = Math.max(0, Number.isFinite(normalizedLevel) ? normalizedLevel : 0);
    const isOk = level.status === 'ok';
    const isEmpty = level.status === 'empty_or_not_genuine' || normalizedLevel <= 0;

    if (isOk) {
        displayPercentage = 'OK';
        barWidth = 100;
    } else if (isEmpty) {
        displayPercentage = 'Not Genuine / Empty Cartridge';
        barWidth = 0;
    }

    return { displayPercentage, barWidth, isEmpty };
}

function ensureLevelBarStructure(levelBar) {
    let label = levelBar.querySelector('.level-label');
    if (!label) {
        label = document.createElement('div');
        label.className = 'level-label';
        levelBar.appendChild(label);
    }

    let colorLabel = label.querySelector('.level-color-label');
    if (!colorLabel) {
        colorLabel = document.createElement('span');
        colorLabel.className = 'level-color-label';
        label.appendChild(colorLabel);
    }

    let percentageLabel = label.querySelector('.level-percentage-label');
    if (!percentageLabel) {
        percentageLabel = document.createElement('span');
        percentageLabel.className = 'level-percentage-label';
        label.appendChild(percentageLabel);
    }

    let container = levelBar.querySelector('.level-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'level-container';
        levelBar.appendChild(container);
    }

    let fill = container.querySelector('.level-fill');
    if (!fill) {
        fill = document.createElement('div');
        container.appendChild(fill);
    }

    return { colorLabel, percentageLabel, fill };
}

/**
 * Safely renders a level-bar element using DOM APIs.
 * @param {string} color - Supply color/name
 * @param {Object} level - Supply level metadata
 * @param {boolean} faded - Whether to render as last-known/faded data
 * @returns {HTMLElement} The rendered level bar
 */
function createLevelBar(color, level = {}, faded = false) {
    const levelBar = document.createElement('div');
    levelBar.dataset.levelKey = String(color);
    ensureLevelBarStructure(levelBar);
    updateLevelBar(levelBar, color, level, faded);
    return levelBar;
}

function updateLevelBar(levelBar, color, level = {}, faded = false) {
    const { colorLabel, percentageLabel, fill } = ensureLevelBarStructure(levelBar);
    const colorToken = getColorToken(color);
    const { displayPercentage, barWidth, isEmpty } = getLevelPresentation(level);

    levelBar.dataset.levelKey = String(color);
    levelBar.className = '';
    levelBar.classList.add('level-bar');
    if (faded) levelBar.classList.add('faded');
    if (isEmpty) levelBar.classList.add('warning-level');

    colorLabel.textContent = capitalizeFirstLetter(String(color));
    percentageLabel.textContent = displayPercentage;

    fill.className = '';
    fill.classList.add('level-fill', `${colorToken}-fill`);
    fill.dataset.level = String(barWidth);
    fill.style.width = `${barWidth}%`;

    // Accessible progressbar semantics.
    const container = levelBar.querySelector('.level-container');
    if (container) {
        container.setAttribute('role', 'progressbar');
        container.setAttribute('aria-valuemin', '0');
        container.setAttribute('aria-valuemax', '100');
        container.setAttribute('aria-valuenow', String(Math.round(barWidth)));
        const labelText = `${capitalizeFirstLetter(String(color))}: ${displayPercentage}`;
        container.setAttribute('aria-label', labelText);
    }
}

function hasLevelEntries(levels) {
    return levels && typeof levels === 'object' && Object.keys(levels).length > 0;
}

function createTextMessage(className, text) {
    const message = document.createElement('div');
    message.className = className;
    message.textContent = text;
    return message;
}

function setSingleMessage(levelsSection, className, text) {
    const existing = levelsSection.children.length === 1 ? levelsSection.firstElementChild : null;
    if (existing && existing.className === className) {
        existing.textContent = text;
    } else {
        levelsSection.replaceChildren(createTextMessage(className, text));
    }
}

function createLastSeenLevels(labelText, levels, faded = true) {
    const wrapper = document.createElement('div');
    wrapper.className = 'last-seen-levels';

    const label = document.createElement('small');
    label.textContent = labelText;
    wrapper.appendChild(label);
    syncLevelBars(wrapper, levels, faded);

    return wrapper;
}

function syncLevelBars(container, levels, faded = false) {
    const existingBars = new Map();
    for (const child of Array.from(container.children)) {
        if (child.classList.contains('level-bar') && child.dataset.levelKey) {
            existingBars.set(child.dataset.levelKey, child);
        }
    }

    const fragment = document.createDocumentFragment();
    const label = container.firstElementChild?.tagName === 'SMALL' ? container.firstElementChild : null;
    if (label) {
        fragment.appendChild(label);
    }

    for (const [color, level] of Object.entries(levels || {})) {
        const key = String(color);
        const levelBar = existingBars.get(key) || createLevelBar(color, level, faded);
        updateLevelBar(levelBar, color, level, faded);
        fragment.appendChild(levelBar);
        existingBars.delete(key);
    }

    container.replaceChildren(fragment);
}

function renderOfflineLevels(levelsSection, lastLevels) {
    const fragment = document.createDocumentFragment();
    let message = levelsSection.querySelector('.offline-message');
    if (!message) {
        message = createTextMessage('offline-message', 'Printer is currently offline');
    } else {
        message.textContent = 'Printer is currently offline';
    }
    fragment.appendChild(message);

    if (hasLevelEntries(lastLevels)) {
        let wrapper = levelsSection.querySelector('.last-seen-levels');
        if (!wrapper) {
            wrapper = createLastSeenLevels('Last known levels:', lastLevels, true);
        } else {
            let label = wrapper.firstElementChild?.tagName === 'SMALL' ? wrapper.firstElementChild : null;
            if (!label) {
                label = document.createElement('small');
                wrapper.prepend(label);
            }
            label.textContent = 'Last known levels:';
            syncLevelBars(wrapper, lastLevels, true);
        }
        fragment.appendChild(wrapper);
    }

    levelsSection.replaceChildren(fragment);
}

function renderWrappedLevels(levelsSection, labelText, levels, faded) {
    let wrapper = levelsSection.children.length === 1 && levelsSection.firstElementChild?.classList.contains('last-seen-levels')
        ? levelsSection.firstElementChild
        : null;

    if (!wrapper) {
        wrapper = createLastSeenLevels(labelText, levels, faded);
    } else {
        let label = wrapper.firstElementChild?.tagName === 'SMALL' ? wrapper.firstElementChild : null;
        if (!label) {
            label = document.createElement('small');
            wrapper.prepend(label);
        }
        label.textContent = labelText;
        syncLevelBars(wrapper, levels, faded);
    }

    levelsSection.replaceChildren(wrapper);
}

/**
 * Renders the levels section with DOM APIs instead of HTML strings.
 * @param {HTMLElement} levelsSection - Target levels container
 * @param {string} status - Printer status
 * @param {Object} levels - Printer supply levels
 * @param {Object|null} lastLevels - Last known levels from DB
 * @returns {void}
 */
function renderLevelsSection(levelsSection, status, levels, lastLevels) {
    const signature = getLevelsRenderSignature(status, levels, lastLevels);
    if (levelsSection.dataset.renderSignature === signature) {
        return;
    }

    if (status === 'offline') {
        renderOfflineLevels(levelsSection, lastLevels);
    } else if (status === 'loading') {
        if (hasLevelEntries(levels)) {
            renderWrappedLevels(levelsSection, 'Loading live levels...', levels, true);
        } else {
            setSingleMessage(levelsSection, 'no-data-message pulse-loading', 'Checking printer status...');
        }
    } else if (hasLevelEntries(levels)) {
        syncLevelBars(levelsSection, levels, false);
    } else {
        setSingleMessage(levelsSection, 'no-data-message', 'No ink/toner level data available');
    }

    levelsSection.dataset.renderSignature = signature;
}

function cancelActiveRefreshes() {
    if (activeRefreshController) {
        activeRefreshController.abort();
        activeRefreshController = null;
    }
}

function getRefreshPriority(cube, activeFloorId) {
    const matchesFloor = matchesActiveFloor(cube, activeFloorId);
    const isHidden = cube.classList.contains('hidden-by-filter');

    if (matchesFloor && !isHidden) return 0;
    if (matchesFloor) return 1;
    return 2;
}

function sortCubesForRefresh(cubes) {
    const activeFloorId = getActiveFloorId();
    return [...cubes].sort((a, b) => {
        const aMatchesActiveFloor = matchesActiveFloor(a, activeFloorId);
        const bMatchesActiveFloor = matchesActiveFloor(b, activeFloorId);
        if (aMatchesActiveFloor !== bMatchesActiveFloor) {
            return aMatchesActiveFloor ? -1 : 1;
        }
        return getRefreshPriority(a, activeFloorId) - getRefreshPriority(b, activeFloorId);
    });
}

/**
 * Returns the lowest supply percentage from a cube, for auto-sorting.
 * Cubes with no level data return Infinity so they sort to the end.
 * @param {HTMLElement} cube - The printer card element
 * @returns {number}
 */
function getCubeLowestLevel(cube) {
    const minLevel = cube.dataset.minLevel;
    if (minLevel === '' || minLevel === undefined) return Infinity;
    const parsed = parseFloat(minLevel);
    return Number.isFinite(parsed) ? parsed : Infinity;
}

/**
 * Reorders printer cards in the DOM by lowest supply level first.
 * Offline/no-data cards go to the end. Called after refresh-all completes.
 * Skips if the search box has active text — search relevance sorting takes priority.
 * Clears any CSS `order` styles left by search so DOM order is visually authoritative.
 * @returns {void}
 */
function sortCubesByLowestLevel() {
    const container = document.querySelector('.container');
    if (!container) return;

    // Don't re-sort while search is active — search sets CSS `order` which
    // overrides DOM order (CSS Flexbox spec: visual order is independent of source order).
    const searchInput = document.getElementById('printerSearch');
    if (searchInput && searchInput.value.trim() !== '') return;

    const cubes = Array.from(container.querySelectorAll('.cube:not(.no-results):not(.floor-empty-state)'));
    if (cubes.length <= 1) return;

    // Clear any leftover CSS `order` from previous search/floor-filter operations.
    cubes.forEach(cube => { cube.style.order = ''; });

    const sorted = cubes.sort((a, b) => getCubeLowestLevel(a) - getCubeLowestLevel(b));
    sorted.forEach(cube => container.appendChild(cube));
}

// ==================== MODAL FOCUS MANAGEMENT ====================

let activeModalTrap = null;

/**
 * Activates a focus trap on a modal element.
 * Saves the previously focused element, focuses the modal,
 * and intercepts Tab to cycle within the modal.
 * @param {HTMLElement} modal - The modal element to trap focus in
 * @returns {void}
 */
function trapFocus(modal) {
    if (activeModalTrap) {
        activeModalTrap.deactivate();
    }

    const previouslyFocused = document.activeElement;

    const getFocusable = () => modal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );

    const focusFirst = () => {
        const focusable = getFocusable();
        if (focusable.length > 0) {
            focusable[0].focus();
        } else {
            modal.focus();
        }
    };

    // Defer focus so the modal is visible first.
    requestAnimationFrame(focusFirst);

    const onKeyDown = (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            modal.classList.remove('show');
            return;
        }

        if (e.key !== 'Tab') return;

        const focusable = getFocusable();
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first || !modal.contains(document.activeElement)) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    };

    modal.addEventListener('keydown', onKeyDown);

    activeModalTrap = {
        deactivate() {
            modal.removeEventListener('keydown', onKeyDown);
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
            if (activeModalTrap === this) {
                activeModalTrap = null;
            }
        }
    };
}

/**
 * Refreshes a single printer card's live data in the background.
 * @async
 * @param {HTMLElement} cube - The printer card element
 * @returns {Promise<void>}
 */
async function refreshPrinterCard(cube, signal) {
    const ip = cube.getAttribute('data-ip');
    if (!ip) return;
    
    const refreshToken = {};
    activeCardRefreshTokens.set(cube, refreshToken);
    cube.classList.add('refreshing');
    
    try {
        const response = await apiFetch('api.php?action=refresh_printer&ip=' + encodeURIComponent(ip), { signal });
        const result = await response.json();
        
        if (result.success && result.data) {
            const data = result.data;
            const info = data.info || {};
            const levels = data.levels || {};
            
            // 1. Update status classes and indicator
            const status = info.status || 'offline';
            const indicator = cube.querySelector('.status-indicator');
            if (indicator) {
                indicator.className = `status-indicator ${status}`;
                indicator.setAttribute('aria-label', `Status: ${capitalizeFirstLetter(status)}`);
            }
            if (status === 'offline') {
                cube.classList.add('offline-cube');
            } else {
                cube.classList.remove('offline-cube');
            }
            
            // 2. Update name if available
            const nameSpan = cube.querySelector('.printer-name');
            if (nameSpan && info.full_name) {
                nameSpan.textContent = info.full_name;
            }
            
            // 3. Update serial if available
            const serialSpan = cube.querySelector('.printer-serial');
            if (serialSpan) {
                serialSpan.textContent = info.serial_number_display || info.serial_number || 'N/A';
            }
            
            // 4. Parse last_levels if offline
            let lastLevels = null;
            if (info.last_levels) {
                try {
                    lastLevels = typeof info.last_levels === 'string' ? JSON.parse(info.last_levels) : info.last_levels;
                } catch (e) {}
            }
            
            // 5. Update level section via DOM-safe renderer
            const levelsSection = cube.querySelector('.levels-section');
            if (levelsSection) {
                levelsSection.dataset.initialStatus = status;
                levelsSection.dataset.initialLevels = JSON.stringify(levels);
                levelsSection.dataset.initialLastLevels = JSON.stringify(lastLevels);
                renderLevelsSection(levelsSection, status, levels, lastLevels);
            }
            
            // 6. Update edit button click handler
            const id = cube.getAttribute('data-id');
            const floorId = cube.getAttribute('data-floor-id');
            const fullInfo = {
                id: id ? parseInt(id) : null,
                floor_id: floorId ? parseInt(floorId) : null,
                ip: info.ip || ip,
                full_name: info.full_name,
                description: info.description,
                purchase_url: info.purchase_url,
                serial_number: info.serial_number,
                model: info.model,
                last_levels: info.last_levels,
                status: status
            };
            
            const editBtn = cube.querySelector('.edit-btn');
            if (editBtn) {
                editBtn.dataset.printerInfo = JSON.stringify(fullInfo);
            }

            // 7. Store lowest level for auto-sorting
            const levelPercentages = Object.values(levels).map(l => Number(l.percentage ?? 100));
            cube.dataset.minLevel = levelPercentages.length > 0 ? String(Math.min(...levelPercentages)) : '';
        } else {
            // Handle error or set to offline
            const indicator = cube.querySelector('.status-indicator');
            if (indicator) {
                indicator.className = `status-indicator offline`;
            }
            cube.classList.add('offline-cube');
        }
    } catch (e) {
        if (e.name === 'AbortError') {
            return;
        }

        console.error('Failed to refresh printer ' + ip, e);
        // On network error, set to offline
        const indicator = cube.querySelector('.status-indicator');
        if (indicator) {
            indicator.className = `status-indicator offline`;
        }
        cube.classList.add('offline-cube');
    } finally {
        if (activeCardRefreshTokens.get(cube) === refreshToken) {
            activeCardRefreshTokens.delete(cube);
            cube.classList.remove('refreshing');
            cube.classList.remove('loading-state');
        }
    }
}

/**
 * Executes an array of task-returning functions with limited concurrency.
 * @async
 * @param {Array<function(): Promise<any>>} tasks - Array of functions returning promises
 * @param {number} limit - Concurrency limit
 * @returns {Promise<Array<any>>} Resolved results of all promises
 */
async function throttlePromises(tasks, limit) {
    const results = [];
    const executing = new Set();
    for (const task of tasks) {
        const p = Promise.resolve().then(() => task());
        results.push(p);
        executing.add(p);
        const clean = () => executing.delete(p);
        p.then(clean, clean);
        if (executing.size >= limit) {
            await Promise.race(executing);
        }
    }
    return Promise.all(results);
}

/**
 * Refreshes all printer cards concurrently in the background.
 * @async
 * @returns {Promise<void>}
 */
async function refreshAllPrinters() {
    cancelActiveRefreshes();

    const cubes = Array.from(document.querySelectorAll('.cube:not(.no-results):not(.floor-empty-state)'));
    if (cubes.length === 0) return;

    const refreshController = new AbortController();
    activeRefreshController = refreshController;
    isRefreshingAllPrinters = true;
    const refreshBtn = document.getElementById('refreshDataBtn');
    if (refreshBtn) {
        refreshBtn.disabled = true;
        refreshBtn.textContent = 'Refreshing...';
    }

    try {
        // Prioritize visible/current-floor cards, then run with concurrency limit of 4.
        const tasks = sortCubesForRefresh(cubes).map(cube => () => refreshPrinterCard(cube, refreshController.signal));

        // Wait for all to complete using the high-performance Promise Throttle Pool
        await throttlePromises(tasks, 4);

        // Auto-sort cubes by lowest toner/ink level so urgent printers are first.
        sortCubesByLowestLevel();

        // Update the last updated time
        const lastRefreshTime = document.getElementById('lastRefreshTime');
        if (lastRefreshTime) {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            const hh = String(now.getHours()).padStart(2, '0');
            const min = String(now.getMinutes()).padStart(2, '0');
            const ss = String(now.getSeconds()).padStart(2, '0');
            lastRefreshTime.textContent = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
        }
    } finally {
        if (activeRefreshController === refreshController) {
            activeRefreshController = null;
            isRefreshingAllPrinters = false;
            if (refreshBtn) {
                refreshBtn.disabled = false;
                refreshBtn.textContent = 'Refresh Printer Data';
            }
        }
    }
}

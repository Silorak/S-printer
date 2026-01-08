/**
 * @fileoverview Sprinter - Client-side functionality
 * Handles theme switching, search, modals, and API interactions.
 * @author Sprinter Team
 */

/**
 * Initialize all functionality when DOM is ready
 */
document.addEventListener('DOMContentLoaded', function () {
    // Apply width to level bars via JavaScript to avoid inline styles
    document.querySelectorAll('.level-fill').forEach(function (fill) {
        const level = fill.getAttribute('data-level') || '0';
        fill.style.width = level + '%';
    });

    // Theme switching
    setupThemeToggle();

    // Search functionality
    setupSearch();

    // Modal functionality
    setupModal();

    // Import/Export functionality
    setupImportExport();

    // Form submission
    setupForm();

    // Settings modal
    setupSettings();

    // Floor filter sidebar
    setupFloorFilter();

    // Apply display settings
    applyDisplaySettings();
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
    noResultsElement.innerHTML = `
        <strong>No printers found</strong>
        <p>No printers match your search criteria.</p>
        <p>Try using different keywords or clear the search.</p>
    `;
    noResultsElement.style.display = 'none'; // Hide initially

    // Only add to container if there are printers to search
    if (container && !hasStaticEmptyState) {
        container.appendChild(noResultsElement);
    }

    searchInput.addEventListener('input', function (e) {
        const searchTerm = e.target.value.toLowerCase();
        const container = document.querySelector('.container');
        const cubes = Array.from(container.getElementsByClassName('cube')).filter(cube =>
            !cube.classList.contains('no-results') && !cube.classList.contains('floor-empty-state')
        );

        // Count visible cubes to check if we need to show "no results"
        let visibleCount = 0;
        let delay = 0;

        // Sort cubes based on match relevance: exact matches first, then partial, then no match
        cubes.sort((a, b) => {
            const aText = a.textContent.toLowerCase();
            const bText = b.textContent.toLowerCase();

            const aIncludes = aText.includes(searchTerm);
            const bIncludes = bText.includes(searchTerm);

            // Prioritize cubes that include the search term
            if (aIncludes && !bIncludes) return -1;
            if (!aIncludes && bIncludes) return 1;
            return 0; // Maintain original order if both or neither include the term
        });

        // Reorder and filter cubes based on search term with animations
        cubes.forEach(cube => {
            const cubeText = cube.textContent.toLowerCase();
            const hasMatch = searchTerm === '' || cubeText.includes(searchTerm);

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
    if (!PrinterModal || !AddPrinterBtn || !CloseModalBtn) return;

    // Open modal when 'Add Printer' button is clicked
    AddPrinterBtn.onclick = function () {
        resetForm(); // Clear and reset the form fields
        document.getElementById('modalTitle').textContent = 'Add Printer'; // Set modal title
        PrinterModal.classList.add('show'); // Show the modal
    }

    // Close modal when the 'x' button is clicked
    CloseModalBtn.onclick = function () {
        PrinterModal.classList.remove('show'); // Hide the modal
    }

    // Close modal if user clicks outside of the modal content
    window.onclick = function (event) {
        if (event.target == PrinterModal) {
            PrinterModal.classList.remove('show'); // Hide the modal
        }
    }

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
        const response = await fetch('api.php?action=check_ip', {
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

        try {
            const response = await fetch(`api.php?action=${action}`, {
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
    if (!confirm('Are you sure you want to delete this printer?')) return;

    try {
        const response = await fetch('api.php?action=delete_printer', {
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

/**
 * Sets up import/export functionality for printer data backup.
 * Export includes printers, floors, and settings.
 * Import supports both new format (with floors) and old format (printers only).
 * @returns {void}
 */
function setupImportExport() {
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
            const response = await fetch('api.php?action=export_printers');
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

                const response = await fetch('api.php?action=import_printers', {
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
    };

    // Close settings modal (no reload - use Save buttons)
    if (closeSettingsBtn) {
        closeSettingsBtn.onclick = function () {
            settingsModal.classList.remove('show');
        };
    }

    // Close when clicking outside (no reload - use Save buttons)
    window.addEventListener('click', function (event) {
        if (event.target === settingsModal) {
            settingsModal.classList.remove('show');
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
            document.getElementById(tabId + 'Tab').classList.add('active');
        };
    });

    // Floor management
    setupFloorManagement();

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

/**
 * Sets up floor management (add, edit, delete floors).
 * @returns {void}
 */
function setupFloorManagement() {
    const addFloorBtn = document.getElementById('addFloorBtn');
    const newFloorInput = document.getElementById('newFloorName');
    const floorList = document.getElementById('floorList');

    // Add new floor
    if (addFloorBtn && newFloorInput) {
        addFloorBtn.onclick = async function () {
            const name = newFloorInput.value.trim();
            if (!name) return;

            try {
                const response = await fetch('api.php?action=add_floor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name })
                });
                const result = await response.json();

                if (result.success) {
                    // Add to list
                    const li = document.createElement('li');
                    li.className = 'floor-list-item';
                    li.dataset.floorId = result.data.id;
                    li.innerHTML = `
                        <input type="text" class="floor-name-input" value="${escapeHtml(name)}">
                        <button type="button" class="save-floor-btn secondary-btn" title="Save">✓</button>
                        <button type="button" class="delete-floor-btn danger-btn" title="Delete">×</button>
                    `;
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
                    const response = await fetch('api.php?action=edit_floor', {
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
                if (!confirm('Delete this floor? Printers will be unassigned.')) return;

                try {
                    const response = await fetch('api.php?action=delete_floor', {
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
    const hideIp = document.getElementById('settingHideIp').checked;
    const showSerial = document.getElementById('settingShowSerial').checked;

    try {
        const response = await fetch('api.php?action=save_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hide_ip: hideIp ? '1' : '0',
                show_serial: showSerial ? '1' : '0'
            })
        });
        const result = await response.json();

        if (result.success) {
            // Apply settings immediately
            applyDisplaySettings();

            // Update global settings
            window.appSettings = {
                hide_ip: hideIp ? '1' : '0',
                show_serial: showSerial ? '1' : '0'
            };

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
            floorEmptyMsg.innerHTML = `
                <strong>No printers on this floor</strong>
                <p>This floor doesn't have any printers assigned yet.</p>
                <p>Click the <strong>+</strong> button to add a printer, then assign it to this floor.</p>
            `;
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
 * Escapes HTML entities to prevent XSS.
 * @param {string} text - The text to escape
 * @returns {string} The escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

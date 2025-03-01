document.addEventListener('DOMContentLoaded', function() {
    // Apply width to level bars via JavaScript to avoid inline styles
    document.querySelectorAll('.level-fill').forEach(function(fill) {
        const level = fill.getAttribute('data-level') || '0';
        fill.style.width = level + '%';
    });
    
    // Theme switching
    setupThemeToggle();
    
    // Search functionality
    setupSearch();
});

function setupThemeToggle() {
    const themeToggle = document.getElementById('themeToggle');
    if (!themeToggle) return;
    
    // Check for saved theme preference or default to 'dark'
    const currentTheme = localStorage.getItem('theme') || 'dark';
    
    // Apply the saved theme on initial load
    if (currentTheme === 'light') {
        document.body.classList.add('light-theme');
    }
    
    // Toggle theme on button click
    themeToggle.addEventListener('click', function() {
        // Toggle the class
        const isLightTheme = document.body.classList.toggle('light-theme');
        
        // Save the preference
        localStorage.setItem('theme', isLightTheme ? 'light' : 'dark');
        
        // Dispatch a custom event for other scripts that might need to know about theme changes
        document.dispatchEvent(new CustomEvent('themeChanged', { 
            detail: { theme: isLightTheme ? 'light' : 'dark' } 
        }));
    });
}

function setupSearch() {
    const searchInput = document.getElementById('printerSearch');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const container = document.querySelector('.container');
        const cubes = Array.from(container.getElementsByClassName('cube'));
        
        // Sort cubes based on match relevance
        cubes.sort((a, b) => {
            const aText = a.textContent.toLowerCase();
            const bText = b.textContent.toLowerCase();
            return bText.includes(searchTerm) - aText.includes(searchTerm);
        });
        
        // Reorder and filter cubes
        cubes.forEach(cube => {
            const cubeText = cube.textContent.toLowerCase();
            const hasMatch = searchTerm === '' || cubeText.includes(searchTerm);
            
            cube.style.display = hasMatch ? 'block' : 'none';
            cube.style.order = hasMatch ? '0' : '1';
        });
        
        // Force grid layout update
        container.style.display = 'none';
        container.offsetHeight; // Trigger reflow
        container.style.display = 'grid';
    });
}
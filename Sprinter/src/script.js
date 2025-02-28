// Theme switching
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.querySelector('.theme-icon');
    
    // Check for saved theme preference or default to 'dark'
    const currentTheme = localStorage.getItem('theme') || 'dark';
    
    // Apply the saved theme on initial load
    if (currentTheme === 'light') {
        document.body.classList.add('light-theme');
        themeIcon.textContent = '☀️';
    } else {
        themeIcon.textContent = '🌙';
    }
    
    // Toggle theme on button click
    themeToggle.addEventListener('click', function() {
        if (document.body.classList.contains('light-theme')) {
            // Switch to dark theme
            document.body.classList.remove('light-theme');
            localStorage.setItem('theme', 'dark');
            themeIcon.textContent = '🌙';
        } else {
            // Switch to light theme
            document.body.classList.add('light-theme');
            localStorage.setItem('theme', 'light');
            themeIcon.textContent = '☀️';
        }
    });document.addEventListener('DOMContentLoaded', function() {
    // Apply width to level bars via JavaScript to avoid inline styles
    document.querySelectorAll('.level-fill').forEach(function(fill) {
        const level = fill.getAttribute('data-level') || '0';
        fill.style.width = level + '%';
    });
    // Search functionality
    const searchInput = document.getElementById('printerSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const container = document.querySelector('.container');
            const cubes = Array.from(container.getElementsByClassName('cube'));
            
            // Sort cubes based on match relevance
            cubes.sort((a, b) => {
                const aText = a.textContent.toLowerCase();
                const bText = b.textContent.toLowerCase();
                const aMatch = aText.includes(searchTerm);
                const bMatch = bText.includes(searchTerm);
                
                // Sort matched items first, then by relevance score
                if (aMatch && !bMatch) return -1;
                if (!aMatch && bMatch) return 1;
                
                // If both match or both don't match, sort by relevance score
                const aScore = getRelevanceScore(aText, searchTerm);
                const bScore = getRelevanceScore(bText, searchTerm);
                return bScore - aScore;
            });
            
            // Reorder and filter cubes
            cubes.forEach((cube, index) => {
                const cubeText = cube.textContent.toLowerCase();
                const hasMatch = searchTerm === '' || cubeText.includes(searchTerm);
                
                cube.style.display = hasMatch ? 'block' : 'none';
                cube.style.order = index;
                
                // Highlight matching text
                if (searchTerm && hasMatch) {
                    highlightMatches(cube, searchTerm);
                } else {
                    removeHighlights(cube);
                }
            });
            
            // Force grid layout update
            container.style.display = 'none';
            container.offsetHeight; // Trigger reflow
            container.style.display = 'grid';
        });
    }
    
    // Auto-refresh functionality (every 5 minutes)
    setInterval(function() {
        if (!document.hidden) { // Only refresh if the page is visible
            location.reload();
        }
    }, 5 * 60 * 1000);

    // Function to calculate relevance score
    function getRelevanceScore(text, searchTerm) {
        // Count occurrences
        const occurrences = (text.match(new RegExp(searchTerm, 'gi')) || []).length;
        
        // Check if it's in important elements
        const hasInTitle = text.includes(searchTerm);
        const hasInDescription = text.includes(searchTerm);
        
        // Calculate score (higher is more relevant)
        return occurrences + (hasInTitle ? 5 : 0) + (hasInDescription ? 3 : 0);
    }
    
    // Function to highlight matching text
    function highlightMatches(element, searchTerm) {
        removeHighlights(element);
        
        const textNodes = getTextNodes(element);
        
        textNodes.forEach(node => {
            const text = node.nodeValue;
            if (text.toLowerCase().includes(searchTerm.toLowerCase())) {
                const regex = new RegExp(`(${escapeRegExp(searchTerm)})`, 'gi');
                const newHtml = text.replace(regex, '<span class="highlight">$1</span>');
                
                const replacementNode = document.createElement('span');
                replacementNode.innerHTML = newHtml;
                node.parentNode.replaceChild(replacementNode, node);
            }
        });
    }
    
    // Function to remove highlights
    function removeHighlights(element) {
        const highlights = element.querySelectorAll('.highlight');
        highlights.forEach(highlight => {
            const textNode = document.createTextNode(highlight.textContent);
            highlight.parentNode.replaceChild(textNode, highlight);
        });
    }
    
    // Helper to get all text nodes
    function getTextNodes(element) {
        const textNodes = [];
        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT,
            { acceptNode: node => node.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT }
        );
        
        let node;
        while (node = walker.nextNode()) {
            textNodes.push(node);
        }
        
        return textNodes;
    }
    
    // Helper to escape special regex characters
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
});
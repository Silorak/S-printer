import { useState, useEffect } from 'react';
import './styles.css';
import { mockPrinters } from './mock-printers';
import PrinterList from './components/PrinterList';
import ThemeToggle from './components/ThemeToggle';

function App() {
  const [printers, setPrinters] = useState({});
  const [theme, setTheme] = useState(localStorage.getItem('theme') || 'dark');
  const [searchTerm, setSearchTerm] = useState('');

  // Effect to load printer data (currently from mock)
  useEffect(() => {
    // TODO: Replace with fetch('/api.php')
    setPrinters(mockPrinters);
  }, []);

  // Effect to handle theme changes
  useEffect(() => {
    document.body.classList.toggle('light-theme', theme === 'light');
    localStorage.setItem('theme', theme);
  }, [theme]);

  const handleThemeToggle = () => {
    setTheme(prevTheme => prevTheme === 'dark' ? 'light' : 'dark');
  };

  return (
    <>
      <header className="header">
        <div className="logo-wrapper">
          <div className="ink-logo">
            <div className="ink-bubble"></div>
            <div className="ink-bubble"></div>
            <div className="ink-bubble"></div>
            <div className="ink-bubble"></div>
            <div className="ink-wave"></div>
          </div>
          <div className="brand-text">
            <div className="site-name">S~printer</div>
            <div className="site-subtitle">Printer Status Monitor</div>
          </div>
        </div>
        <div className="search-container">
          <input
            type="text"
            id="printerSearch"
            placeholder="⌛ Write here to search..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="theme-toggle-container">
          <ThemeToggle onToggle={handleThemeToggle} />
        </div>
      </header>

      <h1>🖨️ Printer Status Monitor</h1>

      <div className="container">
        <PrinterList printers={printers} searchTerm={searchTerm} />
      </div>
    </>
  )
}

export default App

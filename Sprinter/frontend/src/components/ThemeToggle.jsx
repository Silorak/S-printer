import React from 'react';

const ThemeToggle = ({ onToggle }) => {
  return (
    <button id="themeToggle" className="theme-toggle" aria-label="Toggle dark/light theme" onClick={onToggle}>
      <div className="theme-toggle-knob"></div>
      <svg className="theme-toggle-sun" viewBox="0 0 24 24" width="18" height="18">
        <circle cx="12" cy="12" r="4" fill="currentColor"/>
        <path d="M12 2v2M12 20v2M4 12H2M22 12h-2M6 6l-1.4-1.4M19.4 19.4l-1.4-1.4M6 18l-1.4 1.4M19.4 4.6l-1.4 1.4" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/>
      </svg>
      <svg className="theme-toggle-moon" viewBox="0 0 24 24" width="18" height="18">
        <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.5 5.5 0 0 1-4.96-8.65C14.99 2.36 13.52 2 12 2v1z" fill="currentColor" stroke="currentColor" strokeWidth="2" strokeLinejoin="round"/>
      </svg>
    </button>
  );
};

export default ThemeToggle;

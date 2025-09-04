import React from 'react';

const LevelBar = ({ color, level }) => {
  const { percentage } = level;

  return (
    <div className="level-bar">
      <div className="level-label">
        {/* Capitalize the first letter of the color */}
        <span>{color.charAt(0).toUpperCase() + color.slice(1)}</span>
        <span>{percentage}%</span>
      </div>
      <div className="level-container">
        <div
          className={`level-fill ${color}-fill`}
          style={{ width: `${percentage}%` }}
          data-level={percentage}
          aria-label={`${level.current}/${level.max}`}
        ></div>
      </div>
    </div>
  );
};

export default LevelBar;

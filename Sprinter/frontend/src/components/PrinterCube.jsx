import React from 'react';
import LevelBar from './LevelBar';

const PrinterCube = ({ ip, printer }) => {
    const { info, levels } = printer;
    const isOffline = info.status === 'offline';
    const hasLevels = levels && typeof levels === 'object' && Object.keys(levels).length > 0;

    return (
        <div className={`cube ${isOffline ? 'offline-cube' : ''}`}>
            <div className={`status-indicator ${info.status}`}></div>

            <div className="info-item">
                <strong>Full Name:</strong> {info.full_name || info.model || info.sys_name || 'N/A'}
            </div>

            <div className="info-item">
                <strong>Serial:</strong> {info.serial_number || 'N/A'}
            </div>

            {isOffline ? (
                <div className="offline-message">
                    Printer is currently offline
                </div>
            ) : (
                hasLevels ? (
                    Object.entries(levels).map(([color, level]) => (
                        <LevelBar key={color} color={color} level={level} />
                    ))
                ) : (
                    <div className="no-data-message">
                        No ink/toner level data available
                    </div>
                )
            )}

            {info.description && (
                <div className="description-embed">
                    {info.description}
                </div>
            )}

            <div className="cube-footer">
                {info.purchase_url ? (
                    <a href={info.purchase_url} className="buy-button" target="_blank" rel="noopener noreferrer">
                        Order Supplies
                    </a>
                ) : (
                    <span className="placeholder-button"></span>
                )}
                <div className="last-updated">
                    IP: {ip}
                </div>
            </div>
        </div>
    );
};

export default PrinterCube;

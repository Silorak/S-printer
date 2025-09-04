import React from 'react';
import PrinterCube from './PrinterCube';

const PrinterList = ({ printers, searchTerm }) => {
  const printerEntries = Object.entries(printers);

  if (printerEntries.length === 0) {
    return (
      <div className="cube">
        <div className="info-item">
          <strong>No printers found</strong>
          <p>Unable to connect to any printers. Please check your network connection and SNMP settings.</p>
        </div>
      </div>
    );
  }

  const filteredPrinters = printerEntries.filter(([ip, data]) => {
    if (!searchTerm) return true;
    const search = searchTerm.toLowerCase();
    // A simple search that checks against a stringified version of the printer data
    const printerString = JSON.stringify(data).toLowerCase();
    return printerString.includes(search);
  });

  if (filteredPrinters.length === 0) {
    return (
        <div className="cube no-results" style={{ display: 'block', gridColumn: '1 / -1', justifySelf: 'center', maxWidth: '400px', margin: '0 auto' }}>
            <div className="info-item">
                <strong>No printers found</strong>
                <p>No printers match your search criteria.</p>
                <p>Try using different keywords or clear the search.</p>
            </div>
        </div>
    );
  }

  return (
    <>
      {filteredPrinters.map(([ip, data]) => (
        <PrinterCube key={ip} ip={ip} printer={data} />
      ))}
    </>
  );
};

export default PrinterList;

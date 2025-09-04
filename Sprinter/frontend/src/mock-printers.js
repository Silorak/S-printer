// This file provides a mock data structure for the printer list.
// It's used for UI development in the absence of a live backend API.
// The structure mimics the JSON output of the `api.php` script.

export const mockPrinters = {
  "192.168.40.118": {
    "info": {
      "ip": "192.168.40.118",
      "last_updated": 1678886400,
      "sys_name": "HP_LaserJet_M428fdn",
      "sys_description": "HP LaserJet Pro MFP M428fdn",
      "detected_vendor": "HP",
      "full_name": "HP LaserJet Pro MFP M428fdn",
      "serial_number": "CNDRQ2K7HB",
      "model": "HP LaserJet Pro MFP M428fdn",
      "description": "1 aukštas | Mokytojų kambario spausdintuvas",
      "purchase_url": "https://www.ekoprint.lt/paieska?q=CF259",
      "status": "online"
    },
    "levels": {
      "black": {
        "percentage": 75.5,
        "current": 1510,
        "max": 2000,
        "color": "black",
        "description": "Black Toner Cartridge"
      }
    }
  },
  "172.17.163.235": {
    "info": {
      "ip": "172.17.163.235",
      "last_updated": 1678886400,
      "sys_name": "CANON_iR_C3525",
      "sys_description": "Canon imageRUNNER ADVANCE C3525",
      "detected_vendor": "CANON",
      "full_name": "Canon imageRUNNER ADVANCE C3525",
      "serial_number": "CAN7364821",
      "model": "Canon iR-ADV C3525",
      "description": "1 aukštas | Raštinės spausdintuvas",
      "purchase_url": "https://example.com/toner-canon-1",
      "status": "online"
    },
    "levels": {
      "black": {
        "percentage": 92.0,
        "current": 920,
        "max": 1000,
        "color": "black",
        "description": "Black Toner"
      },
      "cyan": {
        "percentage": 45.3,
        "current": 453,
        "max": 1000,
        "color": "cyan",
        "description": "Cyan Toner"
      },
      "magenta": {
        "percentage": 68.1,
        "current": 681,
        "max": 1000,
        "color": "magenta",
        "description": "Magenta Toner"
      },
      "yellow": {
        "percentage": 15.9,
        "current": 159,
        "max": 1000,
        "color": "yellow",
        "description": "Yellow Toner"
      }
    }
  },
  "10.82.165.8": {
    "info": {
        "ip": "10.82.165.8",
        "last_updated": 1678886400,
        "description": "2 aukštas | 5 Kabinetas",
        "purchase_url": "https://example.com/toner-hp-2",
        "full_name": "HP LaserJet Pro (No Level Data)",
        "serial_number": "HP82934982",
        "status": "online"
    },
    "levels": {}
  },
  "10.82.165.14": {
    "info": {
      "ip": "10.82.165.14",
      "last_updated": 1678886400,
      "description": "1 aukštas | Darželio raštinės spausdintuvas",
      "purchase_url": "https://example.com/toner-hp-2",
      "full_name": "HP Office Printer (Offline)",
      "serial_number": "HP72638492",
      "status": "offline"
    },
    "levels": []
  }
};

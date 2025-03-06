<?php
/**
 * List of known printers with their information
 * 
 * This allows you to specify custom information for printers
 * and also ensures these IPs are checked before any network scanning
 */
return [
    '192.168.40.118' => [
        'description' => '1 aukštas | Mokytojų kambario spausdintuvas',
        'purchase_url' => 'https://www.ekoprint.lt/paieska?q=CF259',
        'name' => 'HP LaserJet Pro MFP M428fdn',  // Optional: name to display when offline
        'serial' => 'CNDRQ2K7HB'  // Optional: serial to display when offline
    ],
    '172.17.163.235' => [
        'description' => '1 aukštas | Raštinės spausdintuvas',
        'purchase_url' => 'https://example.com/toner-canon-1',
        'name' => 'Canon Printer',
        'serial' => 'CAN7364821'
    ],
    '10.82.165.8' => [
        'description' => '2 aukštas | 5 Kabinetas',
        'purchase_url' => 'https://example.com/toner-hp-2',
        'name' => 'HP LaserJet Pro',
        'serial' => 'HP82934982'
    ],
    '10.82.165.14' => [
        'description' => '1 aukštas | Darželio raštinės spausdintuvas',
        'purchase_url' => 'https://example.com/toner-hp-2',
        'name' => 'HP Office Printer',
        'serial' => 'HP72638492'
    ],
    '10.82.165.15' => [
        'description' => '1 aukštas | Rašinės HP spausdintuvas',
        'purchase_url' => 'https://www.ekoprint.lt/paieska?q=CF226',
        'name' => 'HP Office Printer',
        'serial' => 'HP83726340'
    ],
    '10.82.165.16' => [
        'description' => '1 aukštas | Būdinčio spausdintuvas',
        'purchase_url' => 'https://www.ekoprint.lt/paieska?q=No.415x',
        'name' => 'HP LaserJet',
        'serial' => 'HP92837465'
    ],
    '172.17.160.115' => [
        'description' => '1 aukštas | Darželis',
        'purchase_url' => 'https://example.com/test',
        'name' => 'Canon Office Printer',
        'serial' => 'CAN8372643'
    ]
];

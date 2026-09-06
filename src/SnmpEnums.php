<?php
declare(strict_types=1);

/**
 * SNMP configuration enums (PHP 8.1 backed enums).
 * Single source of truth for every place that validates or stores SNMP
 * access settings - Database::sanitizeSnmpAuth(), the UI selects and
 * PrinterDiscovery all read from here.
 */
enum SnmpVersion: string {
    case V1  = '1';
    case V2C = '2c';
    case V3  = '3';
}

enum SnmpSecLevel: string {
    case AUTH_PRIV      = 'authPriv';
    case AUTH_NO_PRIV   = 'authNoPriv';
    case NO_AUTH_NO_PRIV = 'noAuthNoPriv';
}

enum SnmpAuthProto: string {
    case SHA = 'SHA';
    case MD5 = 'MD5';
}

enum SnmpPrivProto: string {
    case AES = 'AES';
    case DES = 'DES';
}

<?php
declare(strict_types=1);

/**
 * Shared display-format helpers for PHP entry points.
 *
 * Keep presentation-only normalization here so index.php and api.php do not
 * duplicate serial cleanup or JSON escaping rules.
 */

function cleanSerialNumberForDisplay(?string $serial): string {
    if (!$serial) {
        return 'N/A';
    }

    if (preg_match('/^Hex-STRING:\s*/i', $serial)) {
        $hexPart = trim(preg_replace('/^Hex-STRING:\s*/i', '', $serial));
        $hexBytes = str_replace(' ', '', $hexPart);

        $ascii = '';
        $isPrintable = true;
        for ($i = 0; $i < strlen($hexBytes); $i += 2) {
            $byte = hexdec(substr($hexBytes, $i, 2));
            if ($byte < 32 || $byte > 126) {
                $isPrintable = false;
            }
            $ascii .= chr($byte);
        }

        if ($isPrintable && strlen($hexBytes) > 0) {
            return trim($ascii);
        }

        if (strlen($hexBytes) <= 8) {
            return (string)hexdec($hexBytes);
        }

        return $hexBytes;
    }

    if (preg_match('/^0x([0-9a-fA-F]+)$/i', $serial, $matches)) {
        $hexBytes = $matches[1];
        $ascii = '';
        $isPrintable = true;
        for ($i = 0; $i < strlen($hexBytes); $i += 2) {
            $byte = hexdec(substr($hexBytes, $i, 2));
            if ($byte < 32 || $byte > 126) {
                $isPrintable = false;
            }
            $ascii .= chr($byte);
        }
        if ($isPrintable && strlen($hexBytes) > 0) {
            return trim($ascii);
        }
        return (string)hexdec($hexBytes);
    }

    return $serial;
}

function jsonForHtmlAttribute(mixed $value): string {
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) {
        $json = 'null';
    }
    return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
}

function jsonForInlineScript(mixed $value): string {
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json === false ? 'null' : $json;
}

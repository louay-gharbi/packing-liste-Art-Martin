<?php
// src/Services/QrCodeService.php

namespace App\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * QR codes générés à la volée, jamais stockés (ni fichier, ni chemin en base).
 * Format SVG : ne dépend pas de l'extension GD.
 */
class QrCodeService
{
    private const MARGIN = 10;

    /**
     * Document SVG du QR code, avec le texte $labelText écrit en dessous si fourni
     * (le SvgWriter d'Endroid ignore les labels : le texte est ajouté ici).
     */
    public static function svg(string $data, int $size = 300, ?string $labelText = null): string
    {
        $qr = (new SvgWriter())->write(new QrCode(
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: self::MARGIN,
        ))->getString();
        if ($labelText === null || $labelText === '') {
            return $qr;
        }

        $qr = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $qr);
        $width = $size + 2 * self::MARGIN;
        $fontSize = (int)round($size * 0.09);
        $height = $width + $fontSize + self::MARGIN;
        $textWidth = $width - 2 * self::MARGIN;
        $label = htmlspecialchars($labelText, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . $qr
            . '<text x="' . ($width / 2) . '" y="' . ($width + $fontSize * 0.55) . '" text-anchor="middle" font-family="Open Sans, Arial, Helvetica, sans-serif"'
            . ' font-size="' . $fontSize . '" fill="#000" textLength="' . $textWidth . '" lengthAdjust="spacingAndGlyphs">' . $label . '</text>'
            . '</svg>';
    }

    /** QR code en data URI (pour <img src> dans une page ou un PDF). */
    public static function svgDataUri(string $data, int $size = 300, ?string $labelText = null): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $size, $labelText));
    }
}

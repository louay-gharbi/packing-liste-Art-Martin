<?php
// C:\xampp\htdocs\packinglist\generate_qr.php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Label\Font\Font;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\Label\Margin\Margin;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Writer\PngWriter;

// 1. Création de l’objet QrCode avec toutes les options du constructeur
$qrCode = new QrCode(
    data:                   'Hello world',                       // Contenu encodé
    encoding:               new Encoding('UTF-8'),               // Jeu de caractères
    errorCorrectionLevel: ErrorCorrectionLevel::High,       // Correction d’erreur H
    size:                   300,                                 // Taille en pixels
    margin:                 10,                                  // Marge autour du code
    roundBlockSizeMode: RoundBlockSizeMode::Margin,      // Mode d’arrondi des blocs
    foregroundColor:        new Color(0, 0, 0),                  // Couleur des modules (noir)
    backgroundColor:        new Color(255, 255, 255)             // Couleur de fond (blanc)
);

// 2. (Optionnel) Logo centré au milieu du QR
$logo = new Logo(
    path:                  __DIR__ . '/logo.png',               // Chemin vers votre logo
    resizeToWidth:         50,                                   // Largeur du logo
    resizeToHeight:        50,                                   // Hauteur du logo
    punchoutBackground:    true                                 // Découpe le fond derrière le logo
);

// 3. (Optionnel) Label sous le QR code
$label = new Label(
    text:                  'HELLO WORLD',                       // Texte du label
    font:                  new Font(__DIR__ . '/vendor/endroid/qr-code/assets/open_sans.ttf', 12),
    alignment:             LabelAlignment::Center,              // Alignement centré
    margin:                new Margin(0, 0, 10, 0),             // Marge sous le code
    textColor:             new Color(0, 0, 0)                   // Couleur du texte du label
);

// 4. Génération de l’image PNG via le writer
$writer = new PngWriter();
$result = $writer->write($qrCode, $logo, $label);

// 5. Envoi direct de l’image PNG au navigateur
header('Content-Type: ' . $result->getMimeType());  // ex. « image/png »
echo $result->getString();
exit;

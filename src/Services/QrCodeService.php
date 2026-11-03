<?php
// src/Services/QrCodeService.php

namespace App\Services;

// Use statements basés sur l'exemple generate_qr.php et les besoins de la classe
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Label\Font\Font; // Utiliser Font pour spécifier le chemin
use Endroid\QrCode\Label\LabelAlignment; // Utiliser l'enum/classe pour l'alignement
use Endroid\QrCode\Label\Margin\Margin;
use Endroid\QrCode\Writer\PngWriter;
use Throwable; // Pour attraper toutes les erreurs et exceptions

class QrCodeService
{
    private string $qrCodeStoragePath;
    private string $qrCodePublicPath;
    private bool $gdLoaded;
    private string $defaultFontPath;

    public function __construct()
    {
        $this->gdLoaded = extension_loaded('gd') && function_exists('gd_info');

        if (!defined('PUBLIC_PATH') || !defined('BASE_URL') || !defined('ROOT_PATH')) {
            $errorMsg = "QrCodeService Construct ERREUR CRITIQUE: Constantes PUBLIC_PATH, BASE_URL ou ROOT_PATH non définies.";
            error_log($errorMsg);
            throw new \RuntimeException($errorMsg);
        }

        $this->qrCodeStoragePath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, PUBLIC_PATH), DIRECTORY_SEPARATOR)
                                 . DIRECTORY_SEPARATOR . 'qrcodes'
                                 . DIRECTORY_SEPARATOR . 'palettes';

        // Chemin public relatif (par rapport à BASE_URL) vers le dossier des QR codes
        $this->qrCodePublicPath = '/qrcodes/palettes';
        
        // Chemin vers la police (similaire à votre exemple)
        // Assurez-vous que ce chemin est correct et que la police open_sans.ttf (ou noto_sans.ttf) est bien dans vendor/endroid/qr-code/assets/
        // Remplacez 'open_sans.ttf' par 'noto_sans.ttf' si c'est ce que vous avez.
        $this->defaultFontPath = ROOT_PATH . DIRECTORY_SEPARATOR . 'vendor' 
                               . DIRECTORY_SEPARATOR . 'endroid' 
                               . DIRECTORY_SEPARATOR . 'qr-code' 
                               . DIRECTORY_SEPARATOR . 'assets' 
                               . DIRECTORY_SEPARATOR . 'open_sans.ttf'; 

        error_log("QrCodeService Construct - Storage Path: " . $this->qrCodeStoragePath);
        error_log("QrCodeService Construct - Public Path: " . $this->qrCodePublicPath);
        error_log("QrCodeService Construct - GD Loaded: " . ($this->gdLoaded ? 'Oui' : 'Non'));
        error_log("QrCodeService Construct - Default Font Path Check: " . $this->defaultFontPath . (file_exists($this->defaultFontPath) ? " (Fichier Trouvé)" : " (FICHIER NON TROUVÉ!)"));

        if (!is_dir($this->qrCodeStoragePath)) {
            error_log("QrCodeService Construct: Dossier stockage non trouvé, tentative création: " . $this->qrCodeStoragePath);
            if (!mkdir($this->qrCodeStoragePath, 0775, true)) {
                $lastError = error_get_last();
                $errorMsg = "QrCodeService Construct ERREUR CRITIQUE: mkdir échec pour " . $this->qrCodeStoragePath . ". Erreur PHP: " . ($lastError['message'] ?? 'inconnue') . ". Vérifiez permissions.";
                error_log($errorMsg); 
                throw new \RuntimeException($errorMsg);
            } else { 
                error_log("QrCodeService Construct: Dossier stockage créé: " . $this->qrCodeStoragePath); 
            }
        } elseif (!is_writable($this->qrCodeStoragePath)) {
            $errorMsg = "QrCodeService Construct ERREUR CRITIQUE: Dossier stockage non inscriptible: " . $this->qrCodeStoragePath;
            error_log($errorMsg); 
            throw new \RuntimeException($errorMsg);
        } else { 
            error_log("QrCodeService Construct: Dossier stockage trouvé et inscriptible."); 
        }
    }

    public function generateAndSavePaletteQrCode(string $dataToEncode, string $filename, ?string $labelText = null): string|false
    {
        error_log("QrCodeService generateAndSave - Début. Data: '{$dataToEncode}', Fichier: '{$filename}', Label: '{$labelText}'");

        if (!$this->gdLoaded) {
            error_log("QrCodeService generateAndSave - ERREUR: Extension GD non chargée.");
            return false;
        }

        $safeFilename = basename(preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename));
        if (empty($safeFilename)) {
            error_log("QrCodeService generateAndSave - ERREUR: Nom de fichier invalide après nettoyage: '{$filename}' -> '{$safeFilename}'");
            return false;
        }

        $filePath = $this->qrCodeStoragePath . DIRECTORY_SEPARATOR . $safeFilename . '.png';
        error_log("QrCodeService generateAndSave - Fichier cible final: {$filePath}");

        try {
            // 1. Création de l’objet QrCode (conformément à votre exemple)
            $qrCode = new QrCode(
                data:                   $dataToEncode,
                encoding:               new Encoding('UTF-8'),
                errorCorrectionLevel:   ErrorCorrectionLevel::Low, // Low est suffisant pour les codes internes
                size:                   300,
                margin:                 10,
                roundBlockSizeMode:     RoundBlockSizeMode::Margin,
                foregroundColor:        new Color(0, 0, 0),
                backgroundColor:        new Color(255, 255, 255)
            );
            error_log("QrCodeService generateAndSave - Objet QrCode créé.");

            // 2. Création de l’objet Label (si $labelText est fourni)
            $labelObject = null;
            if ($labelText) {
                if (!file_exists($this->defaultFontPath)) {
                    error_log("QrCodeService generateAndSave - ERREUR CRITIQUE: Police par défaut non trouvée à {$this->defaultFontPath}. Le label ne sera pas créé.");
                    // Dans ce cas, on ne crée pas de label pour éviter une exception de la librairie.
                    // Le QR code sera généré sans label.
                } else {
                    $labelObject = new Label(
                        text:                  $labelText,
                        font:                  new Font($this->defaultFontPath, 20), // Taille 20 pour le label
                        alignment:             LabelAlignment::Center, // Utiliser l'enum/classe directement
                        margin:                new Margin(0, 10, 10, 10), // haut, droite, bas, gauche
                        textColor:             new Color(0, 0, 0)
                    );
                    error_log("QrCodeService generateAndSave - Objet Label créé avec texte: {$labelText}");
                }
            } else {
                 error_log("QrCodeService generateAndSave - Aucun labelText fourni, pas de création d'objet Label.");
            }

            // 3. Instanciation du Writer
            $writer = new PngWriter();
            error_log("QrCodeService generateAndSave - PngWriter instancié.");
            
            // 4. Utiliser le Writer pour générer le résultat du QR code
            // Le deuxième argument de write() est pour le logo (non utilisé ici, donc null)
            $result = $writer->write($qrCode, null, $labelObject);
            error_log("QrCodeService generateAndSave - Résultat du writer obtenu. Tentative de sauvegarde...");
            
            // 5. Sauvegarder le résultat dans un fichier
            $result->saveToFile($filePath);
            error_log("QrCodeService generateAndSave - saveToFile appelé pour: {$filePath}");

            if (!file_exists($filePath) || filesize($filePath) === 0) {
                 error_log("QrCodeService generateAndSave - ERREUR: Fichier QR non créé ou vide après saveToFile(): " . $filePath);
                 return false;
            }

            error_log("QrCodeService generateAndSave - Fichier QR sauvegardé avec succès: " . $filePath);
            // Retourner le chemin relatif pour stockage en BDD
            return $this->qrCodePublicPath . '/' . $safeFilename . '.png';

        } catch (Throwable $e) {
            error_log("QrCodeService generateAndSave - EXCEPTION: " . $e->getMessage() . " - Fichier: " . $e->getFile() . " Ligne: " . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
            return false;
        }
    }

    public function getPaletteQrCodePublicPath(string $filename): ?string
    {
        $safeFilename = basename(preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename));
        $storageFilePath = $this->qrCodeStoragePath . DIRECTORY_SEPARATOR . $safeFilename . '.png';
        if (file_exists($storageFilePath)) {
            // Retourne le chemin relatif si le fichier existe
            return $this->qrCodePublicPath . '/' . $safeFilename . '.png';
        }
        error_log("QrCodeService getPaletteQrCodePublicPath - Fichier non trouvé: " . $storageFilePath);
        return null;
    }
}

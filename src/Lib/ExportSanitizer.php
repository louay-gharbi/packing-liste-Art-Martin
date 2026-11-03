<?php
// src/Lib/ExportSanitizer.php

namespace App\Lib;

/**
 * Neutralise les injections de formules (CSV/Excel injection) dans les exports.
 *
 * Une valeur commençant par =, +, -, @, TAB ou CR est interprétée comme une formule
 * par Excel/LibreOffice lors de l'ouverture du fichier. Le préfixage par une apostrophe
 * force l'interprétation littérale.
 *
 * À appliquer aux valeurs d'origine non maîtrisée (payload QR, CSV importé, commentaires,
 * libellés), PAS aux valeurs numériques calculées par l'application (quantités, totaux).
 */
class ExportSanitizer
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function cell(?string $value): string
    {
        $v = (string) ($value ?? '');
        if ($v === '') {
            return $v;
        }
        if (in_array($v[0], self::DANGEROUS_PREFIXES, true)) {
            return "'" . $v;
        }
        return $v;
    }
}

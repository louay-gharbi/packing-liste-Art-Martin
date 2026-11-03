<?php
// src/Services/ReferenceGenerator.php

namespace App\Services;

use App\Models\DynamicCounter;
use App\Models\Setting;
use DateTimeImmutable; // Utiliser DateTimeImmutable
use DateTimeInterface; // Pour le type hinting
use App\Models\Site;

class ReferenceGenerator
{
    /**
     * Génère une référence basée sur un patron, un ID et des compteurs.
     *
     * @param string $patternKey La clé du paramètre du patron (ex: 'packing_list_reference_pattern')
     * @param int $entityId L'ID de l'entité (ex: ID de la Packing List). Cet ID est utilisé pour {ID_PL} ou équivalent.
     * @param array $additionalPlaceholders Tableau de placeholders supplémentaires et leurs valeurs.
     * @param DateTimeInterface|null $dateContext La date à utiliser pour les placeholders de date (null pour maintenant).
     * @return string La référence générée.
     * @throws \Exception Si le patron n'est pas trouvé ou si une erreur de compteur se produit.
     */
     public static function generate(string $patternKey, int $entityId, ?int $siteId = null, array $additionalPlaceholders = [], ?DateTimeInterface $dateContext = null): string
    {
        $pattern = Setting::get($patternKey);
        if (empty($pattern)) {
            throw new \Exception("Patron de référence non trouvé ou vide pour la clé: {$patternKey}. Configurez-le dans les paramètres.");
        }

        $reference = $pattern;
        $now = ($dateContext instanceof DateTimeImmutable) ? $dateContext : new DateTimeImmutable($dateContext ? $dateContext->format('Y-m-d H:i:s') : 'now');

        // Récupérer le code du site si siteId est fourni et si le placeholder {SITE_CODE} est utilisé
        $siteCode = '';
        if ($siteId !== null && strpos($reference, '{SITE_CODE}') !== false) {
            $site = Site::findById($siteId);
            if ($site) {
                $siteCode = $site->code;
            } else {
                error_log("ReferenceGenerator: Site ID {$siteId} non trouvé pour le placeholder {SITE_CODE} dans le patron {$patternKey}.");
                // Décider quoi faire: erreur ou code vide ? Pour l'instant, code vide.
            }
        }
        
        // 1. Remplacer les placeholders spécifiques (ID, SITE_CODE)
        if (strpos($reference, '{ID_PL}') !== false && str_contains($patternKey, 'packing_list')) $reference = str_replace('{ID_PL}', (string)$entityId, $reference);
        if (strpos($reference, '{ID_PALETTE}') !== false && str_contains($patternKey, 'palette')) $reference = str_replace('{ID_PALETTE}', (string)$entityId, $reference);
        if (strpos($reference, '{ID}') !== false) $reference = str_replace('{ID}', (string)$entityId, $reference);
        if (strpos($reference, '{SITE_CODE}') !== false) $reference = str_replace('{SITE_CODE}', $siteCode, $reference);


        // 2. Gérer les compteurs dynamiques {COUNTER_SCOPE_SITE?_XDIGITS}
        // Exemple : {COUNTER_YEARLY_SITE_4DIGITS} ou {COUNTER_YEARLY_4DIGITS}
        if (preg_match_all('/\{COUNTER_([A-Z]+)(_SITE)?_(\d+)DIGITS\}/', $reference, $counterMatches, PREG_SET_ORDER)) {
            foreach ($counterMatches as $match) {
                $fullPlaceholder = $match[0];
                $counterScope = strtolower($match[1]);
                $isSiteSpecific = ($match[2] === '_SITE');
                $numDigits = (int)$match[3];

                $entityTypePrefix = '';
                if (str_contains($patternKey, 'packing_list')) $entityTypePrefix = 'pl_';
                elseif (str_contains($patternKey, 'palette')) $entityTypePrefix = 'pal_';

                $dynamicCounterKey = 'counter_' . $entityTypePrefix;

                if ($isSiteSpecific) {
                    if ($siteId === null) {
                        error_log("ReferenceGenerator: Placeholder de compteur par site utilisé ({$fullPlaceholder}) mais aucun siteId n'a été fourni pour le patron {$patternKey}.");
                        // Remplacer par des X ou lever une erreur. Pour l'instant, des X.
                        $reference = str_replace($fullPlaceholder, str_repeat('X', $numDigits), $reference);
                        continue;
                    }
                    $dynamicCounterKey .= "site{$siteId}_";
                }

                switch ($counterScope) {
                    case 'yearly': $dynamicCounterKey .= 'yearly_' . $now->format('Y'); break;
                    case 'monthly': $dynamicCounterKey .= 'monthly_' . $now->format('Ym'); break;
                    case 'weekly': $dynamicCounterKey .= 'weekly_' . $now->format('YW'); break;
                    case 'daily': $dynamicCounterKey .= 'daily_' . $now->format('Ymd'); break;
                    case 'global': default: $dynamicCounterKey .= 'global'; break;
                }
                
                $counterValue = DynamicCounter::getNextValue($dynamicCounterKey);
                $formattedCounter = str_pad((string)$counterValue, $numDigits, '0', STR_PAD_LEFT);
                $reference = str_replace($fullPlaceholder, $formattedCounter, $reference);
            }
        }
        
        // 3. Remplacer les placeholders de date simples
        $datePlaceholders = [
            '{YYYY}' => $now->format('Y'), // Année sur 4 chiffres
            '{YY}'   => $now->format('y'), // Année sur 2 chiffres
            '{MM}'   => $now->format('m'), // Mois sur 2 chiffres (01-12)
            '{M}'    => $now->format('n'), // Mois sans zéro initial (1-12)
            '{DD}'   => $now->format('d'), // Jour du mois sur 2 chiffres (01-31)
            '{D}'    => $now->format('j'), // Jour du mois sans zéro initial (1-31)
            '{WW}'   => $now->format('W'), // Semaine ISO-8601 (01-53)
            '{HH}'   => $now->format('H'), // Heure sur 24h (00-23)
            '{H}'    => $now->format('G'), // Heure sur 24h sans zéro initial (0-23)
            '{II}'   => $now->format('i'), // Minutes (00-59)
            '{SS}'   => $now->format('s'), // Secondes (00-59)
        ];
        // Trier par longueur de clé décroissante pour éviter les remplacements partiels (ex: {YY} avant {YYYY})
        uksort($datePlaceholders, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($datePlaceholders as $placeholder => $value) {
            $reference = str_replace($placeholder, $value, $reference);
        }

        // 4. Remplacer les placeholders additionnels
        if (!empty($additionalPlaceholders)) {
            uksort($additionalPlaceholders, fn($a, $b) => strlen($b) - strlen($a)); // Même tri pour sécurité
            foreach ($additionalPlaceholders as $placeholder => $value) {
                $reference = str_replace($placeholder, (string)$value, $reference);
            }
        }
        
        // 5. Remplacer les compteurs simples (non _XDIGITS)
        // Cette partie est conservée pour rétrocompatibilité si d'anciens patrons les utilisent,
        // mais les compteurs _XDIGITS sont plus flexibles.
        $simpleCounterPlaceholdersMap = [
            '{COUNTER_GLOBAL}' => 'global',
            '{COUNTER_YEARLY}' => 'yearly',
            '{COUNTER_MONTHLY}'=> 'monthly',
            '{COUNTER_DAILY}'  => 'daily',
            '{COUNTER_WEEKLY}' => 'weekly',
        ];

        foreach ($simpleCounterPlaceholdersMap as $placeholder => $scope) {
            if (strpos($reference, $placeholder) !== false) {
                $entityTypePrefix = ''; // Identique à ci-dessus
                if (str_contains($patternKey, 'packing_list')) $entityTypePrefix = 'pl_';
                elseif (str_contains($patternKey, 'palette')) $entityTypePrefix = 'pal_';

                $dynamicCounterKey = 'counter_simple_'; // Préfixe pour les distinguer des compteurs XDIGITS
                switch ($scope) {
                    case 'yearly': $dynamicCounterKey .= $entityTypePrefix . 'yearly_' . $now->format('Y'); break;
                    case 'monthly': $dynamicCounterKey .= $entityTypePrefix . 'monthly_' . $now->format('Ym'); break;
                    case 'weekly': $dynamicCounterKey .= $entityTypePrefix . 'weekly_' . $now->format('YW'); break;
                    case 'daily': $dynamicCounterKey .= $entityTypePrefix . 'daily_' . $now->format('Ymd'); break;
                    case 'global':
                    default: $dynamicCounterKey .= str_replace(['_pattern', '_reference', '_code'], '', $patternKey) . '_global_simple'; break;
                }
                try {
                    $counterValue = DynamicCounter::getNextValue($dynamicCounterKey);
                } catch (\RuntimeException $e) {
                     error_log("Erreur lors de la génération de référence pour {$patternKey}: impossible d'obtenir la valeur du compteur simple {$dynamicCounterKey}. Erreur: " . $e->getMessage());
                    throw new \Exception("Erreur de compteur simple pour {$dynamicCounterKey} lors de la génération de la référence.", 0, $e);
                }
                $reference = str_replace($placeholder, (string)$counterValue, $reference);
            }
        }

        return $reference;
    }
}
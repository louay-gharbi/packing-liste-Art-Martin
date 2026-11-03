<?php
// src/Services/SearchService.php

namespace App\Services;

use App\Models\Colis;
use App\Models\Palette;
use App\Models\PackingList;
use App\Models\Site;
use App\Lib\Auth;

class SearchService
{
    /**
     * Exécute une recherche globale et retourne des DTOs formatés pour l'API.
     *
     * @param string $query Le terme de recherche brut fourni par l'utilisateur.
     * @return array Un tableau de résultats formatés (DTOs).
     */
    public function performSearch(string $query): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        // --- Étape 1 : Détection de format et recherche ciblée ---

        // Cas 1 : QR Code Colis (contient des séparateurs spécifiques). Recherche exacte.
        if (str_contains($query, ';') || str_contains($query, '|')) {
            $payload = Colis::parseQrPayload($query);
            $packageNumber = $payload['package_number'] ?? null;
            if ($packageNumber) {
                $colis = Colis::findOneByPackageNumberWithJoins($packageNumber);
                return $this->formatResults($colis ? [$colis] : []);
            }
        }

        // Cas 2 : Préfixe de Palette détecté -> Recherche LIKE par préfixe.
        if (preg_match('/^PAL/i', $query)) {
            $results = Palette::searchByPrefix($query);
            return $this->formatResults($results);
        }

        // Cas 3 : Préfixe de Packing List détecté -> Recherche LIKE par préfixe.
        if (preg_match('/^PL/i', $query)) {
            $results = PackingList::searchByPrefix($query);
            return $this->formatResults($results);
        }

        // --- Étape 2 : Recherche FULLTEXT globale pour tout le reste (mots-clés généraux) ---
        
        $formattedFulltextQuery = $this->prepareFullTextQuery($query);
        
        if (empty($formattedFulltextQuery)) {
            return [];
        }

        $colisList = Colis::search($formattedFulltextQuery);
        $palettes = Palette::search($formattedFulltextQuery);
        $packingLists = PackingList::search($formattedFulltextQuery);

        $results = array_merge($colisList, $packingLists, $palettes);
        
        return $this->formatResults($results);
    }

    /**
     * Formate un terme de recherche pour une requête FULLTEXT en mode booléen.
     *
     * @param string $query La chaîne de recherche brute.
     * @return string La chaîne formatée, prête pour `AGAINST(... IN BOOLEAN MODE)`.
     */
    private function prepareFullTextQuery(string $query): string
    {
        $sanitizedQuery = preg_replace('/[+\-><()~*@"]+/', ' ', $query);
        preg_match_all('/[a-zA-Z0-9\-]+/', $sanitizedQuery, $matches);
        $words = $matches[0] ?? [];
        if (empty($words)) {
            return '';
        }
        $formattedWords = array_map(fn($word) => '+' . trim($word) . '*', $words);
        return implode(' ', $formattedWords);
    }
    
    /**
     * Formate un tableau d'objets modèles en DTOs standardisés pour l'API.
     *
     * @param array $items Un tableau contenant des objets Colis, Palette, et/ou PackingList.
     * @return array Un tableau de DTOs prêts à être encodés en JSON.
     */
    private function formatResults(array $items): array
    {
        $dtos = [];
        foreach ($items as $item) {
            if ($item instanceof Colis) {
                $dtos[] = [
                    'type' => 'colis', 'id' => $item->id, 'title' => $item->package_number,
                    'icon' => 'fa-box',
                    'details' => ['SKU' => $item->sku ?? 'N/A', 'OF' => $item->of_number ?? 'N/A', 'Quantité' => $item->quantity ?? 'N/A',],
                    'context' => [
                        'Palette' => ($item->palette_code && $item->palette_id) ? ['url' => '/palettes/show/'.$item->palette_id, 'text' => $item->palette_code] : null,
                        'Packing List' => ($item->packing_list_reference && $item->packing_list_id) ? ['url' => '/packing-lists/show/'.$item->packing_list_id, 'text' => $item->packing_list_reference] : null,
                    ],
                    'actions' => ['view' => ($item->palette_id) ? ['url' => '/palettes/show/'.$item->palette_id] : null,]
                ];
            } elseif ($item instanceof Palette) {
                $pl = PackingList::findById($item->packing_list_id);
                $dtos[] = [
                    'type' => 'palette', 'id' => $item->id, 'title' => $item->code,
                    'icon' => 'fa-pallet',
                    'details' => ['Statut' => ucfirst($item->status), 'Colis' => $item->getColisCount() . ' / ' . ($item->max_colis ?? 'N/D'),],
                    'context' => ['Packing List' => ($pl) ? ['url' => '/packing-lists/show/'.$item->packing_list_id, 'text' => $pl->reference] : null,],
                    'actions' => ['view' => ['url' => '/palettes/show/'.$item->id],]
                ];
            } elseif ($item instanceof PackingList) {
                $site = $item->site_id ? Site::findById($item->site_id) : null;
                $dtos[] = [
                    'type' => 'packing_list', 'id' => $item->id, 'title' => $item->reference,
                    'icon' => 'fa-list-alt',
                    'details' => ['Statut' => ucfirst($item->status), 'Date exp.' => (new \DateTime($item->ship_date))->format('d/m/Y'),],
                    'context' => ['Site' => ($site) ? ['text' => $site->name] : null,],
                    'actions' => ['view' => ['url' => '/packing-lists/show/'.$item->id],]
                ];
            }
        }
        return $dtos;
    }
}
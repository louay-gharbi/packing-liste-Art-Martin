<?php
namespace App\Services;

use App\Models\Colis;
use App\Lib\Auth;

class ColisSearchService
{
    public function search(array $criteria, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $result = Colis::search($criteria, $perPage, $offset);
        $items = $result['results'];
        $total = $result['total'];
        $dto = [];
        foreach ($items as $colis) {
            $dto[] = [
                'id' => $colis->id,
                'package_number' => $colis->package_number,
                'sku' => $colis->sku,
                'quantity' => $colis->quantity,
                'palette_code' => $colis->palette_code,
                'palette_status' => $colis->palette_status,
                'packing_list_reference' => $colis->packing_list_reference,
                'site_code' => $colis->site_code,
                'scan_date_time' => $colis->scan_date_time,
                'parent_colis_id' => $colis->parent_colis_id,
                'parent_package_number' => $colis->parent_package_number,
                'comment' => $colis->comment,
                'canDelete' => Auth::can('colis_delete'),
                'canMove' => Auth::can('colis_move'),
                'canComment' => Auth::can('colis_update_comment'),
                'canPrint' => Auth::can('colis_print_label'),
            ];
        }
        return ['total' => $total, 'items' => $dto];
    }
}
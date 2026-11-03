<?php
// src/Controllers/ClientPaletteController.php

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\Request;
use App\Lib\Response;
use App\Lib\ExportSanitizer;
use App\Models\ClientPalette;
use App\Models\Colis;
use App\Models\PackingList;
use App\Models\Palette;
use App\Models\Reception;
use DateTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Services\QrCodeService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Palettes client : après décomposition des réceptions clôturées par client (colisage client),
 * les colis d'un client sont scannés sur une ou plusieurs palettes d'expédition vers ce client.
 * Mêmes permissions que la réception : reception_view (consulter/exporter), reception_scan (créer/scanner/retirer),
 * reception_close (clôturer/rouvrir).
 */
class ClientPaletteController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    /**
     * Semaine ISO "2026-W42" → [année, semaine], null si invalide.
     */
    private function parseWeek(mixed $value): ?array
    {
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', (string)$value, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 53) {
            return [(int)$m[1], (int)$m[2]];
        }
        return null;
    }

    private function colisageUrl(int $year, int $week, ?string $client = null): string
    {
        return '/receptions/client-packing?week=' . sprintf('%d-W%02d', $year, $week) . ($client !== null ? '&client=' . urlencode($client) : '');
    }

    private function manageUrl(ClientPalette $p): string
    {
        return '/client-palettes?week=' . $p->weekParam() . '&client=' . urlencode($p->destinationKey());
    }

    /**
     * Destination (?client= : clé "client — code postal" du colisage) → [nom client, code postal], null si inconnue.
     * Cherchée dans les colis reçus de la semaine, sinon dans les palettes client existantes.
     */
    private function resolveDestination(string $dest, int $year, int $week, array $weekData): ?array
    {
        if (!empty($weekData['clients'][$dest])) {
            $first = $weekData['clients'][$dest][0];
            return [$first['client_name'], $first['postal_code']];
        }
        foreach (ClientPalette::getForWeek($year, $week) as $p) {
            if ($p->destinationKey() === $dest) return [$p->client_name, $p->postal_code];
        }
        return null;
    }

    private function loadPalette(int $id): ClientPalette|Response
    {
        $palette = ClientPalette::findById($id);
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Palette client non trouvée.");
            return $this->redirect('/receptions/client-packing');
        }
        return $palette;
    }

    /**
     * Colis reçus pour la destination (client + code postal) et la semaine, avec leur palette client éventuelle,
     * et ceux restant à placer (triés par palette Tunisie pour guider la préparation).
     */
    private function clientProgress(string $dest, int $year, int $week, ?array $weekData = null): array
    {
        $weekData ??= Reception::getClientPackingForWeek($year, $week);
        $lines = $weekData['clients'][$dest] ?? [];
        $placed = ClientPalette::getPlacedMap($year, $week);
        $remaining = array_values(array_filter($lines, fn($l) => !isset($placed[$l['colis_id']])));
        usort($remaining, fn($a, $b) => [$a['palette_code'], $a['package_number']] <=> [$b['palette_code'], $b['package_number']]);
        return [
            'lines' => $lines,
            'remaining' => $remaining,
            'total' => count($lines),
            'placed' => count($lines) - count($remaining),
        ];
    }

    /**
     * Palettes client d'une destination (client + code postal) pour une semaine.
     */
    public function index(Request $request): Response
    {
        $this->authorize('reception_view', '/receptions');
        $week = $this->parseWeek($request->get('week', ''));
        $dest = trim((string)$request->get('client', ''));
        if (!$week || $dest === '') {
            $this->flash(FLASH_ERROR, "Semaine ou client manquant.");
            return $this->redirect('/receptions/client-packing');
        }
        [$year, $wk] = $week;
        $weekData = Reception::getClientPackingForWeek($year, $wk);
        $destination = $this->resolveDestination($dest, $year, $wk, $weekData);
        if (!$destination) {
            $this->flash(FLASH_INFO, "Aucun colis reçu pour « {$dest} » en S" . sprintf('%02d', $wk) . " {$year}.");
            return $this->redirect($this->colisageUrl($year, $wk));
        }
        [$clientName, $postalCode] = $destination;

        return $this->view('client_palettes/index.php', [
            'pageTitle' => sprintf('Palettes client %s — S%02d %d', $dest, $wk, $year),
            'client' => $dest,
            'clientName' => $clientName,
            'postalCode' => $postalCode,
            'palettes' => ClientPalette::getForWeek($year, $wk, $clientName, $postalCode),
            'progress' => $this->clientProgress($dest, $year, $wk, $weekData),
            'year' => $year,
            'week' => $wk,
            'weekParam' => sprintf('%d-W%02d', $year, $wk),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('reception_scan', '/receptions/client-packing');
        $week = $this->parseWeek($request->post('week', ''));
        $dest = trim((string)$request->post('client', ''));
        if (!$week || $dest === '') {
            $this->flash(FLASH_ERROR, "Semaine ou client manquant.");
            return $this->redirect('/receptions/client-packing');
        }
        [$year, $wk] = $week;
        $weekData = Reception::getClientPackingForWeek($year, $wk);
        if (empty($weekData['clients'][$dest])) {
            $this->flash(FLASH_ERROR, "Aucun colis reçu pour « {$dest} » cette semaine : palette non créée.");
            return $this->redirect($this->colisageUrl($year, $wk));
        }
        [$clientName, $postalCode] = $this->resolveDestination($dest, $year, $wk, $weekData);
        $palette = ClientPalette::create($clientName, $postalCode, $year, $wk, Auth::id());
        if (!$palette) {
            $this->flash(FLASH_ERROR, "Impossible de créer la palette client.");
            return $this->redirect('/client-palettes?week=' . sprintf('%d-W%02d', $year, $wk) . '&client=' . urlencode($dest));
        }
        $this->flash(FLASH_SUCCESS, "Palette {$palette->code} créée : scannez ses colis.");
        return $this->redirect('/client-palettes/' . $palette->id);
    }

    /**
     * Écran de scan (palette ouverte) ou de consultation (palette clôturée).
     */
    public function show(Request $request, int $id): Response
    {
        $this->authorize('reception_view', '/receptions');
        $palette = $this->loadPalette($id);
        if ($palette instanceof Response) return $palette;

        return $this->view('client_palettes/show.php', [
            'pageTitle' => 'Palette client ' . $palette->code,
            'palette' => $palette,
            'lines' => ClientPalette::getLines($palette->id),
            'progress' => $this->clientProgress($palette->destinationKey(), $palette->ship_year, $palette->ship_week),
            'canScan' => !$palette->isClosed() && Auth::can('reception_scan'),
        ]);
    }

    /**
     * Scan AJAX d'un colis sur la palette client.
     * 200 posé · 409 déjà sur cette palette · 400 refusé (rien n'est enregistré).
     */
    public function scanAjax(Request $request, int $id): Response
    {
        $this->authorize('reception_scan');
        $palette = ClientPalette::findById($id);
        if (!$palette) {
            return Response::json(['error' => 'Palette client non trouvée.'], 404);
        }
        if ($palette->isClosed()) {
            return Response::json(['error' => 'Palette clôturée : aucun scan possible.'], 400);
        }
        $input = trim((string)$request->post('scan_input', ''));
        if ($input === '') {
            return Response::json(['error' => 'Entrée vide.'], 400);
        }

        $parsed = Colis::parseQrPayload($input);
        $packageNumber = trim((string)($parsed['package_number'] ?? '')) ?: $input;

        $weekData = Reception::getClientPackingForWeek($palette->ship_year, $palette->ship_week);
        $dest = $palette->destinationKey();
        $line = null;
        $otherLine = null;
        $groupageChildren = [];
        foreach ($weekData['clients'] as $key => $lines) {
            foreach ($lines as $l) {
                if (strcasecmp($l['package_number'], $packageNumber) === 0) {
                    if ($key === $dest) { $line = $l; } else { $otherLine = $l; }
                }
                if ($key === $dest && $l['groupage_number'] !== null && strcasecmp($l['groupage_number'], $packageNumber) === 0) {
                    $groupageChildren[] = $l['package_number'];
                }
            }
        }

        $weekLabel = sprintf('S%02d %d', $palette->ship_week, $palette->ship_year);
        if (!$line) {
            $otherCp = $otherLine !== null && $otherLine['postal_code'] !== '' ? $otherLine['postal_code'] : 'sans CP';
            if ($otherLine !== null && $otherLine['client_name'] === $palette->client_name) {
                $error = "Colis \"{$packageNumber}\" : même client mais autre adresse (code postal {$otherCp}). "
                       . "Il va sur une palette « " . Reception::destinationKey($otherLine['client_name'], $otherLine['postal_code']) . " ».";
            } elseif ($otherLine !== null) {
                $error = "Colis \"{$packageNumber}\" destiné au client « {$otherLine['client_name']} » (code postal {$otherCp}) : ne pas le mettre sur cette palette.";
            } elseif ($groupageChildren) {
                $error = "\"{$packageNumber}\" est un groupage : scannez ses colis un par un (" . implode(', ', $groupageChildren) . ").";
            } elseif (PackingList::findByReference($input)) {
                $error = "\"{$input}\" est la référence d'une Packing List : scannez des colis.";
            } elseif (Palette::findByCode($input) || preg_match('/^PA?L-/i', $input)) {
                $error = "\"{$input}\" est une palette Tunisie : scannez les colis un par un.";
            } elseif (preg_match('/^PALC-/i', $input)) {
                $error = "\"{$input}\" est une palette client : scannez des colis.";
            } else {
                $error = "Colis \"{$packageNumber}\" non reçu pour {$dest} en {$weekLabel} (inconnu, réception non clôturée ou colis manquant).";
            }
            return Response::json($this->scanPayload($palette, $error, false), 400);
        }

        $add = ClientPalette::addColis($palette, (int)$line['colis_id'], Auth::id());
        $origin = "palette Tunisie {$line['palette_code']} · {$line['pl_reference']}";
        switch ($add['status']) {
            case 'added':
                return Response::json($this->scanPayload($palette, "Colis \"{$line['package_number']}\" posé ({$origin}).", true), 200);
            case 'duplicate':
                return Response::json($this->scanPayload($palette, "Colis \"{$line['package_number']}\" déjà sur cette palette.", false), 409);
            case 'elsewhere':
                return Response::json($this->scanPayload($palette, "Colis \"{$line['package_number']}\" déjà posé sur la palette client {$add['other_code']}.", false), 400);
            default:
                return Response::json(['error' => "Erreur lors de l'enregistrement du colis."], 500);
        }
    }

    /**
     * Réponse commune des appels AJAX : message + fragments HTML rafraîchis.
     */
    private function scanPayload(ClientPalette $palette, string $message, bool $success): array
    {
        $lines = ClientPalette::getLines($palette->id);
        $progress = $this->clientProgress($palette->destinationKey(), $palette->ship_year, $palette->ship_week);
        $viewData = ['palette' => $palette, 'lines' => $lines, 'progress' => $progress, 'canScan' => true, 'baseUrl' => BASE_URL];
        return [
            'success' => $success,
            'message' => $message,
            'error' => $success ? null : $message,
            'counts' => ['on_palette' => count($lines), 'placed' => $progress['placed'], 'total' => $progress['total']],
            'lines_html' => $this->renderPartial('client_palettes/_lines.php', $viewData),
            'remaining_html' => $this->renderPartial('client_palettes/_remaining.php', $viewData),
        ];
    }

    public function removeLine(Request $request, int $id, int $lineId): Response
    {
        $this->authorize('reception_scan', '/client-palettes/' . $id);
        $palette = $this->loadPalette($id);
        if ($palette instanceof Response) return $palette;
        if ($palette->isClosed()) {
            $this->flash(FLASH_ERROR, "Palette clôturée : rouvrez-la pour retirer un colis.");
        } elseif (ClientPalette::removeLine($id, $lineId)) {
            $this->flash(FLASH_SUCCESS, "Colis retiré de la palette.");
        } else {
            $this->flash(FLASH_ERROR, "Colis introuvable sur cette palette.");
        }
        return $this->redirect('/client-palettes/' . $id);
    }

    public function close(Request $request, int $id): Response
    {
        $this->authorize('reception_close', '/client-palettes/' . $id);
        $palette = $this->loadPalette($id);
        if ($palette instanceof Response) return $palette;
        if ($palette->nbColis === 0) {
            $this->flash(FLASH_ERROR, "Palette vide : scannez au moins un colis avant de la clôturer.");
            return $this->redirect('/client-palettes/' . $id);
        }
        if (ClientPalette::setStatus($id, 'closed', Auth::id())) {
            $this->flash(FLASH_SUCCESS, "Palette {$palette->code} clôturée ({$palette->nbColis} colis) : imprimez son étiquette.");
        } else {
            $this->flash(FLASH_ERROR, "Impossible de clôturer la palette (déjà clôturée ?).");
        }
        return $this->redirect('/client-palettes/' . $id);
    }

    public function reopen(Request $request, int $id): Response
    {
        $this->authorize('reception_close', '/client-palettes/' . $id);
        $palette = $this->loadPalette($id);
        if ($palette instanceof Response) return $palette;
        if (ClientPalette::setStatus($id, 'open', Auth::id())) {
            $this->flash(FLASH_SUCCESS, "Palette {$palette->code} rouverte.");
        } else {
            $this->flash(FLASH_ERROR, "Impossible de rouvrir la palette.");
        }
        return $this->redirect('/client-palettes/' . $id);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->authorize('reception_scan', '/client-palettes/' . $id);
        $palette = $this->loadPalette($id);
        if ($palette instanceof Response) return $palette;
        if (!ClientPalette::deleteIfEmpty($id)) {
            $this->flash(FLASH_ERROR, "Seule une palette ouverte et vide peut être supprimée.");
            return $this->redirect('/client-palettes/' . $id);
        }
        $this->flash(FLASH_SUCCESS, "Palette {$palette->code} supprimée.");
        return $this->redirect($this->manageUrl($palette));
    }

    /**
     * Palette + lignes triées pour les exports ; redirige si la palette est vide ou introuvable.
     */
    private function paletteForExport(int $id): array
    {
        $palette = ClientPalette::findById($id);
        $lines = $palette ? ClientPalette::getLines($palette->id, 'order') : [];
        if (!$palette || !$lines) {
            $this->flash(FLASH_INFO, $palette ? "Palette vide : rien à exporter." : "Palette client non trouvée.");
            $this->redirect($palette ? '/client-palettes/' . $id : '/receptions/client-packing')->send();
            exit;
        }
        return [$palette, $lines];
    }

    public function exportXlsx(Request $request, int $id): void
    {
        $this->authorize('reception_view', '/receptions');
        [$palette, $lines] = $this->paletteForExport($id);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($palette->code, -28));
        $totalQty = array_sum(array_map(fn($l) => $l['quantity'] ?? 0, $lines));
        $rows = [
            ['Liste de colisage — palette client ' . $palette->code],
            ['Client', ExportSanitizer::cell($palette->client_name)],
            ['Code postal de livraison', ExportSanitizer::cell($palette->postal_code)],
            ["Semaine d'expédition Tunisie", sprintf('S%02d %d', $palette->ship_week, $palette->ship_year)],
            ['Statut', $palette->isClosed() ? 'Clôturée le ' . (new DateTime($palette->closed_at))->format('d/m/Y H:i') : 'Ouverte'],
            ['Nombre de colis', count($lines)],
            ['Quantité totale', $totalQty],
            [],
            ['N° commande', 'N° ligne', 'Colis', 'Groupage', 'OF', 'Réf. interne', 'Code article', 'Libellé',
             'Référencement client', 'Quantité', 'Unité', 'Code postal', 'Palette Tunisie', 'Packing List'],
        ];
        foreach ($lines as $l) {
            $rows[] = [ExportSanitizer::cell($l['order_number'] ?? ''), ExportSanitizer::cell($l['line_number'] ?? ''),
                       ExportSanitizer::cell($l['package_number']), ExportSanitizer::cell($l['groupage_number'] ?? ''),
                       ExportSanitizer::cell($l['of_number'] ?? ''), ExportSanitizer::cell($l['internal_reference'] ?? ''),
                       ExportSanitizer::cell($l['article_reference'] ?? ''), ExportSanitizer::cell($l['label'] ?? ''),
                       ExportSanitizer::cell($l['sku'] ?? ''), $l['quantity'], ExportSanitizer::cell($l['unit'] ?? ''),
                       ExportSanitizer::cell($l['postal_code'] ?? ''), ExportSanitizer::cell($l['palette_code'] ?? ''),
                       ExportSanitizer::cell($l['pl_reference'] ?? '')];
        }
        $sheet->fromArray($rows, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2:A6')->getFont()->setBold(true);
        $sheet->getStyle('A8:N8')->getFont()->setBold(true);
        $sheet->setAutoFilter('A8:N' . (8 + count($lines)));
        $sheet->freezePane('A9');
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Colisage_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $palette->code) . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        if (ob_get_length()) ob_end_clean();
        $writer->save('php://output');
        exit;
    }

    /**
     * Étiquette 100 x 150 mm de la palette client clôturée : client, semaine, QR code (code palette client).
     * Page HTML qui lance directement l'impression du navigateur (pas de PDF).
     */
    public function label(Request $request, int $id): Response
    {
        $this->authorize('reception_view', '/receptions');
        $palette = ClientPalette::findById($id);
        if (!$palette || !$palette->isClosed()) {
            $this->flash(FLASH_ERROR, $palette ? "Clôturez la palette avant d'imprimer son étiquette." : "Palette client non trouvée.");
            return $this->redirect($palette ? '/client-palettes/' . $id : '/receptions/client-packing');
        }
        $logoPath = ROOT_PATH . '/logo.jpg';
        $html = $this->renderPartial('client_palettes/label.php', [
            'palette' => $palette,
            'qrDataUri' => QrCodeService::svgDataUri($palette->code, 400),
            'logoBase64' => is_readable($logoPath) ? base64_encode(file_get_contents($logoPath)) : null,
            'backUrl' => BASE_URL . '/client-palettes/' . $palette->id,
        ]);
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function exportPdf(Request $request, int $id): void
    {
        $this->authorize('reception_view', '/receptions');
        [$palette, $lines] = $this->paletteForExport($id);

        $logoPath = ROOT_PATH . '/logo.jpg';
        $html = $this->renderPartial('client_palettes/export_pdf.php', [
            'palette' => $palette,
            'lines' => $lines,
            'appName' => APP_NAME,
            'currentDate' => (new DateTime())->format('d/m/Y H:i'),
            'logoBase64' => is_readable($logoPath) ? base64_encode(file_get_contents($logoPath)) : null,
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        if (ob_get_level()) ob_end_clean();
        $dompdf->stream('Colisage_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $palette->code) . '_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
        exit;
    }
}

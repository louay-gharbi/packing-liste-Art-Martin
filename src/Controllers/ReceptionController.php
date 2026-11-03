<?php
// src/Controllers/ReceptionController.php

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
use App\Models\Site;
use DateTime;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Réception France des Packing Lists expédiées depuis la Tunisie.
 *
 * CLOISONNEMENT : volontairement, ce contrôleur n'appelle PAS denyIfNotAccessible() sur la PL.
 * Les utilisateurs du site de réception (sites.type = 'reception') doivent voir les PL de tous les sites de production,
 * ce que PackingList::isAccessibleBy() leur refuserait. L'accès est contrôlé uniquement par les
 * permissions reception_view / reception_scan / reception_close.
 */
class ReceptionController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        Auth::protect();
    }

    /**
     * Charge la réception ou renvoie une redirection avec flash.
     */
    private function loadReception(int $id): Reception|Response
    {
        $reception = Reception::findById($id);
        if (!$reception) {
            $this->flash(FLASH_ERROR, "Réception non trouvée.");
            return $this->redirect('/receptions');
        }
        return $reception;
    }

    public function index(Request $request): Response
    {
        $this->authorize('reception_view');

        $filters = [
            'reception_status' => (string)$request->get('reception_status', ''),
            'site_id' => (int)$request->get('site_id', 0) ?: null,
            'week' => '',
        ];
        // Semaine d'expédition ISO (?week=2026-W42) → du lundi au dimanche.
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', (string)$request->get('week', ''), $m) && (int)$m[2] >= 1 && (int)$m[2] <= 53) {
            $monday = (new DateTime())->setISODate((int)$m[1], (int)$m[2]);
            $filters['week'] = sprintf('%d-W%02d', (int)$m[1], (int)$m[2]);
            $filters['date_from'] = $monday->format('Y-m-d');
            $filters['date_to'] = $monday->modify('+6 days')->format('Y-m-d');
        }

        return $this->view('receptions/index.php', [
            'pageTitle' => 'Réception',
            'rows' => Reception::getEligiblePackingLists($filters),
            'filters' => $filters,
            'productionSites' => Site::getAllActiveProduction(),
            'shipWeeks' => Reception::getShipWeeks(),
        ]);
    }

    public function start(Request $request, int $plId): Response
    {
        $this->authorize('reception_scan', '/receptions');

        $pl = PackingList::findById($plId);
        if (!$pl || $pl->status !== 'closed') {
            $this->flash(FLASH_ERROR, "Seule une Packing List fermée (expédiée) peut être réceptionnée.");
            return $this->redirect('/receptions');
        }

        $reception = Reception::start($plId, Auth::id(), Auth::user()->site_id);
        if (!$reception) {
            $this->flash(FLASH_ERROR, "Impossible de démarrer la réception de la Packing List " . $pl->reference . ".");
            return $this->redirect('/receptions');
        }
        return $this->redirect('/receptions/scan/' . $reception->id);
    }

    public function scan(Request $request, int $id): Response
    {
        $this->authorize('reception_scan', '/receptions');
        $reception = $this->loadReception($id);
        if ($reception instanceof Response) return $reception;

        if ($reception->isClosed()) {
            $this->flash(FLASH_INFO, "Cette réception est clôturée.");
            return $this->redirect('/receptions/report/' . $id);
        }

        $pl = PackingList::findById($reception->packing_list_id);
        $pl?->loadSite();

        return $this->view('receptions/scan.php', [
            'pageTitle' => 'Réception ' . ($pl ? $pl->reference : ''),
            'reception' => $reception,
            'packingList' => $pl,
            'state' => Reception::getState($reception),
            'recentScans' => Reception::getRecentScans($id),
        ]);
    }

    /**
     * Scan AJAX d'une palette complète (R5) ; les colis ne se scannent pas en réception.
     * 200 reçue · 409 doublon · 422 inattendue (enregistrée) · 400 entrée invalide (colis, PL, code inconnu) / réception clôturée.
     */
    public function processScanAjax(Request $request, int $id): Response
    {
        $this->authorize('reception_scan');
        $reception = Reception::findById($id);
        if (!$reception) {
            return Response::json(['error' => 'Réception non trouvée.'], 404);
        }
        if ($reception->isClosed()) {
            return Response::json(['error' => 'Réception clôturée : aucun scan possible.'], 400);
        }

        $input = trim((string)$request->post('scan_input', ''));
        if ($input === '') {
            return Response::json(['error' => 'Entrée vide.'], 400);
        }

        $userId = Auth::id();
        $palette = Palette::findByCode($input);

        if ($palette) {
            try {
                $scan = Reception::scanPalette($reception, $palette, $userId);
            } catch (\Throwable $e) {
                error_log("ReceptionController::processScanAjax palette {$input}: " . $e->getMessage());
                return Response::json(['error' => "Erreur lors de l'enregistrement de la palette."], 500);
            }
            $messages = [
                'received'   => "Palette {$palette->code} reçue.",
                'duplicate'  => "Palette {$palette->code} déjà reçue.",
                'unexpected' => "Palette {$palette->code} n'appartient pas à cette Packing List : enregistrée comme inattendue.",
            ];
            $httpCodes = ['received' => 200, 'duplicate' => 409, 'unexpected' => 422];
            return Response::json($this->scanPayload($reception, $scan['status'], $messages[$scan['status']]), $httpCodes[$scan['status']]);
        }

        // Référence de PL ou code au format palette inexistant : erreur de scan, rien n'est enregistré
        // (sinon ces codes seraient pris pour des colis inconnus et compteraient comme écarts).
        if (PackingList::findByReference($input)) {
            return Response::json(['error' => "\"{$input}\" est la référence d'une Packing List : scannez une palette ou un colis."], 400);
        }
        if (preg_match('/^PA?L-/i', $input)) {
            return Response::json(['error' => "Palette \"{$input}\" introuvable : vérifiez le code (site, semaine, numéro). Rien n'a été enregistré."], 400);
        }

        // La réception se fait par palette complète (filmée) : un scan de colis est refusé, en indiquant sa palette.
        $parsed = Colis::parseQrPayload($input);
        $packageNumber = trim((string)($parsed['package_number'] ?? '')) ?: $input;
        $paletteCode = Reception::findPaletteCodeOfColis($packageNumber);
        return Response::json(['error' => $paletteCode
            ? "\"{$packageNumber}\" est un colis : la réception se fait par palette complète. Scannez l'étiquette de la palette {$paletteCode}."
            : "Code \"{$input}\" inconnu : scannez l'étiquette d'une palette. Rien n'a été enregistré."], 400);
    }

    /**
     * Réponse commune des appels AJAX : message + fragments HTML rafraîchis (source unique de rendu).
     */
    private function scanPayload(Reception $reception, string $result, string $message): array
    {
        $state = Reception::getState($reception);
        $viewData = ['reception' => $reception, 'state' => $state, 'baseUrl' => BASE_URL];
        return [
            'success' => $result === 'received',
            'result' => $result,
            'message' => $message,
            'error' => $result === 'received' ? null : $message,
            'counts' => $state['counts'],
            'palettes_html' => $this->renderPartial('receptions/_palettes_table.php', $viewData),
            'unexpected_html' => $this->renderPartial('receptions/_unexpected.php', $viewData),
            'recent_html' => $this->renderPartial('receptions/_recent_scans.php', ['recentScans' => Reception::getRecentScans($reception->id)]),
        ];
    }

    public function close(Request $request, int $id): Response
    {
        $this->authorize('reception_close', '/receptions/scan/' . $id);
        $reception = $this->loadReception($id);
        if ($reception instanceof Response) return $reception;

        $comment = trim((string)$request->post('comment', ''));
        $comment = $comment === '' ? null : mb_substr($comment, 0, 5000);
        $state = Reception::getState($reception);

        if (!Reception::close($id, Auth::id(), $comment, $state['discrepancy_count'])) {
            $this->flash(FLASH_ERROR, "Impossible de clôturer la réception (déjà clôturée ?).");
            return $this->redirect('/receptions/scan/' . $id);
        }
        $this->flash(FLASH_SUCCESS, $state['discrepancy_count'] === 0
            ? "Réception clôturée : conforme."
            : "Réception clôturée avec {$state['discrepancy_count']} écart(s).");
        return $this->redirect('/receptions/report/' . $id);
    }

    public function report(Request $request, int $id): Response
    {
        $this->authorize('reception_view', '/receptions');
        $reception = $this->loadReception($id);
        if ($reception instanceof Response) return $reception;

        $pl = PackingList::findById($reception->packing_list_id);
        $pl?->loadSite();

        return $this->view('receptions/report.php', [
            'pageTitle' => 'Rapport de réception ' . ($pl ? $pl->reference : ''),
            'reception' => $reception,
            'packingList' => $pl,
            'state' => Reception::getState($reception),
        ]);
    }

    public function exportReportXlsx(Request $request, int $id): void
    {
        $this->authorize('reception_view', '/receptions');
        $reception = Reception::findById($id);
        $pl = $reception ? PackingList::findById($reception->packing_list_id) : null;
        if (!$reception || !$pl) {
            $this->flash(FLASH_ERROR, "Réception non trouvée pour l'export.");
            $this->redirect('/receptions')->send();
            exit;
        }
        $pl->loadSite();
        $state = Reception::getState($reception);

        $spreadsheet = new Spreadsheet();

        // Onglet 1 : synthèse
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthèse');
        $c = $state['counts'];
        $summary = [
            ['Packing List', ExportSanitizer::cell($pl->reference)],
            ["Site d'origine", ExportSanitizer::cell($pl->site->name ?? '')],
            ["Date d'expédition", (new DateTime($pl->ship_date))->format('d/m/Y')],
            ['Réception démarrée le', (new DateTime($reception->started_at))->format('d/m/Y H:i')],
            ['Démarrée par', ExportSanitizer::cell($reception->startedByName ?? '')],
            ['Statut', $reception->isClosed() ? 'Clôturée' : 'En cours'],
            ['Clôturée le', $reception->closed_at ? (new DateTime($reception->closed_at))->format('d/m/Y H:i') : ''],
            ['Clôturée par', ExportSanitizer::cell($reception->closedByName ?? '')],
            ['Commentaire', ExportSanitizer::cell($reception->comment ?? '')],
            [],
            ['Palettes attendues', $c['palettes_expected']],
            ['Palettes reçues', $c['palettes_received']],
            ['Colis attendus', $c['colis_expected']],
            ['Colis reçus', $c['colis_received']],
            ['Inattendus', $c['unexpected']],
            ["Nombre total d'écarts", $state['discrepancy_count']],
        ];
        $sheet->fromArray($summary, null, 'A1');
        $sheet->getStyle('A1:A16')->getFont()->setBold(true);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        // Onglet 2 : écarts détaillés
        $rows = [['Type d\'écart', 'Palette', 'Colis', 'OF', 'Article', 'Libellé', 'Quantité', 'Commentaire']];
        foreach ($state['missing_palettes'] as $mp) {
            $rows[] = ['Palette manquante', ExportSanitizer::cell($mp['code']), $mp['nb_colis'] . ' colis', '', '', '', '', ''];
            foreach ($mp['colis'] as $col) {
                $rows[] = ['Colis manquant (palette manquante)', ExportSanitizer::cell($mp['code']), ExportSanitizer::cell($col['package_number']),
                           ExportSanitizer::cell($col['of_number'] ?? ''), ExportSanitizer::cell($col['article_reference'] ?? ''),
                           ExportSanitizer::cell($col['label'] ?? ''), $col['quantity'] ?? '', ''];
            }
        }
        foreach ($state['missing_colis'] as $paletteCode => $list) {
            foreach ($list as $col) {
                $rows[] = ['Colis manquant', ExportSanitizer::cell($paletteCode), ExportSanitizer::cell($col['package_number']),
                           ExportSanitizer::cell($col['of_number'] ?? ''), ExportSanitizer::cell($col['article_reference'] ?? ''),
                           ExportSanitizer::cell($col['label'] ?? ''), $col['quantity'] ?? '', ''];
            }
        }
        foreach ($state['unexpected_palettes'] as $up) {
            $rows[] = ['Palette inattendue', ExportSanitizer::cell($up['code']), '', '', '', '',
                       '', ExportSanitizer::cell('PL d\'origine : ' . $up['origin_pl_reference'])];
        }
        foreach ($state['unexpected_colis'] as $uc) {
            $rows[] = ['Colis inattendu', '', ExportSanitizer::cell($uc['package_number']), '', '', '', '',
                       ExportSanitizer::cell($uc['origin_pl_reference'] ? 'PL d\'origine : ' . $uc['origin_pl_reference'] : 'Inconnu en base')];
        }
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Écarts');
        $sheet2->fromArray($rows, null, 'A1');
        $sheet2->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $col) {
            $sheet2->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Reception_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $pl->reference) . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        if (ob_get_length()) ob_end_clean();
        $writer->save('php://output');
        exit;
    }

    /**
     * Semaine ISO demandée (?week=2026-W42), sinon la plus récente ayant une réception, sinon la semaine courante.
     * @return array [année, semaine, liste des semaines disponibles]
     */
    private function resolveWeek(Request $request): array
    {
        $weeks = Reception::getWeeksWithReceptions();
        if (preg_match('/^(\d{4})-W(\d{1,2})$/', (string)$request->get('week', ''), $m) && (int)$m[2] >= 1 && (int)$m[2] <= 53) {
            return [(int)$m[1], (int)$m[2], $weeks];
        }
        if ($weeks) {
            return [$weeks[0]['year'], $weeks[0]['week'], $weeks];
        }
        $now = new DateTime();
        return [(int)$now->format('o'), (int)$now->format('W'), $weeks];
    }

    /**
     * Colisage client par semaine d'expédition, calculé à la volée depuis les réceptions clôturées.
     */
    public function clientPacking(Request $request): Response
    {
        $this->authorize('reception_view', '/receptions');
        [$year, $week, $weeks] = $this->resolveWeek($request);
        $data = Reception::getClientPackingForWeek($year, $week);
        $client = (string)$request->get('client', '');

        return $this->view('receptions/client_packing.php', [
            'pageTitle' => sprintf('Colisage client — S%02d %d', $week, $year),
            'year' => $year,
            'week' => $week,
            'weekParam' => sprintf('%d-W%02d', $year, $week),
            'weeks' => $weeks,
            'data' => $data,
            'selectedClient' => isset($data['clients'][$client]) ? $client : null,
            'placed' => ClientPalette::getPlacedMap($year, $week),
            'clientPalettes' => ClientPalette::getForWeek($year, $week),
        ]);
    }

    /**
     * Données d'export : un client (?client=) ou tous. Redirige si rien à exporter.
     */
    private function clientPackingForExport(Request $request): array
    {
        [$year, $week] = $this->resolveWeek($request);
        $data = Reception::getClientPackingForWeek($year, $week);
        $client = (string)$request->get('client', '');
        $clients = $client !== '' ? array_intersect_key($data['clients'], [$client => true]) : $data['clients'];
        if (!$clients) {
            $this->flash(FLASH_INFO, "Aucun colis reçu à exporter pour cette semaine.");
            $this->redirect('/receptions/client-packing?week=' . sprintf('%d-W%02d', $year, $week))->send();
            exit;
        }
        $suffix = sprintf('S%02d_%d', $week, $year) . ($client !== '' ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $client) : '');
        return [$year, $week, $data, $clients, $suffix];
    }

    public function exportClientPackingXlsx(Request $request): void
    {
        $this->authorize('reception_view', '/receptions');
        [$year, $week, $data, $clients, $suffix] = $this->clientPackingForExport($request);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $header = ['Client', 'N° commande', 'N° ligne', 'Colis', 'Groupage', 'OF', 'Réf. interne', 'Code article', 'Libellé',
                   'Référencement client', 'Quantité', 'Unité', 'Code postal', 'Palette', 'Packing List'];
        $usedTitles = [];
        foreach ($clients as $client => $lines) {
            // Titre d'onglet Excel : 31 caractères max, sans []:*?/\ et unique.
            $title = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', '_', $client), 0, 28);
            $base = $title; $i = 2;
            while (isset($usedTitles[mb_strtolower($title)])) { $title = $base . '_' . $i++; }
            $usedTitles[mb_strtolower($title)] = true;

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $rows = [[sprintf('Colisage client %s — semaine %02d/%d (expédition du %s au %s)', ExportSanitizer::cell($client), $week, $year,
                              $data['monday']->format('d/m/Y'), $data['sunday']->format('d/m/Y'))], [], $header];
            $totalQty = 0;
            foreach ($lines as $l) {
                $totalQty += $l['quantity'] ?? 0;
                $rows[] = [ExportSanitizer::cell($l['client_name']), ExportSanitizer::cell($l['order_number'] ?? ''), ExportSanitizer::cell($l['line_number'] ?? ''),
                           ExportSanitizer::cell($l['package_number']), ExportSanitizer::cell($l['groupage_number'] ?? ''),
                           ExportSanitizer::cell($l['of_number'] ?? ''), ExportSanitizer::cell($l['internal_reference'] ?? ''),
                           ExportSanitizer::cell($l['article_reference'] ?? ''), ExportSanitizer::cell($l['label'] ?? ''),
                           ExportSanitizer::cell($l['sku'] ?? ''), $l['quantity'], ExportSanitizer::cell($l['unit'] ?? ''),
                           ExportSanitizer::cell($l['postal_code'] ?? ''), ExportSanitizer::cell($l['palette_code']),
                           ExportSanitizer::cell($l['pl_reference'])];
            }
            $rows[] = [];
            $rows[] = ['Total', '', '', count($lines) . ' colis', '', '', '', '', '', '', $totalQty];
            $sheet->fromArray($rows, null, 'A1');
            $lastRow = count($rows);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A3:O3')->getFont()->setBold(true);
            $sheet->getStyle("A{$lastRow}:O{$lastRow}")->getFont()->setBold(true);
            $sheet->setAutoFilter('A3:O' . (3 + count($lines)));
            $sheet->freezePane('A4');
            foreach (range('B', 'O') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'Colisage_client_' . $suffix . '_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        if (ob_get_length()) ob_end_clean();
        $writer->save('php://output');
        exit;
    }

    public function exportClientPackingPdf(Request $request): void
    {
        $this->authorize('reception_view', '/receptions');
        [$year, $week, $data, $clients, $suffix] = $this->clientPackingForExport($request);

        $logoPath = ROOT_PATH . '/logo.jpg';
        $html = $this->renderPartial('receptions/export_client_packing.php', [
            'year' => $year,
            'week' => $week,
            'monday' => $data['monday'],
            'sunday' => $data['sunday'],
            'clients' => $clients,
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
        $dompdf->stream('Colisage_client_' . $suffix . '_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
        exit;
    }
}

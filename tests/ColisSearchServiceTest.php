<?php
use PHPUnit\Framework\TestCase;
use App\Services\ColisSearchService;
use App\Models\Colis;

class ColisSearchServiceTest extends TestCase {
    protected function setUp(): void {
        $_SESSION = [];
        $pdo = getPDO();
        $pdo->exec("DELETE FROM palettes");
        $pdo->exec("DELETE FROM colis");
        $pdo->exec("DELETE FROM packing_lists");
        $pdo->exec("DELETE FROM sites");
        $pdo->exec("INSERT INTO sites (id, code, name) VALUES (1,'TEST','Test');");
        $pdo->exec("INSERT INTO packing_lists (id, reference, site_id, created_at, updated_at) VALUES (1,'PL1',1,datetime('now'),datetime('now')); ");
        $pdo->exec("INSERT INTO palettes (id, packing_list_id, code, status, max_colis, open_date_time, created_at, updated_at) VALUES (1,1,'PAL1','open',10,datetime('now'),datetime('now'),datetime('now'));" );
    }

    private function createColis(string $pn): Colis {
        return Colis::create([
            'palette_id' => 1,
            'package_number' => $pn,
            'payload_json' => '{}',
            'scan_date_time' => date('Y-m-d H:i:s'),
            'scanned_by_user_id' => 1,
            'type' => 'product'
        ]);
    }

    public function testSearchReturnsResults() {
        $this->createColis('PN123');
        $service = new ColisSearchService();
        $res = $service->search(['package_number' => 'PN123']);
        $this->assertEquals(1, $res['total']);
        $this->assertSame('PN123', $res['items'][0]['package_number']);
        $this->assertSame('open', $res['items'][0]['palette_status']);
    }
}

<?php
use PHPUnit\Framework\TestCase;
use App\Controllers\ColisController;

// Contrôleur de test sans authentification ni autorisation
class TestColisController extends ColisController {
    public function __construct() {}
    protected function authorize(string $permissionName, ?string $redirectUrl = null, ?string $flashMessage = null): void {}
}
use App\Lib\Request;
use App\Models\Colis;

class ColisGroupageTest extends TestCase {
    protected function setUp(): void {
        $_SESSION = [];
        $pdo = getPDO();
        $pdo->exec("DELETE FROM palettes");
        $pdo->exec("DELETE FROM colis");
        $pdo->exec("INSERT INTO palettes (id, packing_list_id, code, status, max_colis, open_date_time, created_at, updated_at) VALUES (1,1,'PAL1','open',10,datetime('now'),datetime('now'),datetime('now'));" );
        $_SESSION['user_id'] = 1;
    }

    private function simulateRequest(string $payload): Request {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['colis_qr_payload' => $payload];
        return new Request();
    }

    public function testParentAndChildScan() {
        $_SESSION['active_scan_palette_id'] = 1;
        $controller = new TestColisController();

        $controller->processColisScan($this->simulateRequest('package_number|PARENT1'));
        $this->assertArrayHasKey('active_parent_colis_id', $_SESSION);
        $parentId = $_SESSION['active_parent_colis_id'];
        $parent = Colis::findById($parentId);
        $this->assertEquals('parent', $parent->type);
        
        $controller->processColisScan($this->simulateRequest('package_number|CHILD1;qty|1'));
        $child = Colis::findById($parentId + 1);
        $this->assertEquals($parentId, $child->parent_colis_id);
        $this->assertNull($child->palette_id);

        $palette = \App\Models\Palette::findById(1);
        $this->assertEquals(1, $palette->getColisCount());

        $colisOnPalette = Colis::findByPaletteId(1);
        $this->assertCount(2, $colisOnPalette);
        $childFromList = array_filter($colisOnPalette, fn($c) => $c->parent_colis_id !== null);
        $this->assertNotEmpty($childFromList);
        $childObj = array_values($childFromList)[0];
        $this->assertEquals($parent->package_number, $childObj->parent_package_number);

        $colisPlList = Colis::findAllByPackingListId(1);
        $this->assertCount(2, $colisPlList);
        $childFromPl = array_filter($colisPlList, fn($c) => $c->parent_colis_id !== null);
        $this->assertNotEmpty($childFromPl);
        $childPlObj = array_values($childFromPl)[0];
        $this->assertEquals($parent->package_number, $childPlObj->parent_package_number);
    }
}

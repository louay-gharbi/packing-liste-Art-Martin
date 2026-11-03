<?php
use PHPUnit\Framework\TestCase;
use App\Controllers\ColisController;
use App\Models\Colis;
use App\Lib\Request;

class TestMoveController extends ColisController {
    public function __construct() {}
    protected function authorize(string $permissionName, ?string $redirectUrl = null, ?string $flashMessage = null): void {}
}

class MoveColisTest extends TestCase {
    protected function setUp(): void {
        $_SESSION = [];
        $pdo = getPDO();
        $pdo->exec("DELETE FROM palettes");
        $pdo->exec("DELETE FROM colis");
        $pdo->exec("INSERT INTO palettes (id, packing_list_id, code, status, max_colis, open_date_time, created_at, updated_at) VALUES (1,1,'PAL1','open',10,datetime('now'),datetime('now'),datetime('now'));" );
        $pdo->exec("INSERT INTO palettes (id, packing_list_id, code, status, max_colis, open_date_time, created_at, updated_at) VALUES (2,1,'PAL2','open',10,datetime('now'),datetime('now'),datetime('now'));" );
        $_SESSION['user_id'] = 1;
    }

    private function createColis(array $data): Colis {
        return Colis::create(array_merge([
            'palette_id' => 1,
            'package_number' => 'PN'.rand(1000,9999),
            'payload_json' => '{}',
            'scan_date_time' => date('Y-m-d H:i:s'),
            'scanned_by_user_id' => 1,
            'type' => 'product',
            'parent_colis_id' => null
        ], $data));
    }

    private function simulateRequest(array $post): Request {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post;
        return new Request();
    }

    public function testMovingChildMovesParent() {
        $parent = $this->createColis(['type' => 'parent']);
        $child = $this->createColis(['palette_id' => null, 'parent_colis_id' => $parent->id]);

        $controller = new TestMoveController();
        $request = $this->simulateRequest([
            'destination_palette_code' => 'PAL2',
            'source_palette_redirect_url' => '/palettes/show/1'
        ]);
        $controller->processMove($request, $child->id);

        $movedParent = Colis::findById($parent->id);
        $this->assertEquals(2, $movedParent->palette_id);
    }
}

<?php
use PHPUnit\Framework\TestCase;
use App\Models\Colis;

class DeleteColisTest extends TestCase {
    protected function setUp(): void {
        $_SESSION = [];
        $pdo = getPDO();
        $pdo->exec("DELETE FROM palettes");
        $pdo->exec("DELETE FROM colis");
        $pdo->exec("INSERT INTO palettes (id, packing_list_id, code, status, max_colis, open_date_time, created_at, updated_at) VALUES (1,1,'PAL1','open',10,datetime('now'),datetime('now'),datetime('now'));" );
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

    public function testDeletingParentRemovesChildren() {
        $parent = $this->createColis(['type' => 'parent']);
        $child = $this->createColis(['palette_id' => null, 'parent_colis_id' => $parent->id]);

        $this->assertNotNull(Colis::findById($parent->id));
        $this->assertNotNull(Colis::findById($child->id));

        $this->assertTrue(Colis::deleteById($parent->id));

        $this->assertNull(Colis::findById($parent->id));
        $this->assertNull(Colis::findById($child->id));
    }

    public function testDeletingChildRemovesWholeFamily() {
        $parent = $this->createColis(['type' => 'parent']);
        $child1 = $this->createColis(['palette_id' => null, 'parent_colis_id' => $parent->id]);
        $child2 = $this->createColis(['palette_id' => null, 'parent_colis_id' => $parent->id]);

        $this->assertTrue(Colis::deleteById($child1->id));

        $this->assertNull(Colis::findById($parent->id));
        $this->assertNull(Colis::findById($child1->id));
        $this->assertNull(Colis::findById($child2->id));
    }
}


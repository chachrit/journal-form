<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubmissionValidatorTest extends TestCase
{
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    private function schema(): array
    {
        return ['title' => 't', 'fields' => [
            ['name' => 'fullname', 'type' => 'text', 'label' => 'ชื่อ', 'default' => '{user.name}', 'readonly' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'อีเมล'],
            ['name' => 'qty', 'type' => 'number', 'label' => 'จำนวน', 'required' => true, 'min' => 1, 'max' => 10],
            ['name' => 'item', 'type' => 'select', 'label' => 'อุปกรณ์', 'required' => true, 'options' => ['เมาส์', 'อื่นๆ']],
            ['name' => 'item_other', 'type' => 'text', 'label' => 'ระบุ', 'required' => true,
                'show_if' => ['field' => 'item', 'op' => 'eq', 'value' => 'อื่นๆ']],
            ['name' => 'extras', 'type' => 'checkbox', 'label' => 'เพิ่มเติม', 'options' => ['a', 'b']],
            ['name' => 'when', 'type' => 'date', 'label' => 'วันที่', 'min' => '2026-01-01'],
            ['name' => 'phone', 'type' => 'tel', 'label' => 'โทร', 'pattern' => '0[0-9]{8,9}'],
            ['name' => 'note', 'type' => 'textarea', 'label' => 'หมายเหตุ', 'maxlength' => 10],
        ]];
    }

    private function validate(array $post, array $files = [], ?array $schema = null): array
    {
        $v = new SubmissionValidator($schema ?? $this->schema(), ['user.name' => 'สมชาย ใจดี']);
        return $v->validate($post, $files);
    }

    public function testValidSubmission(): void
    {
        $r = $this->validate(['email' => 'a@b.co', 'qty' => '3', 'item' => 'เมาส์', 'extras' => ['b'], 'when' => '2026-05-01', 'phone' => '0812345678', 'note' => "สอง\r\nบรรทัด"]);
        $this->assertSame([], $r['errors']);
        $this->assertSame('สมชาย ใจดี', $r['values']['fullname']);
        $this->assertSame(['b'], $r['values']['extras']);
        $this->assertSame("สอง\nบรรทัด", $r['values']['note']);
        $this->assertArrayNotHasKey('item_other', $r['values'], 'ช่องที่ถูกซ่อนต้องไม่ถูกเก็บ');
    }

    public function testReadonlyDefaultCannotBeTampered(): void
    {
        $r = $this->validate(['fullname' => 'คนอื่น', 'qty' => '1', 'item' => 'เมาส์']);
        $this->assertSame('สมชาย ใจดี', $r['values']['fullname']);
    }

    public function testRequiredAndConditionalRequired(): void
    {
        $r = $this->validate(['item' => 'อื่นๆ']);
        $this->assertArrayHasKey('qty', $r['errors']);
        $this->assertArrayHasKey('item_other', $r['errors'], 'ช่องที่แสดงเพราะเงื่อนไขต้องถูกบังคับกรอก');
    }

    public function testHiddenFieldValueIsDropped(): void
    {
        $r = $this->validate(['qty' => '1', 'item' => 'เมาส์', 'item_other' => 'แอบส่ง']);
        $this->assertSame([], $r['errors']);
        $this->assertArrayNotHasKey('item_other', $r['values']);
    }

    public function testTypeErrors(): void
    {
        $r = $this->validate([
            'email' => 'bad', 'qty' => '99', 'item' => 'ไม่มีตัวเลือกนี้', 'extras' => ['z'],
            'when' => '2025-12-31', 'phone' => '12', 'note' => str_repeat('ก', 11),
        ]);
        $this->assertEqualsCanonicalizing(['email', 'qty', 'item', 'extras', 'when', 'phone', 'note'], array_keys($r['errors']));
    }

    public function testDateTime(): void
    {
        $schema = ['title' => 't', 'fields' => [['name' => 'in', 'type' => 'datetime', 'label' => 'เข้างาน', 'required' => true]]];
        $this->assertSame([], $this->validate(['in' => '2026-11-11T09:30'], [], $schema)['errors']);
        $this->assertArrayHasKey('in', $this->validate(['in' => '2026-13-11T09:30'], [], $schema)['errors']);
        $this->assertArrayHasKey('in', $this->validate(['in' => '2026-11-11 09:30'], [], $schema)['errors']);
        $this->assertSame('11/11/2026 09:30', FormRenderer::plainValue($schema['fields'][0], '2026-11-11T09:30'));
    }

    public function testNonNumeric(): void
    {
        $r = $this->validate(['qty' => 'abc', 'item' => 'เมาส์']);
        $this->assertSame('กรุณากรอกเป็นตัวเลข', $r['errors']['qty']);
    }

    public function testArrayInjectionIntoTextIsIgnored(): void
    {
        $r = $this->validate(['qty' => ['1'], 'item' => 'เมาส์']);
        $this->assertArrayHasKey('qty', $r['errors']);
    }

    private function sigSchema(): array
    {
        return ['title' => 't', 'fields' => [['name' => 'sign', 'type' => 'signature', 'label' => 'ลายเซ็น', 'required' => true]]];
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public function testSignature(): void
    {
        $png = $this->png();
        $r = $this->validate(['sign' => 'data:image/png;base64,' . base64_encode($png)], [], $this->sigSchema());
        $this->assertSame([], $r['errors']);
        $this->assertSame($png, $r['signatures']['sign']);

        $r = $this->validate([], [], $this->sigSchema());
        $this->assertSame('กรุณาเซ็นชื่อ', $r['errors']['sign']);

        $r = $this->validate(['sign' => 'data:image/png;base64,' . base64_encode('<?php evil')], [], $this->sigSchema());
        $this->assertArrayHasKey('sign', $r['errors']);
    }

    private function upload(string $name, string $content): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jf');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'x'];
    }

    public function testFileUpload(): void
    {
        $schema = ['title' => 't', 'fields' => [['name' => 'doc', 'type' => 'file', 'label' => 'ไฟล์', 'accept' => '.pdf,.png', 'required' => true]]];

        $r = $this->validate([], ['doc' => $this->upload('รูป.png', $this->png())], $schema);
        $this->assertSame([], $r['errors']);
        $this->assertSame('image/png', $r['uploads']['doc'][0]['mime']);
        $this->assertSame('รูป.png', $r['uploads']['doc'][0]['name']);

        // นามสกุลไม่อยู่ใน accept
        $r = $this->validate([], ['doc' => $this->upload('a.txt', 'hello')], $schema);
        $this->assertStringContainsString('ไม่รองรับ', $r['errors']['doc']);

        // นามสกุลปลอม (.pdf แต่เป็นข้อความ)
        $r = $this->validate([], ['doc' => $this->upload('a.pdf', 'not a pdf')], $schema);
        $this->assertStringContainsString('ไม่ตรงกับนามสกุล', $r['errors']['doc']);

        // ไม่แนบ
        $r = $this->validate([], ['doc' => ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]], $schema);
        $this->assertSame('กรุณาแนบไฟล์', $r['errors']['doc']);
    }

    public function testFileTooLarge(): void
    {
        $schema = ['title' => 't', 'fields' => [['name' => 'doc', 'type' => 'file', 'label' => 'ไฟล์', 'max_size_mb' => 0.001]]];
        $r = $this->validate([], ['doc' => $this->upload('a.png', $this->png() . str_repeat("\0", 2000))], $schema);
        $this->assertStringContainsString('ใหญ่เกิน', $r['errors']['doc']);
    }

    public function testNormalizeMultipleFiles(): void
    {
        $list = SubmissionValidator::normalizeFiles([
            'name' => ['a.pdf', 'b.pdf'], 'tmp_name' => ['/t/a', '/t/b'], 'size' => [1, 2], 'error' => [0, 4], 'type' => ['', ''],
        ]);
        $this->assertCount(2, $list);
        $this->assertSame('b.pdf', $list[1]['name']);
        $this->assertSame(4, $list[1]['error']);
    }
}

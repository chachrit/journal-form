<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormSchemaTest extends TestCase
{
    private function valid(): array
    {
        return [
            'title' => 'ขอเบิกอุปกรณ์',
            'notify' => ['to' => ['it@example.com'], 'subject' => '{fullname}'],
            'fields' => [
                ['name' => 'fullname', 'type' => 'text', 'label' => 'ชื่อ', 'required' => true],
                ['name' => 'item', 'type' => 'select', 'label' => 'อุปกรณ์', 'options' => ['เมาส์', 'อื่นๆ']],
                ['name' => 'item_other', 'type' => 'text', 'label' => 'ระบุ', 'show_if' => ['field' => 'item', 'op' => 'eq', 'value' => 'อื่นๆ']],
                ['type' => 'heading', 'label' => 'ส่วนที่ 2'],
                ['name' => 'sign', 'type' => 'signature', 'label' => 'ลายเซ็น'],
            ],
        ];
    }

    public function testValidSchemaHasNoErrors(): void
    {
        $this->assertSame([], FormSchema::validate($this->valid()));
    }

    public function testInvalidJsonSyntax(): void
    {
        $r = FormSchema::validateJson('{"title": "x",}');
        $this->assertNull($r['schema']);
        $this->assertStringContainsString('JSON ไม่ถูกรูปแบบ', $r['errors'][0]);
    }

    public function testEmptyNotifyObjectIsAllowed(): void
    {
        $r = FormSchema::validateJson('{"title":"x","notify":{},"fields":[{"name":"a","type":"text","label":"A"}]}');
        $this->assertSame([], $r['errors']);
    }

    public function testMissingTitleAndFields(): void
    {
        $errors = FormSchema::validate(['fields' => []]);
        $this->assertCount(2, $errors);
    }

    public function testDuplicateName(): void
    {
        $s = $this->valid();
        $s['fields'][] = ['name' => 'fullname', 'type' => 'text', 'label' => 'ซ้ำ'];
        $this->assertStringContainsString('ซ้ำ', implode("\n", FormSchema::validate($s)));
    }

    public function testBadNameAndUnknownType(): void
    {
        $s = $this->valid();
        $s['fields'][] = ['name' => 'Bad-Name', 'type' => 'text', 'label' => 'x'];
        $s['fields'][] = ['name' => 'ok', 'type' => 'rating', 'label' => 'x'];
        $errors = implode("\n", FormSchema::validate($s));
        $this->assertStringContainsString('"name" ต้องขึ้นต้นด้วย', $errors);
        $this->assertStringContainsString('"type" ไม่รู้จัก', $errors);
    }

    public function testOptionsRequiredForSelect(): void
    {
        $s = $this->valid();
        $s['fields'][1]['options'] = [];
        $this->assertNotEmpty(FormSchema::validate($s));
    }

    public function testDuplicateOptions(): void
    {
        $s = $this->valid();
        $s['fields'][1]['options'] = ['a', 'a'];
        $this->assertStringContainsString('ค่าซ้ำ', implode("\n", FormSchema::validate($s)));
    }

    public function testShowIfMustReferenceEarlierField(): void
    {
        $s = $this->valid();
        $s['fields'][2]['show_if'] = ['field' => 'sign', 'op' => 'not_empty'];   // sign อยู่ทีหลัง
        $this->assertStringContainsString('ก่อนหน้า', implode("\n", FormSchema::validate($s)));
    }

    public function testShowIfUnknownOpAndMissingValue(): void
    {
        $s = $this->valid();
        $s['fields'][2]['show_if'] = ['all' => [
            ['field' => 'item', 'op' => 'like', 'value' => 'x'],
            ['field' => 'item', 'op' => 'eq'],
            ['field' => 'item', 'op' => 'in', 'value' => 'x'],
        ]];
        $this->assertCount(3, FormSchema::validate($s));
    }

    public function testBadNotifyEmail(): void
    {
        $s = $this->valid();
        $s['notify']['to'] = ['not-an-email'];
        $this->assertStringContainsString('อีเมลไม่ถูกต้อง', implode("\n", FormSchema::validate($s)));
    }

    public function testBadPattern(): void
    {
        $s = $this->valid();
        $s['fields'][0]['pattern'] = '([a-z';
        $this->assertStringContainsString('regex', implode("\n", FormSchema::validate($s)));
    }

    public function testResolveDefault(): void
    {
        $ctx = ['user.name' => 'สมชาย', 'today' => '2026-11-11'];
        $this->assertSame('สมชาย (2026-11-11)', FormSchema::resolveDefault('{user.name} ({today})', $ctx));
        $this->assertSame('{unknown}', FormSchema::resolveDefault('{unknown}', $ctx));
        $this->assertSame(5, FormSchema::resolveDefault(5, $ctx));
    }

    public function testOptionsNormalize(): void
    {
        $field = ['options' => ['A', ['value' => 'b', 'label' => 'บี']]];
        $this->assertSame([['value' => 'A', 'label' => 'A'], ['value' => 'b', 'label' => 'บี']], FormSchema::options($field));
        $this->assertSame('บี', FormSchema::optionLabel($field, 'b'));
    }
}

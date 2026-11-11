<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormRendererTest extends TestCase
{
    private function schema(): array
    {
        return ['title' => 't', 'fields' => [
            ['name' => 'fullname', 'type' => 'text', 'label' => 'ชื่อ <b>', 'default' => '{user.name}', 'required' => true],
            ['name' => 'item', 'type' => 'radio', 'label' => 'อุปกรณ์', 'options' => ['เมาส์', 'อื่นๆ']],
            ['name' => 'item_other', 'type' => 'text', 'label' => 'ระบุ', 'show_if' => ['field' => 'item', 'value' => 'อื่นๆ']],
            ['name' => 'tags', 'type' => 'checkbox', 'label' => 'แท็ก', 'options' => [['value' => 'a', 'label' => 'เอ'], ['value' => 'b', 'label' => 'บี']]],
            ['name' => 'sign', 'type' => 'signature', 'label' => 'ลายเซ็น'],
        ]];
    }

    public function testDefaultsAndEscaping(): void
    {
        $r = new FormRenderer($this->schema(), ['user.name' => '"><script>x</script>']);
        $html = $r->renderFields($r->defaults());
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        $this->assertStringContainsString('ชื่อ &lt;b&gt;', $html);
        $this->assertStringContainsString(' required', $html);
    }

    public function testHiddenFieldRenderedHiddenAndDisabled(): void
    {
        $r = new FormRenderer($this->schema());
        $html = $r->renderFields(['item' => 'เมาส์']);
        $this->assertMatchesRegularExpression('/id="wrap-f-item_other"[^>]*hidden/', $html);
        $this->assertMatchesRegularExpression('/name="item_other"[^>]*disabled/', $html);

        $html = $r->renderFields(['item' => 'อื่นๆ']);
        $this->assertDoesNotMatchRegularExpression('/id="wrap-f-item_other"[^>]*hidden/', $html);
    }

    public function testErrorsAndCheckedValues(): void
    {
        $r = new FormRenderer($this->schema());
        $html = $r->renderFields(['tags' => ['b']], ['fullname' => 'กรุณากรอก']);
        $this->assertStringContainsString('has-error', $html);
        $this->assertStringContainsString('กรุณากรอก', $html);
        $this->assertMatchesRegularExpression('/name="tags\[\]" value="b" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="tags\[\]" value="a" checked/', $html);
    }

    public function testAnswersSkipHiddenAndUseLabels(): void
    {
        $r = new FormRenderer($this->schema());
        $rows = $r->answerRows(['fullname' => 'สมชาย', 'item' => 'เมาส์', 'tags' => ['a', 'b']]);
        $this->assertSame(['ชื่อ <b>', 'อุปกรณ์', 'แท็ก'], array_column($rows, 'label'));
        $this->assertSame('เอ, บี', FormRenderer::plainValue($rows[2]['field'], $rows[2]['value']));

        $html = $r->renderAnswers(['fullname' => '<i>x</i>'], ['sign' => [['id' => 7, 'original_name' => 'signature.png', 'size_bytes' => 10]]]);
        $this->assertStringContainsString('&lt;i&gt;', $html);
        $this->assertStringContainsString('/journal-form/file.php?id=7', $html);
    }

    public function testNotifierSubject(): void
    {
        $schema = $this->schema();
        $sub = ['submitter_name' => 'สมชาย', 'data' => ['item' => 'อื่นๆ', 'tags' => ['a']]];
        $this->assertSame(
            'ขอ อื่นๆ / เอ - สมชาย {missing}',
            Notifier::subject("ขอ {item} / {tags}\r\n- {submitter} {missing}", $schema, $sub, [])
        );
    }
}

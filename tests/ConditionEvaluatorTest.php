<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'eq match' => [['field' => 'a', 'op' => 'eq', 'value' => 'x'], ['a' => 'x'], true],
            'eq default op' => [['field' => 'a', 'value' => 'x'], ['a' => 'x'], true],
            'eq no match' => [['field' => 'a', 'op' => 'eq', 'value' => 'x'], ['a' => 'y'], false],
            'eq number vs string' => [['field' => 'a', 'op' => 'eq', 'value' => 5], ['a' => '5'], true],
            'eq missing' => [['field' => 'a', 'op' => 'eq', 'value' => 'x'], [], false],
            'eq on checkbox' => [['field' => 'a', 'op' => 'eq', 'value' => 'x'], ['a' => ['y', 'x']], true],
            'neq' => [['field' => 'a', 'op' => 'neq', 'value' => 'x'], ['a' => 'y'], true],
            'in' => [['field' => 'a', 'op' => 'in', 'value' => ['x', 'y']], ['a' => 'y'], true],
            'in checkbox overlap' => [['field' => 'a', 'op' => 'in', 'value' => ['x']], ['a' => ['z', 'x']], true],
            'not_in' => [['field' => 'a', 'op' => 'not_in', 'value' => ['x']], ['a' => 'y'], true],
            'empty null' => [['field' => 'a', 'op' => 'empty'], ['a' => null], true],
            'empty array' => [['field' => 'a', 'op' => 'empty'], ['a' => []], true],
            'not_empty' => [['field' => 'a', 'op' => 'not_empty'], ['a' => '0'], true],
            'gt numeric' => [['field' => 'a', 'op' => 'gt', 'value' => 10], ['a' => '11'], true],
            'gt numeric not string' => [['field' => 'a', 'op' => 'gt', 'value' => 10], ['a' => '9'], false],
            'gt empty' => [['field' => 'a', 'op' => 'gt', 'value' => 10], ['a' => ''], false],
            'lt date' => [['field' => 'a', 'op' => 'lt', 'value' => '2026-01-01'], ['a' => '2025-12-31'], true],
            'contains checkbox' => [['field' => 'a', 'op' => 'contains', 'value' => 'จอ'], ['a' => ['เมาส์', 'จอ']], true],
            'contains text' => [['field' => 'a', 'op' => 'contains', 'value' => 'abc'], ['a' => 'xabcx'], true],
            'all' => [['all' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'value' => '2']]], ['a' => '1', 'b' => '2'], true],
            'all fails' => [['all' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'value' => '2']]], ['a' => '1', 'b' => '3'], false],
            'any' => [['any' => [['field' => 'a', 'value' => '1'], ['field' => 'b', 'value' => '2']]], ['a' => '0', 'b' => '2'], true],
            'unknown op' => [['field' => 'a', 'op' => 'like', 'value' => 'x'], ['a' => 'x'], false],
        ];
    }

    #[DataProvider('cases')]
    public function testEvaluate(array $cond, array $values, bool $expected): void
    {
        $this->assertSame($expected, ConditionEvaluator::evaluate($cond, $values));
    }

    public function testNullConditionAlwaysVisible(): void
    {
        $this->assertTrue(ConditionEvaluator::evaluate(null, []));
    }

    public function testVisibilityChainsThroughHiddenFields(): void
    {
        $schema = ['fields' => [
            ['name' => 'a', 'type' => 'radio'],
            ['name' => 'b', 'type' => 'text', 'show_if' => ['field' => 'a', 'value' => 'yes']],
            ['name' => 'c', 'type' => 'text', 'show_if' => ['field' => 'b', 'op' => 'not_empty']],
        ]];
        // b มีค่าแต่ถูกซ่อน (a != yes) → c ต้องถูกซ่อนด้วย
        $v = ConditionEvaluator::visibility($schema, ['a' => 'no', 'b' => 'ค่าค้าง']);
        $this->assertSame(['a' => true, 'b' => false, 'c' => false], $v);

        $v = ConditionEvaluator::visibility($schema, ['a' => 'yes', 'b' => 'x']);
        $this->assertSame(['a' => true, 'b' => true, 'c' => true], $v);
    }
}

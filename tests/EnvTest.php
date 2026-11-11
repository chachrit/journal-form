<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $file;
    private array $keys = ['JF_T_PLAIN', 'JF_T_QUOTED', 'JF_T_COMMENT', 'JF_T_BOOL', 'JF_T_EMPTY', 'JF_T_LIST', 'JF_T_EXPORT', 'JF_T_HASH'];

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, implode("\n", [
            '# comment',
            'JF_T_PLAIN=hello',
            'JF_T_QUOTED="สวัสดี ครับ # ไม่ใช่คอมเมนต์"',
            'JF_T_COMMENT=value # คอมเมนต์ท้าย',
            'JF_T_BOOL=true',
            'JF_T_EMPTY=',
            'JF_T_LIST=110001, 110002,abc',
            'export JF_T_EXPORT=x',
            'JF_T_HASH=pa#ss',
            'not a valid line',
        ]));
        loadEnvFile($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        foreach ($this->keys as $k) {
            unset($_ENV[$k]);
        }
    }

    public function testParsing(): void
    {
        $this->assertSame('hello', env('JF_T_PLAIN'));
        $this->assertSame('สวัสดี ครับ # ไม่ใช่คอมเมนต์', env('JF_T_QUOTED'));
        $this->assertSame('value', env('JF_T_COMMENT'));
        $this->assertTrue(env('JF_T_BOOL'));
        $this->assertSame('default', env('JF_T_EMPTY', 'default'));
        $this->assertSame([110001, 110002], env_int_list('JF_T_LIST'));
        $this->assertSame('x', env('JF_T_EXPORT'));
        $this->assertSame('pa#ss', env('JF_T_HASH'), 'รหัสผ่านที่มี # ต้องไม่ถูกตัด');
    }

    public function testRequiredInProduction(): void
    {
        $this->assertSame('dev-default', envRequiredInProduction('JF_T_MISSING', 'development', 'dev-default'));
        $this->expectException(RuntimeException::class);
        envRequiredInProduction('JF_T_MISSING', 'production');
    }
}

<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Console\DevCommand;
use PHPUnit\Framework\TestCase;

/** `nevela dev` tells the mobile app which port the API is on, and leaves the rest of its settings alone. */
final class DevCommandMobileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/nevela-mobile-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env.local');
        @rmdir($this->dir);
    }

    public function test_it_writes_the_port_when_the_app_has_no_settings_yet(): void
    {
        DevCommand::tellMobile($this->dir, 8003);

        $this->assertStringEndsWith("EXPO_PUBLIC_API_PORT=8003\n", (string) file_get_contents($this->dir.'/.env.local'));
    }

    public function test_it_replaces_the_port_and_keeps_every_other_line(): void
    {
        file_put_contents($this->dir.'/.env.local', "EXPO_PUBLIC_SENTRY=abc\nEXPO_PUBLIC_API_PORT=8000\nOTHER=1\n");

        DevCommand::tellMobile($this->dir, 8001);

        $this->assertSame("EXPO_PUBLIC_SENTRY=abc\nEXPO_PUBLIC_API_PORT=8001\nOTHER=1\n", file_get_contents($this->dir.'/.env.local'));
    }

    public function test_it_adds_the_port_below_settings_that_are_already_there(): void
    {
        file_put_contents($this->dir.'/.env.local', "EXPO_PUBLIC_API_URL=https://api.example.com/api\n");

        DevCommand::tellMobile($this->dir, 8000);

        $written = (string) file_get_contents($this->dir.'/.env.local');
        $this->assertStringStartsWith("EXPO_PUBLIC_API_URL=https://api.example.com/api\n\n#", $written);
        $this->assertStringEndsWith("EXPO_PUBLIC_API_PORT=8000\n", $written);
    }

    public function test_it_does_not_touch_a_file_that_already_has_the_port(): void
    {
        file_put_contents($this->dir.'/.env.local', "EXPO_PUBLIC_API_PORT=8000\n");
        touch($this->dir.'/.env.local', 1_000_000);
        clearstatcache();

        DevCommand::tellMobile($this->dir, 8000);

        clearstatcache();
        $this->assertSame(1_000_000, filemtime($this->dir.'/.env.local'));
    }
}

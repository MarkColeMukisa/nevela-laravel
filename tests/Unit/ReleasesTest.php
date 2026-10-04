<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Console\DevCommand;
use Nevela\Laravel\Support\Releases;
use PHPUnit\Framework\TestCase;

final class ReleasesTest extends TestCase
{
    public function test_the_newest_release_ignores_branches_and_pre_releases(): void
    {
        $this->assertSame('0.1.10', Releases::newest(['v0.1.2', 'v0.1.10', 'v0.1.9', 'dev-main', '0.2.0-beta.1', '0.1.x-dev']));
        $this->assertSame('1.0.0', Releases::newest(['0.9.9', '1.0.0']));
        $this->assertNull(Releases::newest(['dev-main']));
        $this->assertNull(Releases::newest([]));
    }

    public function test_dev_skips_a_port_something_is_already_listening_on(): void
    {
        // Take a port the way another app would, then ask for it.
        $taken = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $this->assertNotFalse($taken, (string) $message);
        $port = (int) substr(strrchr(stream_socket_get_name($taken, false), ':'), 1);

        $chosen = DevCommand::freePort($port);
        fclose($taken);

        $this->assertNotNull($chosen);
        $this->assertGreaterThan($port, $chosen);
        $this->assertLessThan($port + 20, $chosen);
    }

    public function test_the_package_manager_is_read_from_the_lock_file(): void
    {
        $dir = sys_get_temp_dir().'/nevela-pm-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->assertSame('npm', DevCommand::packageManager($dir));
        touch("{$dir}/yarn.lock");
        $this->assertSame('yarn', DevCommand::packageManager($dir));
        touch("{$dir}/pnpm-lock.yaml");
        $this->assertSame('pnpm', DevCommand::packageManager($dir));
    }
}

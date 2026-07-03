<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The daily log channel must NOT set an explicit 'permission'. When set, Monolog
 * chmod()s the file on every stream open, which throws UnexpectedValueException
 * ("Operation not permitted") whenever the log file is owned by a different user
 * than the writer (php-fpm=www-data vs artisan/scheduler=root). Group-writable
 * access is instead guaranteed by setgid + umask 0002 on storage/logs (see the
 * php entrypoint). This test locks that decision so it isn't silently reverted.
 */
class DailyLogPermissionTest extends TestCase
{
    public function test_daily_channel_does_not_force_a_file_permission(): void
    {
        $daily = config('logging.channels.daily');

        $this->assertIsArray($daily);
        $this->assertArrayNotHasKey('permission', $daily);
    }
}

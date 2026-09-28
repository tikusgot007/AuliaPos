<?php

use App\Controllers\Inbox;
use PHPUnit\Framework\TestCase;

/**
 * CLN-901/TEST-907: `Inbox::mbKeByte()` harus mengembalikan nilai identik
 * dengan literalin lama `$mb * 1024 * 1024` supaya ekstraksi helper tidak
 * mengubah perilaku transfer media (0/1/15/100 MB).
 *
 * @internal
 */
final class InboxMbKeByteTest extends TestCase
{
    public function testKonversiIdentikDenganLiteralinLama(): void
    {
        $metode = new ReflectionMethod(Inbox::class, 'mbKeByte');
        $metode->setAccessible(true);

        foreach ([0, 1, 15, 100] as $mb) {
            $this->assertSame(
                $mb * 1024 * 1024,
                $metode->invoke(null, $mb),
                "mbKeByte({$mb}) harus sama dengan {$mb} * 1024 * 1024."
            );
        }
    }
}

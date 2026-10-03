<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Kasir::getReminderTagihanSaya() must always return the `hari` key, even on
 * its early-return paths (no logged-in user / reminder throttled), because the
 * docblock promises `array{show, count, hari}` and callers may read it.
 * TODO-BL38.
 *
 * The private method is invoked via reflection. These two paths return before
 * any database access, so this test needs no fixture data.
 *
 * @internal
 */
final class KasirReminderHariTest extends CIUnitTestCase
{
    /**
     * @return array{show: bool, count: int, hari?: int}
     */
    private function reminderFor(?int $userId, ?int $lastShown): array
    {
        $session = service('session');
        $session->remove(['id_user', 'reminder_tagihan_last_shown']);

        if ($userId !== null) {
            $session->set('id_user', $userId);
        }

        if ($lastShown !== null) {
            $session->set('reminder_tagihan_last_shown', $lastShown);
        }

        $method = new ReflectionMethod(\App\Controllers\Kasir::class, 'getReminderTagihanSaya');
        $method->setAccessible(true);

        return $method->invoke(new \App\Controllers\Kasir());
    }

    public function testReminderIncludesHariKeyWithoutLoggedInUser(): void
    {
        $result = $this->reminderFor(null, null);

        $this->assertArrayHasKey('hari', $result);
        $this->assertFalse($result['show']);
        $this->assertSame(0, $result['count']);
    }

    public function testReminderIncludesHariKeyWhenThrottled(): void
    {
        $result = $this->reminderFor(1, time());

        $this->assertArrayHasKey('hari', $result);
        $this->assertFalse($result['show']);
        $this->assertSame(0, $result['count']);
    }
}

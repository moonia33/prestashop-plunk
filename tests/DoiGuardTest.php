<?php

use PHPUnit\Framework\TestCase;
use PlunkConnect\Outbox\OutboxProcessor;

/**
 * Spec 7.2: a repeated signup must not unsubscribe an already subscribed
 * Plunk contact (e.g. subscribers imported from Newsman).
 */
final class DoiGuardTest extends TestCase
{
    /** @return array{event:string,email:string,subscribed:bool} */
    private static function payload(): array
    {
        return [
            'event' => 'newsletter.signup_requested',
            'email' => 'jonas@example.lt',
            'subscribed' => false,
        ];
    }

    public function testSubscribedContactKeepsSubscribedFlagDropped(): void
    {
        $contacts = [['email' => 'jonas@example.lt', 'subscribed' => true]];
        $out = OutboxProcessor::dropSubscribedForSubscribedContact(self::payload(), $contacts);
        $this->assertArrayNotHasKey('subscribed', $out);
        $this->assertSame('jonas@example.lt', $out['email']);
    }

    public function testUnsubscribedContactKeepsFlag(): void
    {
        $contacts = [['email' => 'jonas@example.lt', 'subscribed' => false]];
        $out = OutboxProcessor::dropSubscribedForSubscribedContact(self::payload(), $contacts);
        $this->assertFalse($out['subscribed']);
    }

    public function testMissingContactKeepsFlag(): void
    {
        $contacts = [['email' => 'kitas@example.lt', 'subscribed' => true]];
        $out = OutboxProcessor::dropSubscribedForSubscribedContact(self::payload(), $contacts);
        $this->assertFalse($out['subscribed']);

        $out = OutboxProcessor::dropSubscribedForSubscribedContact(self::payload(), []);
        $this->assertFalse($out['subscribed']);
    }

    public function testEmailMatchIsCaseInsensitive(): void
    {
        $contacts = [['email' => 'JONAS@example.lt', 'subscribed' => true]];
        $out = OutboxProcessor::dropSubscribedForSubscribedContact(self::payload(), $contacts);
        $this->assertArrayNotHasKey('subscribed', $out);
    }

    /**
     * Spec 7.1: SHOW TABLES must go through executeS() - getValue/getRow
     * append "LIMIT 1" and MariaDB returns 1064 (webhook -> 500).
     */
    public function testNoShowTablesThroughGetValue(): void
    {
        $files = [
            'src/Service/SubscriptionService.php',
            'src/Service/TrackingService.php',
            'src/Command/ReconcileSubscribersCommand.php',
            'src/Service/CouponService.php',
            'src/Service/CatalogSyncService.php',
        ];
        foreach ($files as $f) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/' . $f);
            $this->assertSame(
                0,
                preg_match('/get(?:Value|Row)\s*\(\s*[\'"]SHOW/i', $code),
                "getValue/getRow('SHOW …') found in $f - use executeS()"
            );
        }
    }
}

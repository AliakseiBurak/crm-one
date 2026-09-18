<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Call;
use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

final class CallOptOutTest extends TestCase
{
    public function testSetIsRefusalTrue(): void
    {
        $call = new Call();
        $call->setIsRefusal(true);

        self::assertTrue($call->isRefusal);
    }

    public function testSetIsRefusalFalse(): void
    {
        $call = new Call();
        $call->setIsRefusal(true);
        $call->setIsRefusal(false);

        self::assertFalse($call->isRefusal);
    }

    public function testSetIsOptedOutTrueSetsOptedOutAt(): void
    {
        $org = new Organization();
        $org->setIsOptedOut(true);

        self::assertTrue($org->isOptedOut);
        self::assertNotNull($org->optedOutAt);
    }

    public function testSetIsOptedOutFalseResetsReasonAndDate(): void
    {
        $org = new Organization();
        $org->setIsOptedOut(true);
        $org->setOptOutReason('Не заинтересованы');

        self::assertNotNull($org->optedOutAt);
        self::assertSame('Не заинтересованы', $org->optOutReason);

        $org->setIsOptedOut(false);

        self::assertFalse($org->isOptedOut);
        self::assertNull($org->optOutReason);
        self::assertNull($org->optedOutAt);
    }

    public function testSetIsOptedOutTrueTwiceDoesNotResetDate(): void
    {
        $org = new Organization();
        $org->setIsOptedOut(true);
        $firstDate = $org->optedOutAt;

        $org->setIsOptedOut(true);

        self::assertSame($firstDate, $org->optedOutAt);
    }

    public function testSetIsActive(): void
    {
        $org = new Organization();
        self::assertTrue($org->isActive);

        $org->setIsActive(false);
        self::assertFalse($org->isActive);

        $org->setIsActive(true);
        self::assertTrue($org->isActive);
    }
}

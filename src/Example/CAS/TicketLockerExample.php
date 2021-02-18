<?php
namespace App\Services\CAS;

use JuheData\CAS\Contracts\TicketLocker as TicketLockerContract;

class TicketLockerExample implements TicketLockerContract
{
    public function acquireLock($key, $timeout)
    {
        return true;
    }

    public function releaseLock($key)
    {
        return true;
    }
}

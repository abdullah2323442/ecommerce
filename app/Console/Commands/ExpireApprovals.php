<?php

namespace App\Console\Commands;

use App\Services\Sales\ApprovalService;
use Illuminate\Console\Command;

class ExpireApprovals extends Command
{
    protected $signature = 'approvals:expire';

    protected $description = 'Cancel "Need admin approval" requests nobody answered in time';

    public function handle(ApprovalService $approvals): int
    {
        $this->info('Expired '.$approvals->expireOld().' request(s).');

        return self::SUCCESS;
    }
}

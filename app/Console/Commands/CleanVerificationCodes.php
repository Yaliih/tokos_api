<?php
// app/Console/Commands/CleanVerificationCodes.php

namespace App\Console\Commands;

use App\Models\VerificationCode;
use Illuminate\Console\Command;

class CleanVerificationCodes extends Command
{
    protected $signature = 'verification:clean';
    protected $description = 'Clean expired verification codes';

    public function handle()
    {
        $deleted = VerificationCode::cleanExpired();
        $this->info("Deleted {$deleted} expired verification codes.");
    }
}
<?php
// app/Services/VoteCounterService.php

namespace App\Services;

use App\Models\PostDuel;
use App\Models\PostDebate;
use App\Models\ChallengeDuel;
use App\Models\ContestCandidate;
use Illuminate\Support\Facades\Log;

class VoteCounterService
{
    public function refreshAllCounters(): void
    {
        $this->refreshDuels();
        $this->refreshDebates();
        $this->refreshChallengeDuels();
        $this->refreshContestCandidates();
    }

    public function refreshDuels(): void
    {
        $duels = PostDuel::all();
        foreach ($duels as $duel) {
            $duel->refreshVotesCount();
        }
        Log::info('Duels vote counters refreshed', ['count' => $duels->count()]);
    }

    public function refreshDebates(): void
    {
        $debates = PostDebate::all();
        foreach ($debates as $debate) {
            $debate->refreshVotesCount();
        }
        Log::info('Debates vote counters refreshed', ['count' => $debates->count()]);
    }

    public function refreshChallengeDuels(): void
    {
        $duels = ChallengeDuel::all();
        foreach ($duels as $duel) {
            $duel->refreshVotesCount();
        }
        Log::info('Challenge duels vote counters refreshed', ['count' => $duels->count()]);
    }

    public function refreshContestCandidates(): void
    {
        $candidates = ContestCandidate::all();
        foreach ($candidates as $candidate) {
            $candidate->refreshVotesCount();
        }
        Log::info('Contest candidates vote counters refreshed', ['count' => $candidates->count()]);
    }

    public function refreshDuel(int $duelId): void
    {
        $duel = PostDuel::find($duelId);
        if ($duel) $duel->refreshVotesCount();
    }

    public function refreshDebate(int $debateId): void
    {
        $debate = PostDebate::find($debateId);
        if ($debate) $debate->refreshVotesCount();
    }

    public function refreshChallengeDuel(int $duelId): void
    {
        $duel = ChallengeDuel::find($duelId);
        if ($duel) $duel->refreshVotesCount();
    }

    public function refreshContestCandidate(int $candidateId): void
    {
        $candidate = ContestCandidate::find($candidateId);
        if ($candidate) $candidate->refreshVotesCount();
    }
}
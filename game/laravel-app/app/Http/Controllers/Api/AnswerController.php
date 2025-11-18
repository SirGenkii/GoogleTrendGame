<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Round;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnswerController extends Controller
{
    public function store(Request $request, Round $round): JsonResponse
    {
        $validated = $request->validate([
            'player_id' => ['required', 'exists:players,id'],
            'submitted_order' => ['required', 'array', 'min:1'],
        ]);

        $submitted = $validated['submitted_order'];
        $correct = $round->correct_order ?? [];
        $score = $this->scoreOrder($submitted, $correct);

        $answer = Answer::create([
            'round_id' => $round->id,
            'player_id' => $validated['player_id'],
            'submitted_order' => $submitted,
            'score' => $score,
            'submitted_at' => now(),
        ]);

        $this->finalizeRoundIfComplete($round);

        return response()->json($answer, 201);
    }

    private function scoreOrder(array $submitted, array $correct): int
    {
        if (!$correct) {
            return 0;
        }
        $correctCount = 0;
        foreach ($submitted as $idx => $title) {
            if (isset($correct[$idx]) && $correct[$idx] === $title) {
                $correctCount++;
            }
        }
        $scorePerPosition = 25; // 4 articles => max 100
        return $correctCount * $scorePerPosition;
    }

    private function finalizeRoundIfComplete(Round $round): void
    {
        $round->loadMissing(['game.players', 'answers']);
        $playersCount = $round->game?->players?->count() ?? 0;
        $answersCount = $round->answers->count();
        $deadlinePassed = $round->deadline_at && now()->greaterThanOrEqualTo($round->deadline_at);

        if ($round->status !== 'ended' && ($deadlinePassed || ($playersCount > 0 && $answersCount >= $playersCount))) {
            $round->status = 'ended';
            $round->ended_at = now();
            $round->save();
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Game;
use App\Models\Round;
use App\Services\DataQuestionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoundController extends Controller
{
    public function __construct(private DataQuestionService $dataQuestionService)
    {
    }

    public function show(Round $round): JsonResponse
    {
        $round->load(['answers.player', 'game.players']);

        // Marque la manche terminée si le délai est passé ou si tous ont répondu
        $playersCount = $round->game->players()->count();
        $answersCount = $round->answers()->count();
        if ($round->status !== 'ended' && (
            ($round->deadline_at && now()->greaterThanOrEqualTo($round->deadline_at)) ||
            ($playersCount > 0 && $answersCount >= $playersCount)
        )) {
            $round->status = 'ended';
            $round->ended_at = now();
            $round->save();
        }

        $leaderboard = Answer::selectRaw('player_id, SUM(score) as total_score')
            ->whereIn('round_id', $round->game->rounds()->pluck('id'))
            ->groupBy('player_id')
            ->with('player')
            ->orderByDesc('total_score')
            ->get();

        $answers = $round->answers->map(function ($a) use ($round) {
            $correct = $round->correct_order ?? [];
            $submitted = $a->submitted_order ?? [];
            $matches = 0;
            foreach ($submitted as $idx => $title) {
                if (isset($correct[$idx]) && $correct[$idx] === $title) {
                    $matches++;
                }
            }
            return [
                'player' => $a->player,
                'player_id' => $a->player_id,
                'score' => $a->score,
                'matches' => $matches,
                'submitted_order' => $submitted,
            ];
        });

        return response()->json([
            'round' => $round,
            'answers' => $answers,
            'leaderboard' => $leaderboard,
            'ended' => $round->status === 'ended',
            'allPlayersAnswered' => $round->game->players()->count() > 0 && $round->answers()->count() >= $round->game->players()->count(),
            'question_articles' => $this->getQuestionArticles($round),
        ]);
    }

    private function getQuestionArticles(Round $round): array
    {
        if (!$round->question_id) {
            return [];
        }
        return \DB::connection('data')
            ->table('question_articles')
            ->join('articles', 'question_articles.article_id', '=', 'articles.id')
            ->where('question_id', $round->question_id)
            ->get([
                'articles.title',
                'question_articles.views_total',
                'question_articles.views_avg_daily',
            ])
            ->toArray();
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer'],
            'semester' => ['nullable', 'string', 'in:S1,S2'],
            'duration_seconds' => ['nullable', 'integer', 'min:10', 'max:300'],
        ]);

        $question = $this->dataQuestionService->fetchQuestion(
            $validated['theme'] ?? null,
            $validated['year'] ?? null,
            $validated['semester'] ?? null,
        );

        if (!$question) {
            return response()->json(['message' => 'No question available with given filters'], 404);
        }

        $articles = collect($question['articles']);
        $correctOrder = $this->dataQuestionService->orderAscendingByPopularity($articles);
        $duration = isset($validated['duration_seconds']) && $validated['duration_seconds'] !== null
            ? (int) $validated['duration_seconds']
            : 60;

        $round = Round::create([
            'game_id' => $game->id,
            'question_id' => $question['id'],
            'status' => 'active',
            'theme' => $question['theme'],
            'year' => $question['year'],
            'semester' => $question['semester'],
            'articles' => $articles->pluck('title')->values()->all(),
            'correct_order' => $correctOrder,
            'deadline_at' => Carbon::now()->addSeconds($duration),
            'started_at' => Carbon::now(),
        ]);

        $game->update(['current_round_id' => $round->id, 'status' => 'running']);

        return response()->json($round, 201);
    }
}

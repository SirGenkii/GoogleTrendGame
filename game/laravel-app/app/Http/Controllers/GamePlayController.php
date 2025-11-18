<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Services\DataQuestionService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GamePlayController extends Controller
{
    public function __construct(private DataQuestionService $dataQuestionService)
    {
    }

    public function create(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host_name' => ['nullable', 'string', 'max:255'],
        ]);

        $code = strtoupper(str()->random(6));
        $game = Game::create([
            'code' => $code,
            'status' => 'waiting',
        ]);

        $host = Player::create([
            'game_id' => $game->id,
            'name' => $validated['host_name'] ?? 'Host',
            'is_host' => true,
        ]);

        $game->update(['host_player_id' => $host->id]);
        $request->session()->put('player_id', $host->id);

        return redirect()->route('games.show', $game->code);
    }

    public function join(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $game = Game::where('code', strtoupper($validated['code']))->firstOrFail();
        $player = Player::create([
            'game_id' => $game->id,
            'name' => $validated['name'],
            'is_host' => false,
        ]);
        $request->session()->put('player_id', $player->id);

        return redirect()->route('games.show', $game->code);
    }

    public function show(Game $game): View
    {
        $game->load(['players', 'rounds' => fn ($q) => $q->latest()->take(5), 'currentRound']);
        $playerId = session('player_id');
        $currentRound = $game->currentRound;
        $answers = [];
        $leaderboard = [];
        if ($currentRound) {
            $answers = Answer::with('player')->where('round_id', $currentRound->id)->get();
        }

        $roundIds = $game->rounds->pluck('id');
        if ($roundIds->isNotEmpty()) {
            $leaderboard = Answer::selectRaw('player_id, SUM(score) as total_score')
                ->whereIn('round_id', $roundIds)
                ->groupBy('player_id')
                ->with('player')
                ->orderByDesc('total_score')
                ->get();
        }

        return view('game', [
            'game' => $game,
            'currentRound' => $currentRound,
            'answers' => $answers,
            'playerId' => $playerId,
            'leaderboard' => $leaderboard,
        ]);
    }

    public function startRound(Request $request, Game $game): RedirectResponse
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
            return back()->with('error', 'Pas de question disponible avec ces filtres.');
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

        return redirect()->route('games.show', $game->code);
    }

    public function submitAnswer(Request $request, Round $round): RedirectResponse
    {
        $validated = $request->validate([
            'player_id' => ['required', 'exists:players,id'],
            'submitted_order' => ['required', 'array', 'min:1'],
        ]);

        if ($round->deadline_at && now()->greaterThan($round->deadline_at)) {
            return back()->with('error', 'Temps écoulé pour cette manche.');
        }

        $score = $this->scoreOrder($validated['submitted_order'], $round->correct_order ?? []);

        Answer::create([
            'round_id' => $round->id,
            'player_id' => $validated['player_id'],
            'submitted_order' => $validated['submitted_order'],
            'score' => $score,
            'submitted_at' => now(),
        ]);

        $this->finalizeRoundIfComplete($round);

        return redirect()->route('games.show', $round->game->code)
            ->with('message', "Réponse enregistrée (score: $score)");
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
        $scorePerPosition = 25;
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

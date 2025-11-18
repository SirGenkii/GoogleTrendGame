<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GameController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host_name' => ['nullable', 'string', 'max:255'],
        ]);

        $code = $this->generateCode();
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

        return response()->json([
            'game' => $game,
            'host_player' => $host,
        ], 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load(['players', 'rounds' => function ($q) {
            $q->latest();
        }]);
        return response()->json($game);
    }

    protected function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}

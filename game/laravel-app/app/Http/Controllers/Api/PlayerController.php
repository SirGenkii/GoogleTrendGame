<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function store(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $player = Player::create([
            'game_id' => $game->id,
            'name' => $validated['name'],
            'is_host' => false,
        ]);

        return response()->json($player, 201);
    }
}

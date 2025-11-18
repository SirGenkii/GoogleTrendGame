@extends('layouts.app')

@section('content')
    <div class="card">
        <div class="flex" style="align-items:center; justify-content: space-between;">
            <div>
                <h2>Partie {{ $game->code }}</h2>
                <div class="badge">Statut : {{ $game->status }}</div>
                <div class="badge">Joueurs : {{ $game->players->count() }}</div>
            </div>
            <div>
                <span class="muted">Code</span>
                <div class="badge" style="font-weight:700; font-size:1.1rem;">{{ $game->code }}</div>
            </div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h3>Joueurs</h3>
            <ul class="list" id="players-list" data-game-code="{{ $game->code }}">
                @foreach($game->players as $player)
                    <li class="list-item">
                        <span>{{ $player->name }} @if($player->is_host) <strong>(host)</strong> @endif</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="card">
            <h3>Démarrer une manche</h3>
            <form method="POST" action="{{ route('games.rounds.start', $game->code) }}">
                @csrf
                <label for="theme">Thème</label>
                <input type="text" id="theme" name="theme" placeholder="ex: Technologie (optionnel)" />
                <label for="year">Année</label>
                <input type="number" id="year" name="year" placeholder="2025" />
                <label for="semester">Semestre</label>
                <select id="semester" name="semester">
                    <option value="">-- Choisir --</option>
                    <option value="S1">S1</option>
                    <option value="S2">S2</option>
                </select>
                <label for="duration_seconds">Durée (secondes)</label>
                <input type="number" id="duration_seconds" name="duration_seconds" placeholder="60" value="60" min="10" max="300" />
                <button type="submit">Lancer</button>
            </form>
        </div>
    </div>

@if($currentRound)
        <div class="card" data-current-round-id="{{ $currentRound->id }}">
            <h3>Manche en cours</h3>
            <div class="badge">Thème : {{ $currentRound->theme }}</div>
            <div class="badge">Période : {{ $currentRound->year }} {{ $currentRound->semester }}</div>
            @if($currentRound->deadline_at)
                <div class="countdown" id="countdown" data-deadline="{{ $currentRound->deadline_at->toIso8601String() }}">Calcul...</div>
                <div class="badge" id="answer-status"></div>
            @endif
            <p class="muted">Classe les articles du moins au plus populaire (pageviews moyennes).</p>
            <ul class="list" data-sortable-list id="sortable-articles">
                @foreach($currentRound->articles as $article)
                    <li class="list-item" data-article="{{ $article }}">
                        <span>{{ $article }}</span>
                        <input type="hidden" name="submitted_order[]" value="{{ $article }}">
                    </li>
                @endforeach
            </ul>

            @if($playerId)
                <form id="answer-form" method="POST" action="{{ route('rounds.answers.submit', $currentRound->id) }}">
                    @csrf
                    <input type="hidden" name="player_id" value="{{ $playerId }}" />
                    <button type="submit">Soumettre l'ordre</button>
                </form>
            @else
                <p>Rejoins la partie pour répondre.</p>
            @endif
        </div>

        <div class="card">
            <h3>Réponses</h3>
            <ul id="answers-list">
                @foreach($answers as $answer)
                    <li>{{ $answer->player?->name ?? 'Player #'.$answer->player_id }} : {{ $answer->score }} pts</li>
                @endforeach
            </ul>
        </div>

    @if($currentRound->correct_order)
        <div class="card">
            <h3>Ordre réel (du moins au plus consulté)</h3>
            <ol id="correct-order">
                @foreach($currentRound->correct_order as $article)
                    <li>{{ $article }}</li>
                @endforeach
            </ol>
        </div>
    @endif
    <div class="card">
        <h3>Ta proposition</h3>
        <ol id="submission-list" class="muted"></ol>
    </div>
    <div class="card" id="results-card" style="display:none;">
        <h3>Résumé des réponses</h3>
        <div id="results-details"></div>
    </div>
@else
    <div class="card">Aucune manche en cours.</div>
@endif

    @if(!empty($leaderboard))
        <div class="card">
            <h3>Classement cumulé</h3>
            <ol id="leaderboard">
                @foreach($leaderboard as $entry)
                    <li>{{ $entry->player?->name ?? 'Player #'.$entry->player_id }} : {{ $entry->total_score }} pts</li>
                @endforeach
            </ol>
        </div>
    @endif
@endsection

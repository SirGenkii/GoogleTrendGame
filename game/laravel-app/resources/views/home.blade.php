@extends('layouts.app')

@section('content')
    <div class="grid grid-2">
        <div class="card">
            <h2>Créer une partie</h2>
            <p class="muted">Un code sera généré, partage-le pour inviter.</p>
            <form method="POST" action="{{ route('games.create') }}">
                @csrf
                <label for="host_name">Votre pseudo (host)</label>
                <input type="text" id="host_name" name="host_name" placeholder="Alice" />
                <button type="submit">Créer</button>
            </form>
        </div>

        <div class="card">
            <h2>Rejoindre une partie</h2>
            <form method="POST" action="{{ route('games.join') }}">
                @csrf
                <label for="code">Code de partie (6 lettres)</label>
                <input type="text" id="code" name="code" placeholder="ABC123" required />
                <label for="name">Votre pseudo</label>
                <input type="text" id="name" name="name" placeholder="Votre pseudo" required />
                <button type="submit">Rejoindre</button>
            </form>
        </div>
    </div>
@endsection

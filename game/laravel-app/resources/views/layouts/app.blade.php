<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wiki Battle</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        :root { color-scheme: light; }
        body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; background: #f3f4f6; margin: 0; padding: 0; color: #111827; }
        header { background: #0f172a; color: #fff; padding: 14px 16px; }
        .container { max-width: 1100px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; padding: 16px; margin-bottom: 16px; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        h1,h2,h3 { margin: 0 0 8px 0; }
        form { margin: 8px 0; }
        label { font-size: 0.9rem; color: #4b5563; display: block; margin-bottom: 4px; }
        input, select, button { padding: 10px 12px; margin-bottom: 10px; border-radius: 8px; border: 1px solid #e5e7eb; font-size: 1rem; outline: none; }
        input:focus, select:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.15); }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; transition: background 0.2s ease; }
        button:hover { background: #1d4ed8; }
        .btn-secondary { background: #f3f4f6; color: #111827; border: 1px solid #e5e7eb; }
        .badge { display: inline-block; padding: 6px 10px; background: #e5e7eb; border-radius: 999px; margin-right: 8px; font-size: 0.9rem; }
        .error { color: #b91c1c; }
        .success { color: #047857; }
        ul { padding-left: 18px; }
        .flex { display: flex; gap: 12px; flex-wrap: wrap; }
        .grid { display: grid; gap: 12px; }
        .grid-2 { grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); }
        .list { list-style: none; padding: 0; margin: 0; }
        .list-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; margin-bottom: 8px; background: #f9fafb; cursor: grab; display: flex; justify-content: space-between; align-items: center; }
        .countdown { font-weight: 600; color: #dc2626; }
        .topbar { display: flex; align-items: center; justify-content: space-between; }
        .muted { color: #6b7280; }
        .ended { border: 2px solid #22c55e; animation: flash 1.5s ease; }
        .flash { animation: pulse 1s ease; }
        .disabled { opacity: 0.6; pointer-events: none; }
        .correct { color: #16a34a; font-weight: 600; }
        .wrong { color: #dc2626; font-weight: 600; }
        @keyframes flash { 0% { background: #ecfdf3; } 100% { background: #fff; } }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
</head>
<body data-player-id="{{ $playerId ?? '' }}">
    <header>
        <div class="container">
            <div class="topbar">
                <strong>Wikipedia Popularity Battle</strong>
                <span class="muted">Classement d’articles par popularité (pageviews)</span>
            </div>
        </div>
    </header>
    <div class="container">
        @if(session('error'))
            <div class="card error">{{ session('error') }}</div>
        @endif
        @if(session('message'))
            <div class="card success">{{ session('message') }}</div>
        @endif
        @yield('content')
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            // Drag & drop for article ordering
            const sortableList = document.querySelector('[data-sortable-list]');
            let sortableInst = null;
            if (sortableList) {
                const updateInputs = () => {
                    sortableList.querySelectorAll('[data-article]').forEach((li, idx) => {
                        const input = li.querySelector('input[type="hidden"]');
                        if (input) {
                            input.name = `submitted_order[${idx}]`;
                            input.value = li.dataset.article;
                        }
                    });
                };
                updateInputs();
                sortableInst = new Sortable(sortableList, {
                    animation: 150,
                    onEnd: updateInputs,
                });
            }

            // Countdown
            const countdownEl = document.querySelector('[data-deadline]');
            if (countdownEl) {
                const deadline = new Date(countdownEl.dataset.deadline);
                if (!isNaN(deadline.getTime())) {
                    const tick = () => {
                        const diff = deadline - new Date();
                        if (diff <= 0) {
                            countdownEl.textContent = 'Temps écoulé';
                            return;
                        }
                        const s = Math.floor(diff / 1000);
                        const m = Math.floor(s / 60);
                        const rem = s % 60;
                        countdownEl.textContent = `${m}m ${rem}s restants`;
                        requestAnimationFrame(tick);
                    };
                    tick();
                } else {
                    countdownEl.textContent = 'En cours';
                }
            }

            // Polling for players / answers / leaderboard et détection de nouveau round
            const currentRoundWrapper = document.querySelector('[data-current-round-id]');
            const answersList = document.getElementById('answers-list');
            const leaderboardList = document.getElementById('leaderboard');
            const playersList = document.getElementById('players-list');
            const gameCode = playersList?.dataset.gameCode;
            const submissionList = document.getElementById('submission-list');
            const playerId = document.body.dataset.playerId || '';
            const resultsCard = document.getElementById('results-card');
            const resultsDetails = document.getElementById('results-details');

            const renderList = (el, items) => {
                if (!el) return;
                el.innerHTML = '';
                items.forEach((text) => {
                    const li = document.createElement('li');
                    li.textContent = text;
                    el.appendChild(li);
                });
            };

            if (currentRoundWrapper) {
                const roundId = currentRoundWrapper.dataset.currentRoundId;
                const poll = async () => {
                    try {
                        const r = await fetch(`/api/rounds/${roundId}`);
                        if (!r.ok) return;
                        const data = await r.json();
                        if (data.ended) {
                            currentRoundWrapper.classList.add('ended');
                        }
                        if (answersList && data.answers) {
                            renderList(answersList, data.answers.map(a => `${a.player?.name ?? 'Player #' + a.player_id} : ${a.score} pts (${a.matches}/4 bons)`));
                        }
                        if (leaderboardList && data.leaderboard) {
                            renderList(leaderboardList, data.leaderboard.map(l => `${l.player?.name ?? 'Player #' + l.player_id} : ${l.total_score} pts`));
                        }
                        const articlesMap = new Map((data.question_articles || []).map(qa => [qa.title, qa]));
                        if (data.round?.correct_order) {
                            const correctList = document.getElementById('correct-order');
                            if (correctList) {
                                correctList.innerHTML = '';
                                data.round.correct_order.forEach((a) => {
                                    const li = document.createElement('li');
                                    const detail = articlesMap.get(a);
                                    li.textContent = `${a} (${detail?.views_avg_daily ?? '?'} moy/j)`;
                                    li.className = 'correct';
                                    correctList.appendChild(li);
                                });
                            }
                        }
                        if ((data.ended || data.allPlayersAnswered) && countdownEl) {
                            countdownEl.textContent = data.ended ? 'Manche terminée' : 'Tous les joueurs ont répondu';
                            if (sortableInst) sortableInst.option('disabled', true);
                            document.querySelectorAll('[data-sortable-list] .list-item').forEach((li) => li.classList.add('disabled'));
                        }
                        if (submissionList && playerId && data.answers) {
                            const mine = data.answers.find(a => String(a.player_id) === String(playerId));
                            submissionList.innerHTML = '';
                            if (mine && mine.submitted_order && data.question_articles) {
                                mine.submitted_order.forEach((title, idx) => {
                                    const li = document.createElement('li');
                                    const correctTitle = data.round.correct_order?.[idx];
                                    const articleDetail = articlesMap.get(title);
                                    li.textContent = `${idx + 1}. ${title} (${articleDetail?.views_avg_daily ?? '?'} moy/j)`;
                                    li.className = correctTitle === title ? 'correct' : 'wrong';
                                    submissionList.appendChild(li);
                                });
                            }
                        }
                        if (resultsCard && resultsDetails && (data.ended || data.allPlayersAnswered) && data.answers) {
                            resultsCard.style.display = 'block';
                            resultsDetails.innerHTML = '';
                            data.answers.forEach((a) => {
                                const block = document.createElement('div');
                                block.style.marginBottom = '8px';
                                const title = document.createElement('div');
                                title.innerHTML = `<strong>${a.player?.name ?? 'Player #' + a.player_id}</strong> - ${a.score} pts`;
                                block.appendChild(title);
                                const ol = document.createElement('ol');
                                (a.submitted_order || []).forEach((title, idx) => {
                                    const li = document.createElement('li');
                                    const correctTitle = data.round.correct_order?.[idx];
                                    const articleDetail = articlesMap.get(title);
                                    li.textContent = `${idx + 1}. ${title} (${articleDetail?.views_avg_daily ?? '?'} moy/j)`;
                                    li.className = correctTitle === title ? 'correct' : 'wrong';
                                    ol.appendChild(li);
                                });
                                block.appendChild(ol);
                                resultsDetails.appendChild(block);
                            });
                        }
                    } catch (e) {
                        // ignore
                    }
                    setTimeout(poll, 2000);
                };

                poll();
            }

            const pollPlayers = async () => {
                if (!gameCode || !playersList) return;
                try {
                    const r = await fetch(`/api/games/${gameCode}`);
                    if (!r.ok) return;
                    const data = await r.json();
                    playersList.innerHTML = '';
                    data.players.forEach(p => {
                        const li = document.createElement('li');
                        li.className = 'list-item';
                        li.textContent = p.name + (p.is_host ? ' (host)' : '');
                        playersList.appendChild(li);
                    });
                    const currentId = currentRoundWrapper ? currentRoundWrapper.dataset.currentRoundId : null;
                    if (data.current_round_id && data.current_round_id != currentId) {
                        window.location.reload();
                    }
                } catch (e) {
                    // ignore
                }
                setTimeout(pollPlayers, 3000);
            };

            pollPlayers();

            // Ajax submit answer to avoid full reload
            const answerForm = document.getElementById('answer-form');
            if (answerForm) {
                answerForm.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData(answerForm);
                    fd.delete('submitted_order[]');
                    document.querySelectorAll('[data-sortable-list] [data-article]').forEach((li, idx) => {
                        fd.append(`submitted_order[${idx}]`, li.dataset.article);
                    });
                    try {
                        const resp = await fetch(answerForm.action, {
                            method: 'POST',
                            headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                            body: fd,
                        });
                        if (resp.ok) {
                            const btn = answerForm.querySelector('button');
                            if (btn) {
                                btn.classList.add('flash');
                                btn.disabled = true;
                            }
                            const status = document.getElementById('answer-status');
                            if (status) {
                                status.textContent = 'Réponse envoyée';
                                status.classList.add('success');
                            }
                            if (sortableInst) {
                                sortableInst.option('disabled', true);
                            }
                            document.querySelectorAll('[data-sortable-list] .list-item').forEach((li) => li.classList.add('disabled'));
                        } else {
                            const data = await resp.json().catch(() => ({}));
                            alert(data.message || 'Erreur lors de l’envoi (peut-être temps écoulé)');
                        }
                    } catch (err) {
                        alert('Erreur réseau');
                    }
                });
            }
        });
    </script>
</body>
</html>

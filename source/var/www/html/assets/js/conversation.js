(() => {
    'use strict';

    const cfg = window.SHASTA_CONVERSATION;
    const tokenElement = document.querySelector('meta[name="csrf-token"]');
    if (!cfg || !tokenElement) {
        console.error('Shasta Exchange conversation configuration is missing.');
        return;
    }

    const token = tokenElement.content;
    const list = document.getElementById('messageList');
    const status = document.getElementById('recordStatus');
    const recordBtn = document.getElementById('recordButton');
    const sendBtn = document.getElementById('sendButton');
    const timer = document.getElementById('recordTimer');
    const progress = document.getElementById('recordProgress');

    if (!list || !status || !recordBtn || !sendBtn || !timer || !progress) {
        console.error('Shasta Exchange conversation page is missing required elements.');
        return;
    }

    // Load the custom player stylesheet without requiring a conversation.php edit.
    if (!document.querySelector('link[data-shasta-player-css]')) {
        const playerCss = document.createElement('link');
        playerCss.rel = 'stylesheet';
        playerCss.href = '/assets/css/custom-audio-player.css';
        playerCss.dataset.shastaPlayerCss = 'true';
        document.head.appendChild(playerCss);
    }

    let conversationId = cfg.id;
    let recorder = null;
    let chunks = [];
    let blob = null;
    let stream = null;
    let started = 0;
    let tick = null;
    let busy = false;
    let activeAudio = null;

    const esc = s => String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));

    const formatTime = seconds => {
        if (!Number.isFinite(seconds) || seconds < 0) seconds = 0;
        const whole = Math.floor(seconds);
        return String(Math.floor(whole / 60)).padStart(2, '0') + ':' +
            String(whole % 60).padStart(2, '0');
    };

    function playerMarkup(m) {
        const id = Number(m.id);
        return `
            <div class="sx-player" data-player-id="${id}">
                <audio class="sx-audio" preload="metadata"
                       src="/api/audio.php?id=${id}"></audio>
                <button class="sx-play" type="button" data-action="play"
                        aria-label="Play voice message" title="Play">
                    <span aria-hidden="true">▶</span>
                </button>
                <div class="sx-player-main">
                    <div class="sx-player-title">Voice message</div>
                    <input class="sx-seek" type="range" min="0" max="100"
                           value="0" step="0.1" aria-label="Seek through voice message">
                    <div class="sx-player-time">
                        <span class="sx-current-time">00:00</span>
                        <span class="sx-duration">--:--</span>
                    </div>
                </div>
                <button class="sx-skip" type="button" data-action="back"
                        aria-label="Back 10 seconds" title="Back 10 seconds">−10</button>
                <button class="sx-skip" type="button" data-action="forward"
                        aria-label="Forward 10 seconds" title="Forward 10 seconds">+10</button>
                <button class="sx-speed" type="button" data-action="speed"
                        aria-label="Playback speed" title="Change playback speed">1×</button>
            </div>`;
    }

    function updatePlayer(player) {
        const audio = player.querySelector('.sx-audio');
        if (!audio) return;

        const seek = player.querySelector('.sx-seek');
        const current = player.querySelector('.sx-current-time');
        const duration = player.querySelector('.sx-duration');
        const playButton = player.querySelector('.sx-play');

        current.textContent = formatTime(audio.currentTime);
        duration.textContent = Number.isFinite(audio.duration)
            ? formatTime(audio.duration)
            : '--:--';

        if (Number.isFinite(audio.duration) && audio.duration > 0) {
            seek.value = String((audio.currentTime / audio.duration) * 100);
        } else {
            seek.value = '0';
        }

        const playing = !audio.paused && !audio.ended;
        playButton.innerHTML = playing
            ? '<span aria-hidden="true">Ⅱ</span>'
            : '<span aria-hidden="true">▶</span>';
        playButton.setAttribute('aria-label', playing ? 'Pause voice message' : 'Play voice message');
        playButton.title = playing ? 'Pause' : 'Play';
        player.classList.toggle('is-playing', playing);
    }

    function setupPlayers() {
        list.querySelectorAll('.sx-player').forEach(player => {
            const audio = player.querySelector('.sx-audio');
            if (!audio || audio.dataset.playerReady === 'true') return;
            audio.dataset.playerReady = 'true';

            audio.addEventListener('loadedmetadata', () => updatePlayer(player));
            audio.addEventListener('durationchange', () => updatePlayer(player));
            audio.addEventListener('timeupdate', () => updatePlayer(player));
            audio.addEventListener('play', () => updatePlayer(player));
            audio.addEventListener('pause', () => updatePlayer(player));
            audio.addEventListener('ended', () => {
                updatePlayer(player);
                if (activeAudio === audio) activeAudio = null;
            });
            audio.addEventListener('error', () => {
                const title = player.querySelector('.sx-player-title');
                if (title) title.textContent = 'Audio could not be loaded';
            });
        });
    }

    async function loadMessages() {
        // Do not rebuild the message list while a custom player is playing.
        // Rebuilding would interrupt playback every 10-second refresh.
        if (activeAudio && !activeAudio.paused && !activeAudio.ended) return;

        if (!conversationId) {
            list.innerHTML =
                '<p class="muted">This is a new conversation. Send the first voice message to create it.</p>';
            return;
        }

        const r = await fetch(
            '/api/get_messages.php?conversation_id=' + encodeURIComponent(conversationId),
            {
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        const d = await r.json();

        if (!r.ok) {
            throw new Error(d.error || 'Could not load messages');
        }

        list.innerHTML = d.messages.length
            ? d.messages.map(m => `
                <article class="message-row ${
                    Number(m.sender_id) === Number(cfg.userId) ? 'mine' : ''
                }">
                    <strong>${esc(m.sender_email)}</strong><br>
                    <small>
                        ${esc(m.created_at)} ·
                        ${m.duration_seconds
                            ? Number(m.duration_seconds).toFixed(1) + ' sec'
                            : 'audio'}
                    </small>
                    ${playerMarkup(m)}
                </article>
            `).join('')
            : '<p class="muted">No messages yet.</p>';

        setupPlayers();
        list.scrollTop = list.scrollHeight;

        const q = await fetch(
            '/api/get_next_message.php?conversation_id=' + encodeURIComponent(conversationId),
            {
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        const qd = await q.json();
        const queueCount = document.getElementById('queueCount');
        if (queueCount) {
            queueCount.textContent = qd.status === 'message' ? '1 new' : '0 new';
        }
    }

    // One delegated handler manages all custom player buttons.
    list.addEventListener('click', async event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;

        const player = button.closest('.sx-player');
        if (!player) return;

        const audio = player.querySelector('.sx-audio');
        if (!audio) return;

        const action = button.dataset.action;

        if (action === 'play') {
            if (audio.paused) {
                if (activeAudio && activeAudio !== audio) {
                    activeAudio.pause();
                }
                activeAudio = audio;
                try {
                    await audio.play();
                } catch (error) {
                    status.textContent = 'Could not play this message. Tap play to try again.';
                }
            } else {
                audio.pause();
            }
            updatePlayer(player);
        } else if (action === 'back') {
            audio.currentTime = Math.max(0, (audio.currentTime || 0) - 10);
            updatePlayer(player);
        } else if (action === 'forward') {
            const end = Number.isFinite(audio.duration) ? audio.duration : audio.currentTime + 10;
            audio.currentTime = Math.min(end, (audio.currentTime || 0) + 10);
            updatePlayer(player);
        } else if (action === 'speed') {
            const speeds = [1, 1.25, 1.5, 2, 0.75];
            const currentIndex = speeds.indexOf(audio.playbackRate);
            audio.playbackRate = speeds[(currentIndex + 1) % speeds.length];
            button.textContent = audio.playbackRate + '×';
        }
    });

    list.addEventListener('input', event => {
        if (!event.target.matches('.sx-seek')) return;
        const player = event.target.closest('.sx-player');
        const audio = player && player.querySelector('.sx-audio');
        if (!audio || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
        audio.currentTime = (Number(event.target.value) / 100) * audio.duration;
        updatePlayer(player);
    });

    async function ack(id) {
        const b = new URLSearchParams({ message_id: id });

        await fetch('/api/ack_message.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': token,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: b
        });
    }

    async function playQueue() {
        if (!conversationId || busy) return;

        busy = true;

        try {
            while (true) {
                const r = await fetch(
                    '/api/get_next_message.php?conversation_id=' + encodeURIComponent(conversationId),
                    {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }
                );

                const d = await r.json();
                if (!r.ok || d.status === 'empty') break;

                const a = new Audio(d.message.audio_url);
                status.textContent = 'Playing message from ' + d.message.sender_email;

                await new Promise(resolve => {
                    a.onended = resolve;
                    a.onerror = resolve;
                    a.play().catch(resolve);
                });

                await ack(d.message.id);
            }

            status.textContent = 'Message queue is empty.';
            await loadMessages();

        } catch (e) {
            status.textContent = e.message;
        } finally {
            busy = false;
        }
    }

    recordBtn.addEventListener('click', async () => {
        if (recorder && recorder.state === 'recording') {
            recorder.stop();
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // Keep the browser-native recording format for iPhone compatibility.
            recorder = new MediaRecorder(stream);
            chunks = [];
            started = Date.now();

            recorder.ondataavailable = event => {
                if (event.data.size > 0) chunks.push(event.data);
            };

            recorder.onstop = () => {
                clearInterval(tick);
                if (stream) stream.getTracks().forEach(track => track.stop());

                blob = new Blob(chunks, {
                    type: recorder.mimeType || 'application/octet-stream'
                });

                if (blob.size > 2 * 1024 * 1024) {
                    blob = null;
                    status.textContent = 'Recording exceeds the 2 MB upload limit.';
                    sendBtn.disabled = true;
                } else {
                    sendBtn.disabled = false;
                    status.textContent =
                        'Recording ready (' + Math.ceil(blob.size / 1024) + ' KB).';
                }

                recordBtn.textContent = '● Record again';
                recordBtn.classList.remove('recording');
            };

            recorder.start(250);
            recordBtn.textContent = '■ Stop recording';
            recordBtn.classList.add('recording');
            sendBtn.disabled = true;

            tick = setInterval(() => {
                const sec = Math.min(60, Math.floor((Date.now() - started) / 1000));
                timer.textContent = '00:' + String(sec).padStart(2, '0') + ' / 01:00';
                progress.style.width = (sec / 60 * 100) + '%';

                if (sec >= 60 && recorder.state === 'recording') recorder.stop();
            }, 200);

            status.textContent = 'Recording…';
        } catch (e) {
            status.textContent = e.message;
        }
    });

    sendBtn.addEventListener('click', async () => {
        if (!blob) return;

        sendBtn.disabled = true;
        status.textContent = 'Uploading…';

        const fd = new FormData();
        fd.append('audio', blob, 'voice-message');

        if (conversationId) {
            fd.append('conversation_id', conversationId);
        } else {
            fd.append('recipient_email', cfg.to);
        }

        try {
            const r = await fetch('/api/upload_message.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token },
                body: fd
            });

            const d = await r.json();
            if (!r.ok) throw new Error(d.error || 'Upload failed');

            conversationId = d.conversation_id;
            blob = null;
            status.textContent = 'Message sent.';
            timer.textContent = '00:00 / 01:00';
            progress.style.width = '0%';

            history.replaceState({}, '', '/conversation.php?id=' + conversationId);
            await loadMessages();
        } catch (e) {
            status.textContent = e.message;
            sendBtn.disabled = false;
        }
    });

    loadMessages()
        .then(playQueue)
        .catch(e => status.textContent = e.message);

    if (conversationId) {
        setInterval(() => {
            loadMessages().catch(() => {});
        }, 10000);
    }
})();


(() => {
    const cfg = window.SHASTA_CONVERSATION;
    const token = document.querySelector('meta[name="csrf-token"]').content;

    const list = document.getElementById('messageList');
    const status = document.getElementById('recordStatus');
    const recordBtn = document.getElementById('recordButton');
    const sendBtn = document.getElementById('sendButton');
    const timer = document.getElementById('recordTimer');
    const progress = document.getElementById('recordProgress');

    let conversationId = cfg.id;
    let recorder = null;
    let chunks = [];
    let blob = null;
    let stream = null;
    let started = 0;
    let tick = null;
    let busy = false;

    const esc = s => String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));

    async function loadMessages() {
        if (!conversationId) {
            list.innerHTML =
                '<p class="muted">This is a new conversation. Send the first voice message to create it.</p>';
            return;
        }

        const r = await fetch(
            '/api/get_messages.php?conversation_id=' + conversationId,
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
                    Number(m.sender_id) === Number(cfg.userId)
                        ? 'mine'
                        : ''
                }">
                    <strong>${esc(m.sender_email)}</strong><br>
                    <small>
                        ${esc(m.created_at)} ·
                        ${m.duration_seconds
                            ? Number(m.duration_seconds).toFixed(1) + ' sec'
                            : 'audio'}
                    </small>
                    <audio controls preload="none"
                           src="/api/audio.php?id=${Number(m.id)}"></audio>
                </article>
            `).join('')
            : '<p class="muted">No messages yet.</p>';

        list.scrollTop = list.scrollHeight;

        const q = await fetch(
            '/api/get_next_message.php?conversation_id=' + conversationId,
            {
                credentials: 'same-origin',
                cache: 'no-store'
            }
        );

        const qd = await q.json();

        document.getElementById('queueCount').textContent =
            qd.status === 'message' ? '1 new' : '0 new';
    }

    async function ack(id) {
        const b = new URLSearchParams({
            message_id: id
        });

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
        if (!conversationId || busy) {
            return;
        }

        busy = true;

        try {
            while (true) {
                const r = await fetch(
                    '/api/get_next_message.php?conversation_id=' + conversationId,
                    {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }
                );

                const d = await r.json();

                if (!r.ok || d.status === 'empty') {
                    break;
                }

                const a = new Audio(d.message.audio_url);

                status.textContent =
                    'Playing message from ' + d.message.sender_email;

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
            stream = await navigator.mediaDevices.getUserMedia({
                audio: true
            });

            // Use the browser's default supported recording format.
            // The PHP upload endpoint converts it to Ogg Opus.

            recorder = new MediaRecorder(stream);

            chunks = [];
            started = Date.now();

            recorder.ondataavailable = event => {
                if (event.data.size > 0) {
                    chunks.push(event.data);
                }
            };

            recorder.onstop = () => {
                clearInterval(tick);

                stream.getTracks().forEach(track => track.stop());

                blob = new Blob(chunks, {
                    type: recorder.mimeType || 'application/octet-stream'
                });

                if (blob.size > 2 * 1024 * 1024) {
                    blob = null;

                    status.textContent =
                        'Recording exceeds the 2 MB upload limit.';

                    sendBtn.disabled = true;

                } else {
                    sendBtn.disabled = false;

                    status.textContent =
                        'Recording ready (' +
                        Math.ceil(blob.size / 1024) +
                        ' KB).';
                }

                recordBtn.textContent = '● Record again';
                recordBtn.classList.remove('recording');
            };

            recorder.start(250);

            recordBtn.textContent = '■ Stop recording';
            recordBtn.classList.add('recording');

            sendBtn.disabled = true;

            tick = setInterval(() => {

                const sec = Math.min(
                    60,
                    Math.floor((Date.now() - started) / 1000)
                );

                timer.textContent =
                    '00:' + String(sec).padStart(2, '0') + ' / 01:00';

                progress.style.width = (sec / 60 * 100) + '%';

                if (sec >= 60 && recorder.state === 'recording') {
                    recorder.stop();
                }

            }, 200);

            status.textContent = 'Recording…';

        } catch (e) {
            status.textContent = e.message;
        }
    });

    sendBtn.addEventListener('click', async () => {

        if (!blob) {
            return;
        }

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
                headers: {
                    'X-CSRF-Token': token
                },
                body: fd
            });

            const d = await r.json();

            if (!r.ok) {
                throw new Error(d.error || 'Upload failed');
            }

            conversationId = d.conversation_id;
            blob = null;

            status.textContent = 'Message sent.';

            timer.textContent = '00:00 / 01:00';
            progress.style.width = '0%';

            history.replaceState(
                {},
                '',
                '/conversation.php?id=' + conversationId
            );

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

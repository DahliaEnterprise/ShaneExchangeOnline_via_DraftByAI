(() => {
  'use strict';
  const audio = document.getElementById('archivePlayer');
  const archiveList = document.getElementById('archiveList');
  const status = document.getElementById('archiveStatus');
  if (!audio || !archiveList) return;

  // All archived audio is played through this single HTMLAudioElement.
  audio.controls = false;
  audio.preload = 'metadata';
  audio.setAttribute('aria-label', 'Shasta Exchange master archive player');

  const player = document.createElement('section');
  player.className = 'radio-master-player';
  player.setAttribute('aria-label', 'Master media player');
  player.innerHTML = `
    <div class="master-player-heading">
      <div><span class="master-eyebrow">MASTER MEDIA PLAYER</span><strong id="masterTrackTitle">No recording selected</strong></div>
      <span class="master-player-state" id="masterPlayerState">READY</span>
    </div>
    <div class="master-controls">
      <button type="button" class="master-play" id="masterPlay" aria-label="Play">▶</button>
      <div class="master-timeline">
        <div class="master-time-row"><span id="masterCurrent">0:00</span><span id="masterDuration">0:00</span></div>
        <input id="masterSeek" class="master-seek" type="range" min="0" max="1000" value="0" step="1" aria-label="Seek recording">
      </div>
      <label class="master-volume"><span>VOL</span><input id="masterVolume" type="range" min="0" max="1" value="1" step="0.01" aria-label="Volume"></label>
    </div>
    <div class="master-enable-row">
      <span id="masterEnableMessage">If your browser blocks radio autoplay, enable audio once.</span>
      <button type="button" id="masterEnableAudio">ENABLE AUDIO</button>
    </div>`;
  audio.insertAdjacentElement('beforebegin', player);
  audio.classList.add('master-audio-source');

  const queueBox = document.createElement('section');
  queueBox.className = 'radio-local-queue';
  queueBox.innerHTML = `
    <div class="radio-queue-top"><span class="radio-queue-title">LOCAL PLAY QUEUE</span><span class="radio-queue-count" id="radioQueueCount">0 WAITING</span></div>
    <div class="radio-queue-list" id="radioQueueList"><div class="radio-queue-empty">New completed broadcasts will appear here automatically.</div></div>
    <div class="radio-queue-actions"><button type="button" id="radioPlayQueue" disabled>PLAY QUEUE</button><button type="button" id="radioClearQueue" disabled>CLEAR QUEUE</button></div>`;
  archiveList.insertAdjacentElement('beforebegin', queueBox);

  const $ = id => document.getElementById(id);
  const queue = [];
  const queuedIds = new Set();
  let activeId = null;
  let currentSource = null;
  let audioEnabled = false;
  let queueStartInProgress = false;

  function fmt(value) {
    if (!Number.isFinite(value) || value < 0) value = 0;
    const s = Math.floor(value);
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
  }
  function sync() {
    const duration = Number.isFinite(audio.duration) ? audio.duration : 0;
    $('masterCurrent').textContent = fmt(audio.currentTime);
    $('masterDuration').textContent = fmt(duration);
    $('masterSeek').value = duration ? Math.round(audio.currentTime / duration * 1000) : 0;
    $('masterPlay').textContent = audio.paused ? '▶' : 'Ⅱ';
    $('masterPlay').setAttribute('aria-label', audio.paused ? 'Play' : 'Pause');
    $('masterPlayerState').textContent = audio.error ? 'ERROR' :
      (audio.ended ? 'FINISHED' : (audio.paused ? (audio.src ? 'PAUSED' : 'READY') : 'PLAYING'));
  }
  function renderQueue() {
    const list = $('radioQueueList');
    list.replaceChildren();
    $('radioQueueCount').textContent = `${queue.length} WAITING`;
    $('radioPlayQueue').disabled = !queue.length;
    $('radioClearQueue').disabled = !queue.length;
    if (!queue.length) {
      const empty = document.createElement('div');
      empty.className = 'radio-queue-empty';
      empty.textContent = 'No recordings waiting. New broadcasts are checked automatically.';
      list.append(empty);
      return;
    }
    queue.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'radio-queue-item';
      const number = document.createElement('span');
      number.textContent = `${index + 1}.`;
      const title = document.createElement('strong');
      title.textContent = item.title;
      const from = document.createElement('small');
      from.textContent = item.email || 'Radio member';
      row.append(number, title, from);
      list.append(row);
    });
  }
  function setActiveButton(id) {
    archiveList.querySelectorAll('.archive-file').forEach(button => {
      const match = button.dataset.recordingId === String(id);
      button.classList.toggle('is-playing', match && !audio.paused);
      button.classList.toggle('is-queued', queuedIds.has(button.dataset.recordingId));
      const label = button.querySelector('.archive-start-label');
      if (label) label.textContent = match && !audio.paused ? 'NOW PLAYING' :
        (queuedIds.has(button.dataset.recordingId) ? 'QUEUED' : 'START PLAY');
    });
  }
  function addStartLabels() {
    archiveList.querySelectorAll('.archive-file').forEach(button => {
      if (!button.querySelector('.archive-start-label')) {
        const label = document.createElement('span');
        label.className = 'archive-start-label';
        label.textContent = 'START PLAY';
        button.append(label);
      }
      button.setAttribute('aria-label', `Start playing ${button.querySelector('.file-details strong')?.textContent || 'archived recording'}`);
      button.classList.toggle('is-queued', queuedIds.has(button.dataset.recordingId));
    });
  }
  function loadItem(item) {
    if (!item || !item.url) return;
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    activeId = String(item.id);
    currentSource = item;
    $('masterTrackTitle').textContent = item.title || 'Archived transmission';
    $('masterPlayerState').textContent = 'LOADING';
    audio.src = item.url;
    audio.load();
    setActiveButton(activeId);
  }
  async function startItem(item, fromQueue = false) {
    if (!item || !item.url) return false;
    loadItem(item);
    if (status) status.textContent = `${fromQueue ? 'Queued recording ready' : 'Selected recording'} from ${item.email || 'a radio member'}.`;
    try {
      // Call play directly; if browser policy rejects it, keep the item queued.
      await audio.play();
      $('masterEnableMessage').textContent = 'Audio is enabled for this page. New broadcasts will play automatically.';
      $('masterEnableAudio').textContent = 'AUDIO ENABLED';
      $('masterEnableAudio').disabled = true;
      sync();
      setActiveButton(activeId);
      return true;
    } catch (_) {
      $('masterPlayerState').textContent = 'TAP TO ENABLE';
      $('masterEnableMessage').textContent = 'Your browser blocked autoplay. Click ENABLE AUDIO once; this recording will stay queued.';
      $('masterEnableAudio').textContent = 'ENABLE AUDIO';
      $('masterEnableAudio').disabled = false;
      if (status) status.textContent = 'Playback is blocked by the browser. The recording has been kept in your local queue.';
      sync();
      return false;
    }
  }
  async function playNext() {
    if (queueStartInProgress || (audio.paused === false && !audio.ended)) return;
    const next = queue[0];
    if (!next) {
      renderQueue();
      return;
    }
    queueStartInProgress = true;
    try {
      const started = await startItem(next, true);
      if (started && queue.length && String(queue[0].id) === String(next.id)) {
        queue.shift();
        queuedIds.delete(String(next.id));
        renderQueue();
      }
      // If autoplay was blocked, do not shift. The ENABLE AUDIO click retries
      // this exact first item, then later items continue in order.
    } finally {
      queueStartInProgress = false;
    }
  }
  function pruneQueue(recordings) {
    const valid = new Set(recordings.map(item => String(item.id)));
    for (let i = queue.length - 1; i >= 0; i--) {
      if (!valid.has(String(queue[i].id))) {
        queuedIds.delete(String(queue[i].id));
        queue.splice(i, 1);
      }
    }
    renderQueue();
  }

  // Initial archive contents are not replayed: only files discovered after page
  // startup are considered new and automatically added to this tab's local queue.
  window.addEventListener('shasta:archive-update', event => {
    const detail = event.detail || {};
    const recordings = Array.isArray(detail.recordings) ? detail.recordings : [];
    pruneQueue(recordings);
    const fresh = Array.isArray(detail.newlyReady) ? detail.newlyReady.slice() : [];
    // API list is newest-first; play the newly discovered items oldest-first.
    fresh.sort((a, b) => {
      const ta = new Date(String(a.ended_at).replace(' ', 'T') + 'Z').getTime() || 0;
      const tb = new Date(String(b.ended_at).replace(' ', 'T') + 'Z').getTime() || 0;
      return ta - tb || Number(a.id) - Number(b.id);
    });
    fresh.forEach(rec => {
      const id = String(rec.id);
      if (queuedIds.has(id) || id === activeId) return;
      const item = {
        id,
        url: `${window.SHASTA_RADIO.api}?action=audio&id=${encodeURIComponent(id)}`,
        title: `Broadcast_${String(id).padStart(5, '0')}.webm`,
        email: rec.email || 'Radio member'
      };
      queue.push(item);
      queuedIds.add(id);
    });
    renderQueue();
    addStartLabels();
    if (fresh.length && status) {
      status.textContent = `${fresh.length} new recording${fresh.length === 1 ? '' : 's'} added to the local play queue.`;
    }
    // Start automatically if idle. If browser autoplay policy blocks it,
    // the queue remains intact and the visible PLAY QUEUE button can be used.
    if (queue.length && (audio.paused || audio.ended) && !audio.src) playNext();
    else if (queue.length && !currentSource && audio.paused) playNext();
  });

  window.addEventListener('shasta:archive-select', event => {
    const item = event.detail;
    if (!item) return;
    const id = String(item.id);
    const queuedIndex = queue.findIndex(entry => String(entry.id) === id);
    if (queuedIndex >= 0) {
      queue.splice(queuedIndex, 1);
      queuedIds.delete(id);
      renderQueue();
    }
    startItem(item, false);
  });

  async function enableAudioAndResume() {
    audioEnabled = true;
    $('masterEnableAudio').disabled = true;
    $('masterEnableMessage').textContent = 'Enabling audio…';
    if (audio.src && currentSource) {
      try {
        await audio.play();
        $('masterEnableMessage').textContent = 'Audio is enabled for this page. New broadcasts will play automatically.';
        $('masterEnableAudio').textContent = 'AUDIO ENABLED';
        sync();
        // If the current source is the first queued item, remove it only after
        // play() succeeds.
        if (queue.length && String(queue[0].id) === String(currentSource.id)) {
          queue.shift();
          queuedIds.delete(String(currentSource.id));
          renderQueue();
        }
        return;
      } catch (_) {
        $('masterEnableAudio').disabled = false;
        $('masterEnableMessage').textContent = 'Playback is still blocked. Check the browser tab/site sound settings and try again.';
        return;
      }
    }
    if (queue.length) {
      $('masterEnableAudio').disabled = false;
      await playNext();
    } else {
      $('masterEnableMessage').textContent = 'Audio is enabled. New broadcasts will play automatically.';
      $('masterEnableAudio').textContent = 'AUDIO ENABLED';
      $('masterEnableAudio').disabled = true;
    }
  }
  $('masterEnableAudio').addEventListener('click', enableAudioAndResume);
  $('masterPlay').addEventListener('click', async () => {
    if (!audio.src && queue.length) return enableAudioAndResume();
    if (!audio.src) {
      if (status) status.textContent = 'Wait for a new recording or select one from the archive.';
      return;
    }
    if (audio.paused) {
      try {
        await audio.play();
        $('masterEnableMessage').textContent = 'Audio is enabled for this page. New broadcasts will play automatically.';
        $('masterEnableAudio').textContent = 'AUDIO ENABLED';
        $('masterEnableAudio').disabled = true;
        if (queue.length && currentSource && String(queue[0].id) === String(currentSource.id)) {
          queue.shift();
          queuedIds.delete(String(currentSource.id));
          renderQueue();
        }
      } catch (_) {
        $('masterEnableAudio').disabled = false;
        $('masterEnableMessage').textContent = 'Click ENABLE AUDIO to allow playback.';
      }
    } else audio.pause();
  });
  $('radioPlayQueue').addEventListener('click', async () => {
    if (audio.src && currentSource && audio.paused) {
      try {
        await audio.play();
        if (queue.length && String(queue[0].id) === String(currentSource.id)) {
          queue.shift();
          queuedIds.delete(String(currentSource.id));
          renderQueue();
        }
      } catch (_) {
        $('masterEnableAudio').disabled = false;
        $('masterEnableMessage').textContent = 'Click ENABLE AUDIO to allow playback.';
      }
    } else {
      await playNext();
    }
  });
  $('radioClearQueue').addEventListener('click', () => {
    queue.splice(0, queue.length);
    queuedIds.clear();
    renderQueue();
    addStartLabels();
    if (status) status.textContent = 'Local play queue cleared.';
  });
  $('masterSeek').addEventListener('input', event => {
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
      audio.currentTime = Number(event.target.value) / 1000 * audio.duration;
    }
  });
  $('masterVolume').addEventListener('input', event => {
    audio.volume = Number(event.target.value);
    audio.muted = false;
  });
  audio.addEventListener('loadedmetadata', () => {
    if (currentSource) $('masterTrackTitle').textContent = currentSource.title;
    sync();
  });
  audio.addEventListener('play', () => {
    sync();
    setActiveButton(activeId);
  });
  audio.addEventListener('pause', () => {
    sync();
    setActiveButton(activeId);
  });
  audio.addEventListener('ended', () => {
    sync();
    setActiveButton(null);
    currentSource = null;
    activeId = null;
    playNext();
  });
  audio.addEventListener('error', () => {
    sync();
    if (status) status.textContent = 'A queued recording could not be loaded; moving to the next item.';
    currentSource = null;
    activeId = null;
    if (queue.length) playNext();
  });
  ['timeupdate','durationchange','waiting','emptied'].forEach(name => audio.addEventListener(name, sync));
  audio.volume = 1;
  addStartLabels();
  renderQueue();
  sync();
})();

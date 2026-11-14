(() => {
'use strict';
const cfg = window.SHASTA_RADIO;
const $ = id => document.getElementById(id);
const state = {
  stream: null, transmitting: false, inside: false, gps: null,
  peers: new Map(), activeId: null, muted: false, lastPoll: null,
  recorder: null, chunks: [], recordingStarted: 0, recordingTimer: null,
  archivePlayerId: null, stopped: false
};
const api = async (action, data = {}, method = 'POST') => {
  const body = new FormData();
  body.append('action', action);
  Object.entries(data).forEach(([k,v]) => body.append(k, v));
  const response = await fetch(cfg.api, {
    method, body, credentials: 'same-origin',
    headers: {'X-CSRF-Token': cfg.csrf}
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(json.error || `Radio request failed (${response.status})`);
  return json;
};
function notice(message, error = false) {
  $('radioNotice').textContent = message || '';
  $('radioNotice').classList.toggle('error', !!error);
}
function gpsPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('This browser does not support GPS location.'));
    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true, timeout: 15000, maximumAge: 5000
    });
  });
}
async function updateGps() {
  try {
    const pos = await gpsPosition();
    state.gps = {lat:pos.coords.latitude, lon:pos.coords.longitude, accuracy:pos.coords.accuracy};
    const result = await api('heartbeat', {...state.gps, transmitting: state.transmitting ? 1 : 0});
    state.inside = !!result.inside;
    $('gpsBadge').textContent = state.inside ? 'INSIDE COUNTY' : 'OUTSIDE COUNTY';
    $('gpsBadge').className = `status-badge ${state.inside ? 'status-good' : 'status-warn'}`;
    $('locationMessage').textContent = state.inside
      ? `Location verified. GPS accuracy approximately ${Math.round(state.gps.accuracy)} meters.`
      : 'You can listen and replay, but transmitting is available only inside Shasta County.';
    $('transmitButton').disabled = !state.inside && !state.transmitting;
    if (!state.inside && state.transmitting) await stopTransmit('You left the permitted broadcast area.');
  } catch (e) {
    state.inside = false;
    $('gpsBadge').textContent = 'LOCATION UNAVAILABLE';
    $('gpsBadge').className = 'status-badge status-warn';
    $('locationMessage').textContent = e.message || 'Location permission is required to transmit.';
    $('transmitButton').disabled = true;
    if (state.transmitting) await stopTransmit('Location verification was lost.');
  }
}
async function startTransmit() {
  if (!state.inside || !state.gps) return notice('You must verify your location inside Shasta County first.', true);
  try {
    state.stream = await navigator.mediaDevices.getUserMedia({audio:{
      echoCancellation:true, noiseSuppression:true, autoGainControl:true
    }});
    for (const entry of state.peers.values()) {
      if (entry.audioTransceiver) await entry.audioTransceiver.sender.replaceTrack(state.stream.getAudioTracks()[0] || null);
    }
    state.transmitting = true;
    const result = await api('heartbeat', {...state.gps, transmitting:1});
    if (!result.inside) throw new Error(result.error || 'County location verification failed.');
    $('transmitStatus').textContent = 'Transmitting';
    $('transmitButtonText').textContent = 'STOP TRANSMITTING';
    $('transmitButton').classList.add('is-transmitting');
    $('transmitButton').setAttribute('aria-pressed','true');
    $('transmitButton').disabled = false;
    notice('Your microphone is active. The newest active transmitter has priority.');
    await poll();
  } catch (e) {
    state.transmitting = false;
    if (state.stream) state.stream.getTracks().forEach(t=>t.stop());
    state.stream = null;
    $('transmitStatus').textContent = 'Standby';
    $('transmitButtonText').textContent = 'TAP TO TRANSMIT';
    $('transmitButton').classList.remove('is-transmitting');
    $('transmitButton').setAttribute('aria-pressed','false');
    notice(e.message, true);
  }
}
async function stopTransmit(message = '') {
  state.transmitting = false;
  try {
    if (state.gps) await api('heartbeat', {...state.gps, transmitting:0});
  } catch (_) {}
  if (state.recorder && state.recorder.state !== 'inactive') state.recorder.stop();
  for (const entry of state.peers.values()) {
    if (entry.audioTransceiver) entry.audioTransceiver.sender.replaceTrack(null).catch(()=>{});
  }
  if (state.stream) state.stream.getTracks().forEach(t=>t.stop());
  state.stream = null;
  $('transmitStatus').textContent = 'Standby';
  $('transmitButtonText').textContent = 'TAP TO TRANSMIT';
  $('transmitButton').classList.remove('is-transmitting');
  $('transmitButton').setAttribute('aria-pressed','false');
  $('transmitButton').disabled = !state.inside;
  if (message) notice(message, true);
}
function peerFor(id) {
  id = Number(id);
  if (state.peers.has(id)) return state.peers.get(id);
  const pc = new RTCPeerConnection({
    iceServers: [
      {urls:'stun:stun.l.google.com:19302'},
      {urls:'stun:stun1.l.google.com:19302'}
      // Add your TURN service credentials here for reliable connections across restrictive NATs.
    ]
  });
  const entry = {pc, audio:null, makingOffer:false};
  state.peers.set(id, entry);
  // Negotiate a bidirectional audio transceiver even when this member is only listening.
  // The sender track can be attached later without a second offer/answer negotiation.
  const audioTransceiver = pc.addTransceiver('audio', {direction:'sendrecv'});
  entry.audioTransceiver = audioTransceiver;
  if (state.stream) audioTransceiver.sender.replaceTrack(state.stream.getAudioTracks()[0] || null).catch(()=>{});
  pc.ontrack = event => {
    let audio = entry.audio;
    if (!audio) {
      audio = document.createElement('audio');
      audio.autoplay = true;
      audio.playsInline = true;
      $('remoteAudio').appendChild(audio);
      entry.audio = audio;
    }
    audio.srcObject = event.streams[0] || new MediaStream([event.track]);
    audio.muted = state.muted || Number(state.activeId) !== id;
    audio.play().catch(()=>{});
  };
  pc.onicecandidate = event => {
    if (event.candidate) sendSignal(id,'ice',JSON.stringify(event.candidate));
  };
  pc.onconnectionstatechange = () => {
    if (['failed','closed'].includes(pc.connectionState)) removePeer(id);
  };
  // The lower user ID initiates the offer, preventing offer glare.
  if (Number(cfg.userId) < id) makeOffer(id, entry);
  return entry;
}
async function makeOffer(id, entry) {
  if (entry.makingOffer) return;
  try {
    entry.makingOffer = true;
    const offer = await entry.pc.createOffer();
    await entry.pc.setLocalDescription(offer);
    await sendSignal(id,'offer',JSON.stringify(entry.pc.localDescription));
  } catch (e) { console.warn('Offer failed',e); }
  finally { entry.makingOffer = false; }
}
async function sendSignal(to,type,payload) {
  try { await api('signal',{to,type,payload}); } catch (e) { console.warn(e.message); }
}
function removePeer(id) {
  const entry = state.peers.get(Number(id));
  if (!entry) return;
  try { entry.pc.close(); } catch (_) {}
  if (entry.audio) entry.audio.remove();
  state.peers.delete(Number(id));
}
async function handleSignals() {
  const result = await api('signals');
  for (const signal of result.signals || []) {
    const id = Number(signal.from_user_id);
    const entry = peerFor(id);
    try {
      const payload = JSON.parse(signal.payload || '{}');
      if (signal.signal_type === 'offer') {
        await entry.pc.setRemoteDescription(payload);
        if (state.stream) state.stream.getTracks().forEach(track=>{
          if (!entry.pc.getSenders().some(s=>s.track===track)) entry.pc.addTrack(track,state.stream);
        });
        const answer = await entry.pc.createAnswer();
        await entry.pc.setLocalDescription(answer);
        await sendSignal(id,'answer',JSON.stringify(entry.pc.localDescription));
      } else if (signal.signal_type === 'answer') {
        if (entry.pc.signalingState === 'have-local-offer') await entry.pc.setRemoteDescription(payload);
      } else if (signal.signal_type === 'ice') {
        try { await entry.pc.addIceCandidate(payload); } catch (_) {}
      } else if (signal.signal_type === 'hangup') removePeer(id);
    } catch (e) { console.warn('Signal handling failed',e); }
  }
}
async function updateArchiveSegment(activeId) {
  const iAmAudible = state.transmitting && Number(activeId) === Number(cfg.userId);
  if (iAmAudible && (!state.recorder || state.recorder.state === 'inactive')) {
    if (!state.stream) return;
    try {
      const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : 'audio/webm';
      state.chunks = [];
      state.recordingStarted = Date.now();
      state.recorder = new MediaRecorder(state.stream,{mimeType:mime});
      state.recorder.ondataavailable = e => { if (e.data && e.data.size) state.chunks.push(e.data); };
      state.recorder.onstop = uploadSegment;
      state.recorder.start(1000);
    } catch (e) { console.warn('Recording unavailable',e); }
  } else if (!iAmAudible && state.recorder && state.recorder.state !== 'inactive') {
    state.recorder.stop();
  }
}
async function uploadSegment() {
  const blob = new Blob(state.chunks,{type:'audio/webm'});
  if (!blob.size) return;
  const duration = (Date.now()-state.recordingStarted)/1000;
  const data = new FormData();
  data.append('action','upload');
  data.append('audio',blob,`radio-${Date.now()}.webm`);
  data.append('duration',duration.toFixed(2));
  data.append('started_at',Math.floor(state.recordingStarted/1000));
  try {
    const response = await fetch(cfg.api,{method:'POST',body:data,credentials:'same-origin',headers:{'X-CSRF-Token':cfg.csrf}});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Archive upload failed');
    loadArchive();
  } catch (e) { notice(`Could not save archive segment: ${e.message}`,true); }
  state.chunks = [];
}
function renderPeople(people, activeId) {
  const list = $('speakerList');
  $('transmitterCount').textContent = people.filter(p=>p.transmitting_since).length;
  $('listenerCount').textContent = people.filter(p=>!p.transmitting_since).length;
  if (!people.length) { list.innerHTML='<p class="empty-state">No other radio members are online.</p>'; return; }
  list.replaceChildren();
  people.forEach(p=>{
    const row = document.createElement('div'); row.className='speaker-row';
    const left = document.createElement('div'); left.className='speaker-person';
    const avatar = document.createElement('span'); avatar.className='avatar'; avatar.textContent='♪';
    const details = document.createElement('span'); details.className='speaker-details';
    const name = document.createElement('strong'); name.textContent=p.email;
    const status = document.createElement('small'); status.textContent=Number(p.user_id)===Number(activeId)?'Currently audible':(p.transmitting_since?'Transmitting — waiting':'Listening');
    details.append(name,status); left.append(avatar,details);
    const badge = document.createElement('span'); badge.className='status-badge '+(Number(p.user_id)===Number(activeId)?'status-good':'status-wait');
    badge.textContent=Number(p.user_id)===Number(activeId)?'ON AIR':(p.transmitting_since?'QUEUED':'LISTENING');
    row.append(left,badge); list.append(row);
  });
}
async function poll() {
  try {
    const result = await api('poll');
    state.lastPoll = result;
    state.activeId = result.active_user_id;
    $('connectionBadge').textContent='CONNECTED';
    $('connectionBadge').className='status-badge status-good';
    renderPeople(result.people || [], result.active_user_id);
    for (const p of result.peers || []) {
      const id = Number(p.user_id);
      if (!state.peers.has(id)) peerFor(id);
    }
    const online = new Set((result.peers || []).map(p=>Number(p.user_id)));
    for (const id of state.peers.keys()) if (!online.has(id)) removePeer(id);
    for (const [id,entry] of state.peers) if (entry.audio) {
      entry.audio.muted = state.muted || Number(result.active_user_id)!==id;
      if (!entry.audio.muted) entry.audio.play().catch(()=>{});
    }
    await updateArchiveSegment(result.active_user_id);
    await handleSignals();
  } catch (e) {
    $('connectionBadge').textContent='RECONNECTING';
    $('connectionBadge').className='status-badge status-warn';
    console.warn(e);
  }
}
async function loadArchive() {
  try {
    const result = await api('archive');
    const list = $('archiveList');
    list.replaceChildren();
    $('archiveCount').textContent=`${result.recordings.length} files`;
    if (!result.recordings.length) {
      const empty=document.createElement('p'); empty.className='empty-state'; empty.textContent='No completed broadcasts in the last 30 minutes.';
      list.append(empty); return;
    }
    result.recordings.forEach(rec=>{
      const button=document.createElement('button'); button.type='button'; button.className='archive-file';
      const icon=document.createElement('span'); icon.className='file-icon'; icon.textContent='♫';
      const details=document.createElement('span'); details.className='file-details';
      const title=document.createElement('strong'); title.textContent=`Broadcast_${String(rec.id).padStart(5,'0')}.webm`;
      const sub=document.createElement('small'); sub.textContent=`${rec.email} · ${new Date(rec.ended_at.replace(' ','T')+'Z').toLocaleTimeString([], {hour:'numeric',minute:'2-digit'})}`;
      const duration=document.createElement('span'); duration.className='file-duration'; duration.textContent=`${Math.floor(rec.duration_seconds/60)}:${String(Math.floor(rec.duration_seconds%60)).padStart(2,'0')}`;
      details.append(title,sub); button.append(icon,details,duration);
      button.addEventListener('click',()=>{
        $('archivePlayer').src=`${cfg.api}?action=audio&id=${encodeURIComponent(rec.id)}`;
        $('archivePlayer').play().catch(()=>{});
        $('archiveStatus').textContent=`Playing recording from ${rec.email}.`;
      });
      list.append(button);
    });
  } catch (e) { $('archiveStatus').textContent=e.message; }
}
$('transmitButton').addEventListener('click',()=>state.transmitting?stopTransmit():startTransmit());
$('muteButton').addEventListener('click',()=>{
  state.muted=!state.muted;
  $('muteButton').textContent=state.muted?'Unmute audio':'Mute audio';
  for (const [id,entry] of state.peers) if(entry.audio) entry.audio.muted=state.muted || Number(state.activeId)!==id;
});
window.addEventListener('beforeunload',()=>{
  state.stopped=true;
  if(state.stream) state.stream.getTracks().forEach(t=>t.stop());
  for(const id of state.peers.keys()) removePeer(id);
});
async function boot() {
  if (!navigator.mediaDevices || !window.RTCPeerConnection || !window.MediaRecorder) {
    notice('This browser does not support the required live audio features. Use a current version of Chrome, Firefox, Safari, or Edge.',true);
    return;
  }
  $('transmitButtonText').textContent='CHECKING LOCATION';
  await updateGps();
  await poll();
  await loadArchive();
  setInterval(()=>{ if(!state.stopped) updateGps(); },10000);
  setInterval(()=>{ if(!state.stopped) poll(); },2000);
  setInterval(()=>{ if(!state.stopped) loadArchive(); },15000);
}
boot();
})();

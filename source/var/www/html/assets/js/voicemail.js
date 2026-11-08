(() => {
  const token = document.querySelector('meta[name="csrf-token"]').content;
  const list = document.getElementById('conversationList');
  const notice = document.getElementById('notice');
  const dialog = document.getElementById('newDialog');
  const form = document.getElementById('newConversationForm');
  const emailInput = document.getElementById('recipientEmail');
  const error = document.getElementById('dialogError');
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function load() {
    try {
      const r = await fetch('/api/get_conversations.php', {credentials:'same-origin',cache:'no-store'});
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Could not load conversations');
      if (!data.conversations.length) {
        list.innerHTML = '<p class="muted">No conversations yet. Select “New conversation” to get started.</p>';
        return;
      }
      list.innerHTML = data.conversations.map(c => `
        <a class="conversation-item" href="/conversation.php?id=${Number(c.id)}">
          <span class="avatar">${esc((c.other_email || '?').slice(0,1).toUpperCase())}</span>
          <span class="conversation-meta"><strong>${esc(c.other_email)}</strong><small>${c.latest_message_at ? 'Last message: '+esc(c.latest_message_at) : 'No messages yet'}</small></span>
          ${Number(c.unread_count)>0 ? `<span class="count">${Number(c.unread_count)} new</span>` : ''}
        </a>`).join('');
    } catch(e) { notice.textContent = e.message; }
  }
  document.getElementById('newConversation').addEventListener('click',()=>{error.textContent='';emailInput.value='';dialog.showModal();emailInput.focus();});
  document.getElementById('closeDialog').addEventListener('click',()=>dialog.close());
  document.getElementById('cancelDialog').addEventListener('click',()=>dialog.close());
  form.addEventListener('submit', async e => {
    e.preventDefault(); error.textContent='';
    const body=new URLSearchParams({email:emailInput.value});
    try {
      const r=await fetch('/api/create_conversation.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':token,'Content-Type':'application/x-www-form-urlencoded'},body});
      const d=await r.json(); if(!r.ok) throw new Error(d.error||'Could not start conversation');
      location.href='/conversation.php?to='+encodeURIComponent(d.email);
    } catch(err) { error.textContent=err.message; }
  });
  load(); setInterval(load,7000);
})();

const $ = id => document.getElementById(id);
let context = null;

function message(text, type = 'error') {
  $('message').textContent = text;
  $('message').className = type === 'success' ? 'ls-success' : 'ls-error';
}
function clearMessage() { $('message').className = 'hidden'; }
async function send(action, data) {
  const result = await chrome.runtime.sendMessage({action, data});
  if (!result?.ok) throw new Error(result?.error || 'Extension request failed.');
  return result.data;
}
async function render(nextContext) {
  context = nextContext;
  const stored = await chrome.storage.local.get(['leadspaceWorkspaceId', 'leadspaceListId']);
  $('signedout').classList.add('hidden'); $('signedin').classList.remove('hidden');
  $('connection').textContent = 'Connected'; $('userName').textContent = context.user.name;
  $('workspace').innerHTML = context.workspaces.map(workspace => `<option value="${workspace.id}">${workspace.name}</option>`).join('');
  const selectedId = context.workspaces.some(item => item.id === Number(stored.leadspaceWorkspaceId)) ? Number(stored.leadspaceWorkspaceId) : context.workspaces[0]?.id;
  if (selectedId) $('workspace').value = String(selectedId);
  await renderWorkspace(stored.leadspaceListId);
}
async function renderWorkspace(preferredListId) {
  const workspace = context.workspaces.find(item => item.id === Number($('workspace').value));
  if (!workspace) return;
  $('plan').textContent = `${workspace.plan.name} · ${workspace.subscription.status}`;
  $('usage').textContent = `${workspace.usage.leads.toLocaleString()} / ${workspace.usage.limit.toLocaleString()} leads · ${workspace.access.allowed ? 'Extraction enabled' : workspace.access.reason}`;
  $('list').innerHTML = workspace.lists.length ? workspace.lists.map(list => `<option value="${list.id}">${list.name}</option>`).join('') : '<option value="">Create a Lead List in the dashboard</option>';
  if (workspace.lists.some(item => item.id === Number(preferredListId))) $('list').value = String(preferredListId);
  await chrome.storage.local.set({leadspaceWorkspaceId: workspace.id, leadspaceListId: Number($('list').value) || null});
}
async function load() {
  clearMessage();
  try { await render(await send('leadspaceContext')); }
  catch (error) { $('signedin').classList.add('hidden'); $('signedout').classList.remove('hidden'); $('connection').textContent = 'Not connected'; if (!/Connect the extension/.test(error.message)) message(error.message); }
}
$('connect').addEventListener('click', async () => { clearMessage(); $('connect').disabled = true; try { await render(await send('leadspaceConnect')); message('Extension connected securely.', 'success'); } catch (error) { message(error.message); } finally { $('connect').disabled = false; } });
$('refresh').addEventListener('click', load);
$('disconnect').addEventListener('click', async () => { await send('leadspaceDisconnect'); load(); });
$('workspace').addEventListener('change', () => renderWorkspace(null));
$('list').addEventListener('change', () => chrome.storage.local.set({leadspaceListId: Number($('list').value) || null}));
$('search').addEventListener('click', async () => { try { await send('leadspaceCanExtract'); const query = $('query').value.trim(); if (query) chrome.tabs.create({url: `https://www.google.com/maps/search/${encodeURIComponent(query)}`}); } catch (error) { message(error.message); } });
load();

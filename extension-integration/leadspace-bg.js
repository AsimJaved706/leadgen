const LEADSPACE_ORIGIN = 'https://leads.diligenttechnologies.co';

async function leadspaceStored() {
  return chrome.storage.local.get(['leadspaceToken', 'leadspaceTokenExpiresAt', 'leadspaceWorkspaceId', 'leadspaceListId']);
}

async function leadspaceApi(path, options = {}) {
  const stored = await leadspaceStored();
  if (!stored.leadspaceToken) throw new Error('Connect the extension to Leadspace first.');
  const response = await fetch(`${LEADSPACE_ORIGIN}/api${path}`, {
    ...options,
    headers: {Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${stored.leadspaceToken}`, ...(options.headers || {})}
  });
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) {
    await chrome.storage.local.remove(['leadspaceToken', 'leadspaceTokenExpiresAt', 'leadspaceContext']);
    throw new Error('Your extension session expired. Connect again.');
  }
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Leadspace request failed.');
  return data;
}

async function refreshLeadspaceContext() {
  const context = await leadspaceApi('/extension/context');
  await chrome.storage.local.set({leadspaceContext: context, leadspaceContextCheckedAt: Date.now()});
  return context;
}

async function selectedWorkspace() {
  const stored = await leadspaceStored();
  const context = await refreshLeadspaceContext();
  const workspace = context.workspaces.find(item => item.id === Number(stored.leadspaceWorkspaceId)) || context.workspaces[0];
  if (!workspace) throw new Error('No workspace is available for this account.');
  if (!workspace.access.allowed) throw new Error(workspace.access.reason || 'This workspace cannot use the extension.');
  if (workspace.usage.leads >= workspace.usage.limit) throw new Error('The workspace lead storage limit has been reached.');
  return {stored, context, workspace};
}

async function connectLeadspace() {
  const redirect = chrome.identity.getRedirectURL('leadspace');
  const extensionId = chrome.runtime.id;
  const authUrl = `${LEADSPACE_ORIGIN}/login?extension_id=${encodeURIComponent(extensionId)}`;
  const callback = await chrome.identity.launchWebAuthFlow({url: authUrl, interactive: true});
  if (!callback || !callback.startsWith(redirect)) throw new Error('Leadspace authorization was cancelled.');
  const fragment = new URL(callback).hash.slice(1);
  const params = new URLSearchParams(fragment);
  const token = params.get('token');
  const expiresAt = params.get('expires_at');
  if (!token || !expiresAt) throw new Error('Leadspace did not return valid extension credentials.');
  await chrome.storage.local.set({leadspaceToken: token, leadspaceTokenExpiresAt: expiresAt});
  return refreshLeadspaceContext();
}

chrome.runtime.onMessage.addListener((message, sender, respond) => {
  if (sender.id !== chrome.runtime.id || !message || typeof message !== 'object') return false;
  const run = async () => {
    if (message.action === 'leadspaceConnect') return connectLeadspace();
    if (message.action === 'leadspaceDisconnect') {
      await chrome.storage.local.remove(['leadspaceToken', 'leadspaceTokenExpiresAt', 'leadspaceContext', 'leadspaceWorkspaceId', 'leadspaceListId']);
      return {ok: true};
    }
    if (message.action === 'leadspaceContext') return refreshLeadspaceContext();
    if (message.action === 'leadspaceCanExtract') {
      const {workspace} = await selectedWorkspace();
      return {allowed: true, workspace: {id: workspace.id, name: workspace.name, plan: workspace.plan, usage: workspace.usage}};
    }
    if (message.action === 'leadspaceSave' && Array.isArray(message.data)) {
      const {stored, workspace} = await selectedWorkspace();
      const listId = Number(stored.leadspaceListId);
      if (!workspace.lists.some(list => list.id === listId)) throw new Error('Choose a Lead List in the extension before saving.');
      return leadspaceApi(`/extension/workspaces/${workspace.id}/leads`, {method: 'POST', body: JSON.stringify({list_id: listId, leads: message.data})});
    }
    throw new Error('Unknown Leadspace extension action.');
  };
  run().then(data => respond({ok: true, data})).catch(error => respond({ok: false, error: error.message}));
  return true;
});

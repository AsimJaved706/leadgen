(() => {
  const params = new URLSearchParams(location.hash.replace(/^#/, ''));
  const workspaceId = Number(params.get('leadspace_workspace'));
  const jobId = Number(params.get('leadspace_job'));
  if (!workspaceId || !jobId || document.getElementById('leadspace-application-assistant')) return;

  const setField = (selectors, value) => {
    if (!value) return 0;
    for (const selector of selectors) {
      const input = document.querySelector(selector);
      if (!input || input.disabled || input.readOnly || input.value) continue;
      const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input), 'value')?.set;
      setter ? setter.call(input, value) : input.value = value;
      input.dispatchEvent(new Event('input', {bubbles:true}));
      input.dispatchEvent(new Event('change', {bubbles:true}));
      return 1;
    }
    return 0;
  };
  const send = (action, data={}) => chrome.runtime.sendMessage({action, data:{workspaceId,jobId,...data}});
  const panel = document.createElement('aside');
  panel.id = 'leadspace-application-assistant';
  panel.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2147483647;width:320px;padding:16px;background:#fff;color:#102f57;border:1px solid #cbdcf0;border-radius:12px;box-shadow:0 14px 40px rgba(16,47,87,.22);font:14px/1.45 Arial,sans-serif';
  panel.innerHTML = '<strong style="font-size:16px">Leadspace application assistant</strong><p id="ls-apply-message">Loading application details...</p><div style="display:flex;gap:8px"><button id="ls-fill" style="padding:9px 12px;border:0;border-radius:7px;background:#2468e8;color:white;font-weight:700">Fill fields</button><button id="ls-submitted" style="padding:9px 12px;border:1px solid #b8cbe3;border-radius:7px;background:white;color:#174d8c;font-weight:700">Mark submitted</button></div><p style="font-size:11px;color:#607795;margin-bottom:0">Review every answer, upload your resume, and complete CAPTCHA or consent fields before submitting.</p>';
  document.documentElement.appendChild(panel);
  let payload;
  send('leadspaceApplication').then(result => {
    if (!result?.ok) throw new Error(result?.error || 'Could not load this application.');
    payload = result.data;
    panel.querySelector('#ls-apply-message').textContent = `${payload.job.title} at ${payload.job.company_name || 'the employer'}`;
  }).catch(error => panel.querySelector('#ls-apply-message').textContent = error.message);
  panel.querySelector('#ls-fill').addEventListener('click', () => {
    if (!payload) return;
    const name = payload.applicant.name.trim().split(/\s+/); const first=name.shift()||''; const last=name.join(' ');
    let filled=0;
    filled+=setField(['input[autocomplete="given-name"]','input[name*="first" i]','input[id*="first" i]'],first);
    filled+=setField(['input[autocomplete="family-name"]','input[name*="last" i]','input[id*="last" i]'],last);
    filled+=setField(['input[type="email"]','input[name*="email" i]'],payload.applicant.email);
    filled+=setField(['input[type="tel"]','input[name*="phone" i]'],payload.applicant.phone);
    filled+=setField(['input[name*="linkedin" i]','input[id*="linkedin" i]'],payload.applicant.linkedin);
    filled+=setField(['input[name*="portfolio" i]','input[name*="website" i]'],payload.applicant.portfolio);
    filled+=setField(['input[autocomplete="address-level2"]','input[name*="location" i]','input[name*="city" i]'],payload.applicant.location);
    panel.querySelector('#ls-apply-message').textContent = `${filled} fields filled. Review the form before submitting.`;
    send('leadspaceApplicationStatus',{status:'saved'});
  });
  panel.querySelector('#ls-submitted').addEventListener('click', async () => {
    const result=await send('leadspaceApplicationStatus',{status:'applied'});
    panel.querySelector('#ls-apply-message').textContent=result?.ok?'Application marked as applied in Leadspace.':(result?.error||'Could not update Leadspace.');
  });
})();

(() => {
  const text = selector => document.querySelector(selector)?.textContent?.trim() || null;
  const firstText = selectors => selectors.map(text).find(Boolean) || null;
  const clean = value => typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() || null : null;
  const htmlText = value => { if (!value) return null; const node = document.createElement('div'); node.innerHTML = value; return clean(node.textContent); };
  const jobPosting = () => {
    for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
      try { const raw = JSON.parse(script.textContent); const candidates = Array.isArray(raw) ? raw : raw['@graph'] || [raw]; const found = candidates.find(item => item?.['@type'] === 'JobPosting'); if (found) return found; }
      catch (_) {}
    }
    return {};
  };
  const address = location => { const value = Array.isArray(location) ? location[0] : location; const item = value?.address || value; if (typeof item === 'string') return item; return [item?.addressLocality, item?.addressRegion, item?.addressCountry?.name || item?.addressCountry].filter(Boolean).join(', ') || null; };
  const dateValue = value => value && !Number.isNaN(Date.parse(value)) ? new Date(value).toISOString() : null;
  const extract = () => {
    const data = jobPosting(); const url = new URL(location.href); url.search = ''; url.hash = '';
    const id = url.pathname.match(/\/jobs\/view\/(\d+)/)?.[1] || new URLSearchParams(location.search).get('currentJobId');
    const title = clean(data.title) || firstText(['.job-details-jobs-unified-top-card__job-title h1', '.jobs-unified-top-card__job-title', 'h1']);
    const company = clean(data.hiringOrganization?.name) || firstText(['.job-details-jobs-unified-top-card__company-name', '.jobs-unified-top-card__company-name', '.topcard__org-name-link']);
    const locationText = address(data.jobLocation) || firstText(['.job-details-jobs-unified-top-card__primary-description-container', '.jobs-unified-top-card__bullet', '.topcard__flavor--bullet']);
    const description = htmlText(data.description) || firstText(['.jobs-description__content', '.jobs-description-content__text', '#job-details', '.description__text']);
    const pageText = `${title || ''} ${locationText || ''} ${description || ''}`.toLowerCase();
    const workplace = pageText.includes('hybrid') ? 'hybrid' : pageText.includes('remote') ? 'remote' : pageText.includes('on-site') || pageText.includes('onsite') ? 'onsite' : 'unknown';
    const email = description?.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)?.[0] || null;
    return { source_job_id: id, source_url: url.toString(), title, company_name: company, company_website: data.hiringOrganization?.sameAs || data.hiringOrganization?.url || null, location: locationText, country: data.jobLocation?.address?.addressCountry?.name || data.jobLocation?.address?.addressCountry || null, workplace_type: workplace, employment_type: Array.isArray(data.employmentType) ? data.employmentType.join(', ') : clean(data.employmentType), description, contact_email: email, posted_at: dateValue(data.datePosted), expires_at: dateValue(data.validThrough), metadata: {captured_from: 'linkedin_job_page', captured_at: new Date().toISOString()} };
  };
  chrome.runtime.onMessage.addListener((message, sender, respond) => {
    if (message?.action !== 'leadspaceExtractLinkedInJob') return false;
    try { const job = extract(); respond(job.title ? {ok: true, job} : {ok: false, error: 'Open the full LinkedIn job details before saving.'}); }
    catch (error) { respond({ok: false, error: error.message}); }
    return false;
  });
})();

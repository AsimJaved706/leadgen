import argparse, json, math, re
from datetime import datetime, timezone
from pathlib import Path
import requests
from jobspy import scrape_jobs

ROOT = Path(__file__).resolve().parent
ASIAN_COUNTRIES = {
    'afghanistan','armenia','azerbaijan','bahrain','bangladesh','bhutan','brunei','cambodia','china','cyprus',
    'georgia','india','indonesia','iran','iraq','israel','japan','jordan','kazakhstan','kuwait','kyrgyzstan',
    'laos','lebanon','malaysia','maldives','mongolia','myanmar','nepal','north korea','oman','pakistan',
    'palestine','philippines','qatar','saudi arabia','singapore','south korea','sri lanka','syria','taiwan',
    'tajikistan','thailand','timor-leste','turkey','turkmenistan','united arab emirates','uzbekistan','vietnam',
    'yemen','hong kong','macao','macau','uae','u.a.e.','republic of korea','korea','viet nam','burma',
}

def value(row, key, default=None):
    item = row.get(key, default)
    return default if item is None or str(item) in {'nan', 'NaT', '<NA>'} else item

def number(item):
    try: return int(float(item)) if item is not None and math.isfinite(float(item)) else None
    except (TypeError, ValueError): return None

def is_asian(country, location):
    country = re.sub(r'[_-]+', ' ', str(country or '')).strip().lower()
    if country: return country in ASIAN_COUNTRIES
    location = re.sub(r'[_-]+', ' ', str(location or '')).strip().lower()
    if not location or location in {'remote', 'worldwide', 'global', 'anywhere'}: return False
    return any(re.search(r'(^|,|\s)' + re.escape(name) + r'($|,|\s)', location) for name in ASIAN_COUNTRIES)

def normalize(row):
    url = value(row, 'job_url') or value(row, 'job_url_direct')
    match = re.search(r'/jobs/view/(\d+)', str(url or ''))
    city, state, country = value(row, 'city'), value(row, 'state'), value(row, 'country')
    emails = value(row, 'emails', []) or []
    if isinstance(emails, str): emails = [part.strip() for part in emails.split(',') if part.strip()]
    source = str(value(row, 'site', 'Unknown')).replace('_', ' ').title()
    return {'source_platform': source, 'source_job_id': value(row, 'id') or (match.group(1) if match else None),
        'source_url': url, 'title': value(row, 'title', 'Untitled job'), 'company_name': value(row, 'company'),
        'company_website': value(row, 'company_url_direct') or value(row, 'company_url'),
        'location': ', '.join(str(x) for x in (city, state, country) if x) or value(row, 'location'), 'country': country,
        'workplace_type': 'remote' if value(row, 'is_remote') is True else 'unknown',
        'employment_type': value(row, 'job_type'), 'seniority_level': value(row, 'job_level'),
        'salary_min': number(value(row, 'min_amount')), 'salary_max': number(value(row, 'max_amount')),
        'salary_currency': value(row, 'currency'), 'salary_period': value(row, 'interval'),
        'description': value(row, 'description'), 'contact_email': emails[0] if emails else None,
        'posted_at': str(value(row, 'date_posted')) if value(row, 'date_posted') else None,
        'metadata': {'jobspy': True, 'captured_at': datetime.now(timezone.utc).isoformat()}}

def push_to_panel(jobs):
    config = json.loads((ROOT / 'config.json').read_text(encoding='utf-8'))
    totals = {'created': 0, 'updated': 0, 'failed': 0}
    for source in sorted({job['source_platform'] for job in jobs}):
        rows = [job for job in jobs if job['source_platform'] == source]
        response = requests.post(f"{config['api_url'].rstrip('/')}/workspaces/{config['workspace_id']}/jobs/import",
            headers={'Accept': 'application/json', 'Authorization': f"Bearer {config['token']}"},
            json={'source_platform': source, 'jobs': rows}, timeout=120)
        if not response.ok: raise RuntimeError(f'Panel import failed for {source}: HTTP {response.status_code} {response.text[:300]}')
        result = response.json()
        print(f"Panel {source}: {result['created']} created, {result['updated']} updated, {result['failed']} failed")
        for key in totals: totals[key] += int(result.get(key, 0))
    return totals

def main():
    p = argparse.ArgumentParser(); p.add_argument('--query', default='Full Stack Developer'); p.add_argument('--location', default='Remote')
    p.add_argument('--country-indeed', default='USA'); p.add_argument('--results', type=int, default=50); p.add_argument('--hours-old', type=int, default=168)
    p.add_argument('--sites', nargs='+', default=['linkedin', 'indeed']); p.add_argument('--fetch-description', action='store_true'); p.add_argument('--push', action='store_true')
    p.add_argument('--exclude-asia', action='store_true', help='Discard jobs located in Asian countries before saving')
    args = p.parse_args(); all_rows, source_errors, excluded = [], {}, 0
    for site in args.sites:
        try:
            print(f'Fetching up to {args.results} jobs from {site}...')
            frame = scrape_jobs(site_name=[site], search_term=args.query, location=args.location, country_indeed=args.country_indeed,
                results_wanted=max(1, min(args.results, 100)), hours_old=args.hours_old, fetch_description=args.fetch_description, verbose=1)
            rows = json.loads(frame.to_json(orient='records', date_format='iso')); normalized = [normalize(row) for row in rows]
            if args.exclude_asia:
                accepted = [job for job in normalized if not is_asian(job.get('country'), job.get('location'))]
                excluded += len(normalized) - len(accepted); normalized = accepted
            all_rows.extend(normalized); print(f'{site}: {len(normalized)} accepted')
        except Exception as exc:
            source_errors[site] = str(exc); print(f'{site}: failed - {exc}')
    output = ROOT / 'output'; output.mkdir(exist_ok=True); stamp = datetime.now().strftime('%Y%m%d-%H%M%S')
    json_path = output / f'leadspace-jobs-{stamp}.json'; json_path.write_text(json.dumps({'source_platform':'JobSpy','jobs':all_rows}, ensure_ascii=False, indent=2), encoding='utf-8')
    csv_path = output / f'jobs-{stamp}.csv'
    if all_rows:
        import pandas as pd
        pd.DataFrame(all_rows).to_csv(csv_path, index=False, encoding='utf-8-sig')
    panel = push_to_panel(all_rows) if args.push and all_rows else None
    print(json.dumps({'fetched':len(all_rows), 'excluded_asia':excluded, 'source_errors':source_errors, 'panel':panel, 'json':str(json_path), 'csv':str(csv_path)}, indent=2))

if __name__ == '__main__': main()

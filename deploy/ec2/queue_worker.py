import fcntl, json, subprocess, sys
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parent

def api(config, path, payload=None):
    response = requests.post(config['api_url'].rstrip('/') + path, json=payload,
        headers={'Accept':'application/json','Authorization':f"Bearer {config['token']}"}, timeout=120)
    if response.status_code == 204: return None
    response.raise_for_status()
    return response.json()

def last_json(output):
    decoder = json.JSONDecoder()
    for index in range(len(output) - 1, -1, -1):
        if output[index] != '{': continue
        try:
            value, _ = decoder.raw_decode(output[index:])
            if isinstance(value, dict): return value
        except json.JSONDecodeError: pass
    return {'output': output[-2000:]}

def main():
    config = json.loads((ROOT/'config.json').read_text(encoding='utf-8'))
    with (ROOT/'worker.lock').open('w') as lock:
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: return
        request = api(config, '/worker/job-requests/claim')
        if not request: return
        params = request.get('parameters') or {}
        if request['type'] == 'scrape':
            command = [sys.executable, str(ROOT/'worker.py'), '--query', params.get('search_term','Full Stack Developer'),
                '--location', params.get('location','Remote'), '--results', str(params.get('results_per_source',50)),
                '--hours-old', str(params.get('hours_old',24)), '--sites', *params.get('sources',['linkedin','indeed']), '--fetch-description', '--push']
        else:
            command = [sys.executable, str(ROOT/'email_enricher.py'), '--limit', str(params.get('limit',1000)), '--push']
        try:
            run = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=10800)
            if run.returncode: raise RuntimeError((run.stderr or run.stdout)[-8000:])
            api(config, f"/worker/job-requests/{request['id']}/complete", {'status':'completed','result':last_json(run.stdout)})
        except Exception as error:
            api(config, f"/worker/job-requests/{request['id']}/complete", {'status':'failed','error':str(error)[-8000:]})

if __name__ == '__main__': main()

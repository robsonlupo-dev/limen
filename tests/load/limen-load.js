// Operação de QA — carga read-heavy (landing/entrada/catálogo).
// Rodar SEMPRE contra app local, nunca contra staging/produção:
//   k6 run -e BASE_URL=http://localhost:8000 tests/load/limen-load.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    ramp_100:  { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '30s', target: 100 }, { duration: '1m', target: 100 }, { duration: '20s', target: 0 }] },
    ramp_500:  { executor: 'ramping-vus', startVUs: 0, startTime: '2m', stages: [{ duration: '30s', target: 500 }, { duration: '1m', target: 500 }, { duration: '20s', target: 0 }] },
    ramp_1000: { executor: 'ramping-vus', startVUs: 0, startTime: '4m', stages: [{ duration: '30s', target: 1000 }, { duration: '1m', target: 1000 }, { duration: '20s', target: 0 }] },
  },
  thresholds: {
    http_req_duration: ['p(95)<800'],
    http_req_failed: ['rate<0.02'],
  },
};

const pages = ['/', '/entrada', '/catalogo'];

export default function () {
  const path = pages[Math.floor(Math.random() * pages.length)];
  const r = http.get(`${__ENV.BASE_URL}${path}`);
  check(r, { 'status 2xx/3xx': (x) => x.status >= 200 && x.status < 400 });
  sleep(1);
}

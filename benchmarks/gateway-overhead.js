import http from 'k6/http';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const gatewayOverhead = new Trend('gateway_overhead_ms');

export const options = {
  scenarios: {
    warmup: {
      executor: 'constant-vus',
      vus: 5,
      duration: '30s',
      startTime: '0s',
      exec: 'warmup',
    },
    steady: {
      executor: 'constant-vus',
      vus: 20,
      duration: '2m',
      startTime: '30s',
      exec: 'steady',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.001'],
    http_req_duration: ['p(95)<100', 'p(99)<200'],
    gateway_overhead_ms: ['p(95)<100'],
  },
};

const payload = JSON.stringify({
  model: 'claude-sonnet',
  max_tokens: 16,
  messages: [{ role: 'user', content: 'bench' }],
});

const baseUrl = __ENV.GATEWAY_URL || 'http://llm_nginx:80';
const apiKey = __ENV.GATEWAY_API_KEY;
if (!apiKey) {
  throw new Error('GATEWAY_API_KEY env must be set');
}

function send() {
  const res = http.post(`${baseUrl}/v1/messages`, payload, {
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${apiKey}`,
    },
  });
  check(res, { '200': (r) => r.status === 200 });
  gatewayOverhead.add(res.timings.duration);
}

export function warmup() {
  send();
}

export function steady() {
  send();
}

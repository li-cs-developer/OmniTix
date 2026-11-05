// ═══════════════════════════════════════════════════════════
// OmniTix — k6 load test: concurrent seat holds
//
// Target: 50,000 virtual users across a 60-second ramp, hitting the Go
// HTTP gateway's /api/v1/seats/hold endpoint. Each VU holds a unique seat
// (no contention) so we measure raw throughput, not conflict resolution.
//
// Run:
//   k6 run --vus 500 --duration 60s scripts/load/hold-seat.js
//   k6 run scripts/load/hold-seat.js
// ═══════════════════════════════════════════════════════════

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const OCCURRENCE_ID = __ENV.OCCURRENCE_ID || 'loadtest-occ-01';

const holdLatency = new Trend('hold_latency_ms', true);
const conflictCount = new Counter('hold_conflict_total');
const errorCount = new Counter('hold_error_total');

export const options = {
  stages: [
    { duration: '10s', target: 500 },   // warm up
    { duration: '30s', target: 5000 },  // ramp
    { duration: '20s', target: 50000 }, // peak
    { duration: '10s', target: 0 },     // drain
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<100', 'p(99)<250'],
    hold_latency_ms: ['p(95)<100'],
  },
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
  const seatId = `load-${__VU}-${__ITER}`;

  const payload = JSON.stringify({
    occurrence_id: OCCURRENCE_ID,
    seat_id: seatId,
    user_id: `user-${__VU}`,
  });

  const params = {
    headers: { 'Content-Type': 'application/json' },
    timeout: '10s',
    tags: { name: 'HoldSeat' },
  };

  const start = Date.now();
  const res = http.post(`${BASE_URL}/api/v1/seats/hold`, payload, params);
  holdLatency.add(Date.now() - start);

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
  });

  if (!ok) {
    if (res.status === 409) {
      conflictCount.add(1);
    } else {
      errorCount.add(1);
    }
  }

  sleep(0.1);
}

export function setup() {
  const res = http.get(`${BASE_URL}/health`);
  if (res.status !== 200) {
    throw new Error(`service not healthy: ${res.status}`);
  }
  return { startTime: new Date().toISOString() };
}

export function teardown(data) {
  console.log(`Load test ran from ${data.startTime} to ${new Date().toISOString()}`);
}
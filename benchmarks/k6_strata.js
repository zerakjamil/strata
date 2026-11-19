import http from 'k6/http';
import { check } from 'k6';

export const options = {
  vus: 50,
  duration: '10s',
};

export default function () {
  const url = 'http://localhost:8001/api/search?q=mechanical+keyboard&limit=10&index=products';
  const res = http.get(url, {
    headers: { 'Accept': 'application/json' },
  });

  check(res, {
    'status is 200': (r) => r.status === 200,
    'has 10 hits': (r) => {
      try {
        const data = JSON.parse(r.body);
        return data.data && data.data.length === 10;
      } catch (e) {
        return false;
      }
    },
  });
}

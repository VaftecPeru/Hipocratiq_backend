#!/usr/bin/env node
/**
 * Isolated full-stack smoke: real Next.js -> real Laravel with ephemeral
 * PostgreSQL and Redis. Read-only requests; no OTP, clinical data or AWS.
 */
import assert from "node:assert/strict";

const web = "http://127.0.0.1:3000";
const api = "http://127.0.0.1:8000";

async function request(url, options = {}) {
  return fetch(url, { redirect: "manual", signal: AbortSignal.timeout(12000), ...options });
}

async function json(response, expectedStatus, label) {
  assert.equal(response.status, expectedStatus, label + ": unexpected HTTP status");
  assert.match(response.headers.get("content-type") ?? "", /application\/json/i,
    label + ": JSON content type required");
  const parsed = await response.json();
  console.log(label + ": HTTP " + response.status + " PASS");
  return parsed;
}

async function ready(base, label) {
  const payload = await json(await request(base + "/api/health/ready", {
    headers: { Accept: "application/json" },
  }), 200, label);
  assert.equal(payload.success, true);
  assert.equal(payload.data?.status, "ok");
  for (const dependency of ["database", "redis", "storage"]) {
    assert.equal(payload.data?.dependencies?.[dependency], "ok",
      label + ": " + dependency + " must be available");
  }
}

async function denied(base, label) {
  for (const resource of ["/api/v1/auth/me", "/api/v1/fhir/Patient/1"]) {
    const payload = await json(await request(base + resource, {
      headers: { Accept: "application/json" },
    }), 401, label + " " + resource);
    assert.equal(payload.code, "UNAUTHENTICATED",
      "Guest access to clinical data must not succeed");
  }
}

async function cors() {
  const path = api + "/api/v1/auth/otp/request";
  const allowedOrigin = web;
  const untrustedOrigin = "https://unapproved-clinic.invalid";
  const preflight = (origin) => request(path, {
    method: "OPTIONS",
    headers: {
      Origin: origin,
      "Access-Control-Request-Method": "POST",
      "Access-Control-Request-Headers": "Content-Type",
    },
  });
  const allowed = await preflight(allowedOrigin);
  assert.ok(allowed.status >= 200 && allowed.status < 300);
  assert.equal(allowed.headers.get("access-control-allow-origin"), allowedOrigin);
  const rejected = await preflight(untrustedOrigin);
  const actualOrigin = rejected.headers.get("access-control-allow-origin");
  assert.notEqual(actualOrigin, untrustedOrigin, "Untrusted origin must not be reflected");
  assert.notEqual(actualOrigin, "*", "Wildcard CORS is prohibited");
  if (actualOrigin !== null) {
    assert.equal(actualOrigin, allowedOrigin, "Only the approved origin may be sent");
  }
  console.log("Real Laravel CORS allowlist: PASS");
}

async function main() {
  const homepage = await request(web + "/");
  assert.equal(homepage.status, 200, "Real Next.js login page should be available");
  assert.match(homepage.headers.get("content-type") ?? "", /text\/html/i);
  assert.match(await homepage.text(), /hipocratiq/i, "Next.js login must render");
  console.log("Next.js SSR login: PASS");
  await ready(api, "Laravel direct DB/Redis");
  await ready(web + "/backend", "Next.js proxy to Laravel DB/Redis");
  await denied(api, "Laravel direct auth");
  await denied(web + "/backend", "Next.js proxy auth");
  await cors();
  console.log("INTEGRATED_HTTP_GATE=PASS");
  console.log("NEXT + LARAVEL + PostgreSQL17 + Redis8 on synthetic isolated CI.");
  console.log("HTTPS UAT, AWS SMS, 36/36 clinical and signed Android are separate gates.");
}

main().catch((error) => {
  console.error("INTEGRATED_HTTP_GATE=FAIL: " + error.message);
  process.exitCode = 1;
});

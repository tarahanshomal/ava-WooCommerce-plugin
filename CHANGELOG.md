# Changelog

## 1.2.3 — 2026-11-13

- Add an authenticated connection check for the saved key, required SMS scopes and account/service readiness without sending a message or charging credit.
- Explain revoked, expired and security-invalidated keys with steps to replace the saved credential; keep diagnostics isolated to each endpoint/key and never display upstream secrets.
- Preserve atomic per-slot template revisions and empty order method labels from the published 1.2.0 package.
- Preserve all accepted, failed and uncertain message outcomes when a key changes; credential replacement never silently replays old SMS.

## 1.2.2 — 2026-11-12

- Cache admin log count and page results for sixty seconds with generation invalidation after successful queue mutations.
- Preserve live database reads for atomic claims, deduplication, delivery reporting and recovery.
- Document precise coding-standard exceptions for necessary custom-table operations and uninstall cleanup.
- Add cache regression checks for zero results, mutation invalidation, late stale fills, generation eviction and filtered pagination.

## 1.2.1 — 2026-11-11

- Prepare the WordPress.org submission package with the ava-sms-for-woocommerce directory and translation domain.
- Align the public plugin name and clarify installation and external service documentation.

## 1.2.0 — 2026-11-11

- Attach immutable store, template revision, and used-variable snapshots for one-time custom template review on supporting Ava panels.
- Preserve revisions across ordinary order/customer/amount changes; advance them on saved edits, including a change followed by a revert.
- Separate cloned store identities after a canonical site URL change, while preserving old queued snapshots.
- Keep legacy jobs and unsupported template facts on the existing reviewed send path. Package available from the official Ava downloads page.

## 1.1.0 — 2026-11-08

- Show the real Ava wallet's available balance, total balance and reserved credit in integer rials on the settings page, independently of automatic SMS sending.
- Read the authenticated wallet with the existing messaging key; cache each URL/key combination for sixty seconds and offer a permission/nonce-protected refresh and account charge link.
- Show safe local errors when balances cannot be fetched or validated; never show stale credit as current or disclose upstream responses.

## 1.0.1 — 2026-11-08

- Show content review and dispatch waits separately from queued SMS, retaining Ava review and dispatch metadata.
- Persist report attempts and deadlines; continue read-only status polls with backoff capped at one hour and recover missed report actions in bounded housekeeping batches.
- Retain unresolved local records until their outcome is final; keep uncertain sends protected from automatic resending.

## 1.0.0 — 2026-11-04

- Add direct integration with Ava's public SMS API, including idempotency keys and message reports.
- Add configurable customer order-status messages and administrator notifications.
- Add explicit manual order messages and paid test messages.
- Add background sending, duplicate suppression and local logs with configurable retention.
- Add Iranian phone normalization, Persian settings and WooCommerce order CRUD for HPOS.
- Add behavioral tests, deterministic installable ZIP packaging and GitHub release automation.

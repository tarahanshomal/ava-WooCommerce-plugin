# Changelog

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

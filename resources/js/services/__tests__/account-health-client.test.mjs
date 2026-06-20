/**
 * Standalone tests for account-health-client.js.
 * Runnable via:  node --test resources/js/services/__tests__/account-health-client.test.mjs
 * (No bundler/Vue deps — the module under test is pure JS.)
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { computeClientScore, entropyScore, secretByteLength } from '../account-health-client.js'

// GEZDGNBVGY3TQOJQ = 10 bytes (Base32 of "1234567890")
const TEN_BYTE = 'GEZDGNBVGY3TQOJQ'
// 20-byte secret: Base32 of a 20-byte zero buffer
const TWENTY_BYTE = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'

test('entropy: 20-byte secret scores 100', () => {
    assert.equal(entropyScore(TWENTY_BYTE), 100)
})

test('entropy: 12-byte secret scores 50', () => {
    // 12 bytes -> between 10 and 15
    const twelve = 'GEZDGNBVGY3TQOJQGEZDGNBV' // approx; verify via byte length
    const bytes = secretByteLength(twelve)
    assert.ok(bytes >= 10 && bytes <= 15, `expected 10-15 bytes, got ${bytes}`)
    assert.equal(entropyScore(twelve), 50)
})

test('entropy: short secret (<10 bytes) scores 0', () => {
    assert.equal(entropyScore('AB'), 0)
    assert.equal(entropyScore(''), 0)
    assert.equal(entropyScore(null), 0)
})

test('two identical secrets both score duplicate 0', () => {
    const accounts = [
        { id: 1, secret: TEN_BYTE },
        { id: 2, secret: TEN_BYTE },
    ]
    const scores = computeClientScore(accounts)
    assert.equal(scores.get(1).duplicate_score, 0)
    assert.equal(scores.get(2).duplicate_score, 0)
})

test('unique secret scores duplicate 100', () => {
    const accounts = [
        { id: 1, secret: TEN_BYTE },
        { id: 2, secret: TWENTY_BYTE },
    ]
    const scores = computeClientScore(accounts)
    assert.equal(scores.get(1).duplicate_score, 100)
    assert.equal(scores.get(2).duplicate_score, 100)
})

test('client_total is the average of entropy and duplicate', () => {
    const accounts = [{ id: 1, secret: TWENTY_BYTE }] // entropy 100, dup 100
    const scores = computeClientScore(accounts)
    assert.equal(scores.get(1).client_total, 100)
})

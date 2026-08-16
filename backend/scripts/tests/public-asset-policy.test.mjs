import test from 'node:test';
import assert from 'node:assert/strict';
import { forbiddenAssetHashes } from '../frontend-asset-policy.mjs';
import { validateExternalTag, validateFileInventory, validateRepository } from '../verify-public-assets.mjs';

test('current repository satisfies the fail-closed public asset policy', () => {
    assert.deepEqual(validateRepository(), []);
});

test('mutable external package URLs are rejected', () => {
    const errors = validateExternalTag('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>');
    assert.match(errors.join('\n'), /Unpinned or unclassified/);
});

test('static CDN assets without exact SRI are rejected', () => {
    const errors = validateExternalTag('<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>');
    assert.match(errors.join('\n'), /SRI/);
});

test('unclassified public additions are rejected', () => {
    const errors = validateFileInventory([{ path: 'public/rogue.js', sha256: 'abc' }], []);
    assert.match(errors.join('\n'), /Unclassified/);
});

test('restricted font bytes are rejected even when renamed', () => {
    const restricted = [...forbiddenAssetHashes][0];
    const entry = { path: 'public/assets/renamed.bin', sha256: restricted };
    const errors = validateFileInventory([entry], [{ ...entry, classification: 'first_party' }]);
    assert.match(errors.join('\n'), /Restricted or unproven font bytes/);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const { buildContactColumnList, getOptOutColumn } = require('../lib/contact-sync-schema');

test('builds a schema-safe contact column list and opts-out column fallback', () => {
  const columns = ['id', 'firstName', 'lastUpdated', 'doNotEmail'];
  assert.deepEqual(
    buildContactColumnList(columns, ['id', 'firstName', 'lastUpdated', 'emailOptOut', 'doNotEmail', 'missingColumn']),
    ['id', 'firstName', 'lastUpdated', 'doNotEmail']
  );
  assert.equal(getOptOutColumn(['id', 'doNotEmail']), 'doNotEmail');
  assert.equal(getOptOutColumn(['id', 'emailOptOut']), 'emailOptOut');
});

function buildContactColumnList(baseColumns = [], availableColumns = []) {
  const availableSet = new Set(availableColumns.map((c) => c.trim()).filter(Boolean));
  return [...new Set(baseColumns.filter((column) => availableSet.has(column)))];
}

function getOptOutColumn(availableColumns = []) {
  const availableSet = new Set(availableColumns.map((c) => c.trim()).filter(Boolean));
  return availableSet.has('emailOptOut')
    ? 'emailOptOut'
    : availableSet.has('doNotEmail')
      ? 'doNotEmail'
      : null;
}

module.exports = { buildContactColumnList, getOptOutColumn };

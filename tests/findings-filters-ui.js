/* Verify the real findings renderer uses counts for the selected filters. */
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('assets/js/admin.js', 'utf8');
const start = source.indexOf('function renderFindings(data) {');
const end = source.indexOf('\n\tfunction renderRow(', start);
if (start < 0 || end < 0) throw new Error('Findings renderer not found');
const screen = { innerHTML: '', querySelector: () => null };
const render = vm.runInNewContext('(' + source.slice(start, end).trim() + ')', {
  state: { status: 'healthy', type: 'all', scope: 'external', search: '', page: 1, perPage: 20 },
  root: { querySelector: () => screen },
  t: x => x,
  escapeHtml: x => String(x == null ? '' : x),
  translateMarkup: x => x,
  isActiveScan: () => false,
  button: (label, action) => '<button data-action="' + action + '">' + label + '</button>',
  renderRow: item => '<tr data-resource-row="' + item.id + '"></tr>',
  patchFindings: () => { throw new Error('Unexpected patch'); }
});
render({progress: {}, counts: {all: 13, healthy: 10}, list: {total: 1, items: [{id: 1}], filter_counts: {all: 2, healthy: 1}}});
if (!/data-status="healthy">Healthy<span class="sblc-filter-count">1<\/span>/.test(screen.innerHTML)) throw new Error('Healthy count does not match the selected filters');
if (!/data-status="all">All<span class="sblc-filter-count">2<\/span>/.test(screen.innerHTML)) throw new Error('All count does not match the selected filters');
if (!screen.innerHTML.includes('data-action="clear-filters"')) throw new Error('Clear filters control is missing');
console.log('Findings renderer shows filtered counts and clear-filters control.');

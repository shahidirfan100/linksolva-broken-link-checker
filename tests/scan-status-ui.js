/* Exercise the actual status renderer with discovery and verification progress. */
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('assets/js/admin.js', 'utf8');
const start = source.indexOf('function scanStatusMarkup(progress) {');
const end = source.indexOf('\n\tfunction showActiveScan(', start);
if (start < 0 || end < 0) throw new Error('Scan status renderer not found');
const render = vm.runInNewContext('(' + source.slice(start, end).trim() + ')', {
  elapsedLabel: seconds => 'Elapsed: ' + Math.floor((Number(seconds) || 0) / 60) + 'm ' + ((Number(seconds) || 0) % 60) + 's',
  translateMarkup: value => value,
  escapeHtml: value => String(value),
  t: value => value,
  button: () => '<button>Stop</button>'
});
for (const phase of ['discovery', 'verification']) {
  const html = render({ phase, percent: 37, message: '5 of 13 checked', scan_id: 1 });
  if (!html.includes('class="sblc-progress"') || !html.includes('width:37%') || !html.includes('37%') || !html.includes('5 of 13 checked')) {
    throw new Error('Missing progress bar or status during ' + phase);
  }
}
console.log('Scan status renderer displays progress in both phases.');

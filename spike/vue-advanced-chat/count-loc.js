// Counts code lines (no blank lines, no comment-only lines). Usage:
//   node count-loc.js file.js            -> whole file
//   node count-loc.js file.php 2910-2963 -> line range
const fs = require('fs');
function count(text) {
  let n = 0, inBlock = false;
  for (const raw of text.split('\n')) {
    let l = raw.trim();
    if (inBlock) { const i = l.indexOf('*/'); if (i < 0) continue; l = l.slice(i + 2).trim(); inBlock = false; }
    while (l.startsWith('/*')) { const i = l.indexOf('*/'); if (i < 0) { inBlock = true; l = ''; break; } l = l.slice(i + 2).trim(); }
    if (l === '' || l.startsWith('//')) continue;
    n++;
  }
  return n;
}
module.exports = { count };
if (require.main === module) {
  const [file, range] = process.argv.slice(2);
  let lines = fs.readFileSync(file, 'utf8').split('\n');
  if (range) { const [a, b] = range.split('-').map(Number); lines = lines.slice(a - 1, b); }
  console.log(count(lines.join('\n')));
}

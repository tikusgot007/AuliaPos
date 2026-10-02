// Static server for the spike (python3 -m http.server also works). Usage: node serve.js [port]
const http = require('http'), fs = require('fs'), path = require('path');
const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png' };
http.createServer((req, res) => {
  // the current app serves media at /inbox/media/{id} (no extension); map /media/{id} to {id}.png for the baseline page
  const url = decodeURIComponent(req.url.split('?')[0]).replace(/^\/$/, '/index.html').replace(/^\/media\/(\d+)$/, '/media/$1.png');
  const f = path.join(__dirname, url);
  if (!f.startsWith(__dirname) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('not found'); }
  res.writeHead(200, { 'content-type': types[path.extname(f)] || 'application/octet-stream' });
  fs.createReadStream(f).pipe(res);
}).listen(Number(process.argv[2]) || 8765);

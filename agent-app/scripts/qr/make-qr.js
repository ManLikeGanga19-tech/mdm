// node make-qr.js <payload.json> <out.png> : renders the JSON (minified) as a QR code, then decodes it back to verify.
const fs = require('fs');
const QRCode = require('qrcode');
const jsQR = require('jsqr');
const { PNG } = require('pngjs');
const [src, out] = process.argv.slice(2);
const text = JSON.stringify(JSON.parse(fs.readFileSync(src, 'utf8')));
QRCode.toFile(out, text, { width: 900, margin: 4, errorCorrectionLevel: 'M' }).then(() => {
  const png = PNG.sync.read(fs.readFileSync(out));
  const decoded = jsQR(new Uint8ClampedArray(png.data), png.width, png.height);
  console.log(decoded && decoded.data === text ? `OK: QR decodes to the payload (${text.length} chars)` : 'MISMATCH');
});

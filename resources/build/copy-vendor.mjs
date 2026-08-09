/**
 * Bundles the `qrcode` npm package into a single self-contained browser
 * IIFE at assets/js/qrcode.min.js.
 *
 * Why bundle rather than link a CDN: the QR code on the deposit page
 * encodes a bitcoin URI containing the user's fresh deposit address.
 * Rendering it client-side from a local script means that address never
 * leaves the browser. A third-party QR image service (or a CDN-hosted
 * library, which can be swapped upstream at any time) would be handed
 * every deposit address the store issues.
 *
 * The output IS committed - Hostinger cannot run esbuild.
 */

import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const outDir = resolve(root, 'assets/js');

await mkdir(outDir, { recursive: true });

// Entry point: expose just the canvas renderer we use, as window.QRCode.
const entry = resolve(root, 'resources/build/.qr-entry.js');
await writeFile(
  entry,
  `import QRCode from 'qrcode';
   window.QRCode = {
     toCanvas: (canvas, text, opts) => QRCode.toCanvas(canvas, text, opts),
   };
  `,
);

const result = await build({
  entryPoints: [entry],
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  platform: 'browser',
  outfile: resolve(outDir, 'qrcode.min.js'),
  legalComments: 'none',
  metafile: true,
});

const bytes = Object.values(result.metafile.outputs)[0].bytes;
console.log(`  vendor qrcode.min.js (${(bytes / 1024).toFixed(1)} kB)`);

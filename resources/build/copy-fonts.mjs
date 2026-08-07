/**
 * Copies the woff2 files we use out of node_modules into
 * public_html/assets/fonts, under short stable names.
 *
 * The copied files ARE committed. Hostinger has no npm, and the fonts
 * must be self-hosted anyway - a CDN request from the wallet page would
 * leak the referrer to a third party while a deposit address is on
 * screen.
 */

import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const target = resolve(root, 'public_html/assets/fonts');

const files = [
  ['@fontsource/instrument-serif/files/instrument-serif-latin-400-normal.woff2', 'instrument-serif-400.woff2'],
  ['@fontsource/instrument-serif/files/instrument-serif-latin-400-italic.woff2', 'instrument-serif-400-italic.woff2'],
  ['@fontsource/inter/files/inter-latin-400-normal.woff2', 'inter-400.woff2'],
  ['@fontsource/inter/files/inter-latin-500-normal.woff2', 'inter-500.woff2'],
  ['@fontsource/inter/files/inter-latin-600-normal.woff2', 'inter-600.woff2'],
  ['@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-400-normal.woff2', 'plex-mono-400.woff2'],
  ['@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-500-normal.woff2', 'plex-mono-500.woff2'],
];

await mkdir(target, { recursive: true });

for (const [from, to] of files) {
  await copyFile(resolve(root, 'node_modules', from), resolve(target, to));
  console.log(`  fonts  ${to}`);
}

/**
 * Copies the pdf.js worker into public/ with a .js extension.
 *
 * pdf.js ships its worker as .mjs, and loads it through a dynamic import() when
 * it sets up. ES module imports are subject to strict MIME checking, and plenty
 * of servers — nginx's stock mime.types among them — have no mapping for .mjs,
 * so they answer application/octet-stream and the browser refuses to execute it.
 * The document then never renders, with an error that points at the module
 * loader rather than at the web server.
 *
 * Serving the identical bytes as .js sidesteps the whole problem: every server
 * already knows that extension. Copied at build time rather than committed, so
 * the worker can never drift out of sync with the installed pdfjs-dist.
 */

import { copyFileSync, existsSync, mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'

const source = resolve('node_modules/pdfjs-dist/build/pdf.worker.min.mjs')
const destination = resolve('public/pdf.worker.min.js')

if (!existsSync(source)) {
  console.error(`pdf.js worker not found at ${source} — run npm install first.`)
  process.exit(1)
}

mkdirSync(dirname(destination), { recursive: true })
copyFileSync(source, destination)

console.log('pdf.js worker -> public/pdf.worker.min.js')

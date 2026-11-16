import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const require = createRequire(new URL('../frontend/package.json', import.meta.url))
const ts = require('typescript')
const React = require('react')
const { renderToStaticMarkup } = require('react-dom/server')
const source = readFileSync(new URL('../frontend/src/components/TidalLink.tsx', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText
const module = { exports: {} }
new Function('require', 'module', 'exports', compiled)(require, module, module.exports)
const { TidalLink } = module.exports
const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
const release = { title: 'An album', artist: 'An artist', tidalAlbumId: '556197748' }
try {
  for (const [agent, expected] of [
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'tidal://album/556197748'],
    ['Mozilla/5.0 (Linux; Android 15)', 'https://tidal.com/album/556197748'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)', 'https://tidal.com/album/556197748'],
    ['Mozilla/5.0 (Macintosh)', 'https://tidal.com/album/556197748'],
  ]) {
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { userAgent: agent } })
    const html = renderToStaticMarkup(React.createElement(TidalLink, { release }))
    assert.ok(html.includes(`href="${expected}"`))
    assert.ok(html.includes('Listen on TIDAL'))
    assert.ok(!/autoplay|\/play|\/search|target=/.test(html))
  }
  for (const tidalAlbumId of [null, undefined, '', '0', '12?autoplay=true', 'javascript:alert(1)', 'https://tidal.com/album/12']) {
    assert.equal(renderToStaticMarkup(React.createElement(TidalLink, { release: { ...release, tidalAlbumId } })), '')
  }
  console.log('TIDAL link rendering passed: Windows protocol, mobile HTTPS, hidden uncertain links, no autoplay or fallback.')
} finally {
  if (original) Object.defineProperty(globalThis, 'navigator', original)
  else delete globalThis.navigator
}

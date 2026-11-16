// Assembles deploy/ = exactly what gets uploaded to the one.com web root via
// SFTP: the built frontend at the root and the PHP API under api/.
// Secrets (api/config/config.local.php) are never copied - upload them by hand.
import { cpSync, existsSync, rmSync } from 'node:fs'
import { basename, dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const dist = join(root, 'frontend', 'dist')
const api = join(root, 'api')
const out = join(root, 'deploy')

if (!existsSync(join(dist, 'index.html'))) {
  console.error('frontend/dist is missing - run "npm run build" first.')
  process.exit(1)
}

// Local secrets that must never end up in the package (see also sftp-deploy.sh).
// api/var holds local experiment output (personal listening data) - never shipped.
const isSecret = (src) => {
  const name = basename(src)
  const rel = relative(api, src).split(/[\\/]/)
  return name.endsWith('.local.php') || name === '.env' || name.startsWith('.env.') || rel[0] === 'var'
}

rmSync(out, { recursive: true, force: true })
cpSync(dist, out, { recursive: true })
cpSync(api, join(out, 'api'), { recursive: true, filter: (src) => !isSecret(src) })

console.log(`Deploy package ready in ${relative(root, out)}/ - upload its contents to the web root.`)

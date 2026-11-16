import { useId, useState, type ReactNode } from 'react'
import type { Release } from '../data/types.ts'
import { coverUrl } from '../lib/releases.ts'

// The real front cover from the Cover Art Archive when one exists for this
// exact MusicBrainz release group. Until it has loaded - or if there is none -
// generated artwork is shown: deterministic per release, with a grain overlay
// for a slightly lo-fi, print-like feel.

type CoverStyle = 'grain' | 'rings' | 'grid' | 'stripes' | 'blocks' | 'wave'

const STYLES: CoverStyle[] = ['grain', 'rings', 'grid', 'stripes', 'blocks', 'wave']

const PALETTES: [string, string, string][] = [
  ['#0f0f0f', '#b3261e', '#f2f2f2'],
  ['#1a1a1a', '#3b0d0d', '#e8e2d6'],
  ['#050505', '#ff3d00', '#ffd1c2'],
  ['#111111', '#f5c518', '#f4f4f4'],
  ['#0b1c2c', '#4f8fba', '#e6f1ff'],
  ['#0d0d0d', '#8a8a8a', '#d9ff3d'],
  ['#120a1f', '#7b2cbf', '#e0aaff'],
  ['#1c1410', '#d98e4a', '#fff1e0'],
  ['#1b1b1b', '#2f6f4e', '#e9e4d8'],
  ['#0a0a0a', '#3a3a3a', '#ff4d4d'],
  ['#0e0e14', '#3d5afe', '#f0f0ff'],
  ['#14001f', '#ff2e88', '#ffd6e8'],
  ['#0c1a1a', '#3fa7a0', '#d8f3ef'],
]

function hash(str: string): number {
  let h = 2166136261
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

function rand(seed: number) {
  let s = seed || 1
  return () => {
    s ^= s << 13
    s ^= s >>> 17
    s ^= s << 5
    return ((s >>> 0) % 10_000) / 10_000
  }
}

export function Cover({ release }: { release: Release }) {
  const [image, setImage] = useState<'loading' | 'loaded' | 'missing'>('loading')
  const label = `Cover for ${release.title} by ${release.artist}`

  return (
    <span className="cover">
      <GeneratedCover release={release} label={image === 'loaded' ? undefined : label} />
      {image !== 'missing' && (
        <img
          className={`cover__img${image === 'loaded' ? ' is-loaded' : ''}`}
          src={coverUrl(release)}
          alt={label}
          loading="lazy"
          decoding="async"
          referrerPolicy="no-referrer"
          onLoad={() => setImage('loaded')}
          onError={() => setImage('missing')}
        />
      )}
    </span>
  )
}

function GeneratedCover({ release, label }: { release: Release; label?: string }) {
  const uid = useId().replace(/:/g, '')
  const seed = hash(release.id)
  const style = STYLES[seed % STYLES.length]
  const [bg, mid, fg] = PALETTES[(seed >>> 8) % PALETTES.length]
  const r = rand(seed)
  const grad = `g${uid}`
  const grain = `n${uid}`

  let art: ReactNode
  switch (style) {
    case 'grain': {
      const cx = 60 + r() * 80
      const cy = 60 + r() * 80
      art = (
        <>
          <circle cx={cx} cy={cy} r={58 + r() * 20} fill={fg} opacity="0.92" />
          <circle cx={cx + 8} cy={cy + 6} r={30 + r() * 12} fill={bg} />
          <rect x="0" y={150 + r() * 20} width="200" height="3" fill={fg} opacity="0.6" />
        </>
      )
      break
    }
    case 'rings':
      art = Array.from({ length: 7 }, (_, i) => (
        <circle
          key={i}
          cx={100 + (r() - 0.5) * 10}
          cy={100 + (r() - 0.5) * 10}
          r={12 + i * 13}
          fill="none"
          stroke={i % 2 ? fg : mid}
          strokeWidth={i === 3 ? 6 : 1.5}
          opacity={1 - i * 0.1}
        />
      ))
      break
    case 'grid': {
      const horizon = 80 + r() * 30
      art = (
        <>
          {Array.from({ length: 13 }, (_, i) => (
            <line key={`v${i}`} x1={100} y1={horizon} x2={-140 + i * 40} y2={200} stroke={fg} strokeWidth="1" opacity="0.7" />
          ))}
          {Array.from({ length: 7 }, (_, i) => {
            const y = horizon + Math.pow(i / 6, 1.8) * (200 - horizon)
            return <line key={`h${i}`} x1="0" y1={y} x2="200" y2={y} stroke={fg} strokeWidth="1" opacity="0.7" />
          })}
          <rect x="0" y="0" width="200" height={horizon} fill={bg} />
          <circle cx={100} cy={horizon} r={26 + r() * 14} fill={mid} />
        </>
      )
      break
    }
    case 'stripes': {
      const angle = -30 + r() * 60
      art = (
        <g transform={`rotate(${angle} 100 100)`}>
          {Array.from({ length: 9 }, (_, i) => (
            <rect key={i} x={-60} y={-60 + i * 36} width="320" height={i % 3 === 0 ? 16 : 6} fill={i % 3 === 0 ? mid : fg} opacity={i % 3 === 0 ? 1 : 0.5} />
          ))}
        </g>
      )
      break
    }
    case 'blocks':
      art = (
        <>
          <rect x={20 + r() * 20} y={24 + r() * 20} width={90 + r() * 30} height={90 + r() * 40} fill={mid} />
          <rect x={90 + r() * 20} y={90 + r() * 20} width={70} height={70} fill={fg} />
          <rect x={30} y={160} width={60 + r() * 60} height={8} fill={fg} />
        </>
      )
      break
    case 'wave':
      art = Array.from({ length: 9 }, (_, i) => {
        const amp = 8 + r() * 18
        const y = 40 + i * 16
        const phase = r() * 40
        return (
          <path
            key={i}
            d={`M -10 ${y} C ${40 + phase} ${y - amp}, ${80 + phase} ${y + amp}, 120 ${y} S 190 ${y - amp}, 210 ${y}`}
            fill="none"
            stroke={i % 3 === 0 ? fg : mid}
            strokeWidth={i % 3 === 0 ? 2.5 : 1.2}
            opacity={0.5 + (i % 3) * 0.2}
          />
        )
      })
      break
  }

  return (
    <svg
      viewBox="0 0 200 200"
      preserveAspectRatio="xMidYMid slice"
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
    >
      <defs>
        <linearGradient id={grad} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={bg} />
          <stop offset="1" stopColor={style === 'grain' || style === 'blocks' ? bg : mid} stopOpacity={style === 'grid' ? 0 : 0.55} />
        </linearGradient>
        <filter id={grain} x="0" y="0" width="100%" height="100%">
          <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch" />
          <feColorMatrix type="saturate" values="0" />
          <feComponentTransfer>
            <feFuncA type="table" tableValues="0 0.22" />
          </feComponentTransfer>
        </filter>
      </defs>
      <rect width="200" height="200" fill={bg} />
      <rect width="200" height="200" fill={`url(#${grad})`} />
      {art}
      <rect width="200" height="200" filter={`url(#${grain})`} />
    </svg>
  )
}

import { useState } from 'react'
import type { Stars } from '../lib/feedback.ts'
import { StarIcon } from './Icons.tsx'

const STARS: Stars[] = [1, 2, 3, 4, 5]

/** Read-only star display (used on cards). */
export function StarDisplay({ value, size = 14 }: { value: Stars; size?: number }) {
  return (
    <span className="stars stars--display" role="img" aria-label={`Rated ${value} of 5`}>
      {STARS.map((s) => (
        <StarIcon key={s} width={size} height={size} filled={s <= value} />
      ))}
    </span>
  )
}

/** Interactive 1-5 star input. Clicking the current value clears it. */
export function StarInput({ value, onChange }: { value: Stars | null; onChange: (s: Stars) => void }) {
  const [hover, setHover] = useState<Stars | null>(null)
  const shown = hover ?? value ?? 0

  return (
    <div className="stars stars--input" role="radiogroup" aria-label="Your rating" onMouseLeave={() => setHover(null)}>
      {STARS.map((s) => (
        <button
          key={s}
          type="button"
          role="radio"
          aria-checked={value === s}
          aria-label={`${s} ${s === 1 ? 'star' : 'stars'}`}
          className={s <= shown ? 'is-on' : undefined}
          onMouseEnter={() => setHover(s)}
          onFocus={() => setHover(s)}
          onBlur={() => setHover(null)}
          onClick={() => onChange(s)}
        >
          <StarIcon width="26" height="26" filled={s <= shown} />
        </button>
      ))}
    </div>
  )
}

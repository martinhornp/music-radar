import { useState, type ReactNode } from 'react'
import type { Release } from '../data/types.ts'
import { ReleaseCard, type CardVariant } from './ReleaseCard.tsx'

interface Props {
  id: string
  title: string
  subtitle?: string
  icon?: ReactNode
  releases: Release[]
  variant: CardVariant
  layout: 'feature' | 'grid' | 'row'
  /** Visual treatment of the whole section, e.g. 'wildcard'. */
  tone?: 'default' | 'wildcard'
  emptyText: string
  /** Cards rendered at first and per "Load more" (all releases stay reachable). */
  pageSize?: number
  onOpen: (release: Release) => void
}

export function ReleaseSection({ id, title, subtitle, icon, releases, variant, layout, tone = 'default', emptyText, pageSize, onOpen }: Props) {
  const [shown, setShown] = useState(pageSize ?? Infinity)
  const visible = releases.slice(0, shown)
  const remaining = releases.length - visible.length

  return (
    <section className={`section section--${tone}`} aria-labelledby={`${id}-title`}>
      <header className="section__head">
        <h2 id={`${id}-title`} className="section__title">
          {icon}
          {title}
          <span className="section__count">{releases.length}</span>
        </h2>
        {subtitle && <p className="section__sub">{subtitle}</p>}
      </header>

      {releases.length === 0 ? (
        <p className="empty">{emptyText}</p>
      ) : (
        <div className={`releases releases--${layout}`}>
          {visible.map((r) => (
            <ReleaseCard key={r.id} release={r} variant={variant} onOpen={onOpen} />
          ))}
        </div>
      )}

      {remaining > 0 && pageSize && (
        <div className="load-more">
          <p className="load-more__count">
            Showing {visible.length} of {releases.length}
          </p>
          <button type="button" className="btn btn--ghost" onClick={() => setShown((n) => n + pageSize)}>
            Load {Math.min(pageSize, remaining)} more
          </button>
        </div>
      )}
    </section>
  )
}

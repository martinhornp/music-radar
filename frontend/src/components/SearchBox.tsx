import { useId } from 'react'
import { CloseIcon } from './Icons.tsx'

interface Props {
  value: string
  onChange: (value: string) => void
}

/** Search field for artists, albums and EPs. Esc clears it. */
export function SearchBox({ value, onChange }: Props) {
  const id = useId()
  return (
    <div className="search" role="search">
      <label htmlFor={id} className="sr-only">
        Search artists, albums and EPs
      </label>
      <svg className="search__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-3.5-3.5" />
      </svg>
      <input
        id={id}
        className="search__input"
        type="search"
        value={value}
        placeholder="Search artists, albums and EPs"
        autoComplete="off"
        spellCheck={false}
        maxLength={100}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Escape') onChange('')
        }}
      />
      {value !== '' && (
        <button type="button" className="search__clear" onClick={() => onChange('')} aria-label="Clear search">
          <CloseIcon width="16" height="16" />
        </button>
      )}
    </div>
  )
}

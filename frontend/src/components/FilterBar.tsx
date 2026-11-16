import {
  DATE_OPTIONS,
  DEFAULT_FILTERS,
  SUPPORTED_TYPES,
  isFiltered,
  type DateFilter,
  type DateOption,
  type Filters,
  type TypeFilter,
} from '../lib/releases.ts'

interface Props {
  genres: string[]
  value: Filters
  onChange: (f: Filters) => void
  resultCount: number
  /** What "Reset" returns to (the page's starting filters). */
  defaults?: Filters
  /** Release-date choices offered on this page. */
  dateOptions?: DateOption[]
}

export function FilterBar({ genres, value, onChange, resultCount, defaults = DEFAULT_FILTERS, dateOptions = DATE_OPTIONS }: Props) {
  const toggleGenre = (g: string) =>
    onChange({
      ...value,
      genres: value.genres.includes(g) ? value.genres.filter((x) => x !== g) : [...value.genres, g],
    })

  const typeOptions: TypeFilter[] = ['all', ...SUPPORTED_TYPES]

  return (
    <section className="filters" aria-label="Filters">
      <div className="filters__row filters__genres" role="group" aria-label="Genre">
        <button
          type="button"
          className="chip"
          aria-pressed={value.genres.length === 0}
          onClick={() => onChange({ ...value, genres: [] })}
        >
          All genres
        </button>
        {genres.map((g) => (
          <button key={g} type="button" className="chip" aria-pressed={value.genres.includes(g)} onClick={() => toggleGenre(g)}>
            {g}
          </button>
        ))}
      </div>

      <div className="filters__row">
        <div className="segmented" role="group" aria-label="Release type">
          {typeOptions.map((t) => (
            <button key={t} type="button" aria-pressed={value.type === t} onClick={() => onChange({ ...value, type: t })}>
              {t === 'all' ? 'Albums & EPs' : t === 'Album' ? 'Albums' : 'EPs'}
            </button>
          ))}
        </div>

        <label className="select">
          <span className="sr-only">Release date</span>
          <select value={value.date} onChange={(e) => onChange({ ...value, date: e.target.value as DateFilter })}>
            {dateOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </label>

        <p className="filters__count" aria-live="polite">
          {resultCount} {resultCount === 1 ? 'release' : 'releases'}
        </p>

        {isFiltered(value, defaults) && (
          <button type="button" className="link-btn" onClick={() => onChange(defaults)}>
            Reset
          </button>
        )}
      </div>
    </section>
  )
}

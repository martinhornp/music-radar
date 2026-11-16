const regionNames = new Intl.DisplayNames('en', { type: 'region' })

/** "DK" -> "Denmark"; falls back to the code itself. */
export function countryName(code: string): string {
  try {
    return regionNames.of(code) ?? code
  } catch {
    return code
  }
}

const dateFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })

export function formatDate(iso: string): string {
  return dateFormat.format(new Date(`${iso}T12:00:00`))
}

/** Whole days between an ISO date and today (0 = today). */
export function daysSince(iso: string): number {
  const today = new Date()
  today.setHours(12, 0, 0, 0)
  return Math.round((today.getTime() - new Date(`${iso}T12:00:00`).getTime()) / 86_400_000)
}


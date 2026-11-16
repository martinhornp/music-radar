import { useFeedback } from '../lib/feedback.ts'
import { CloseIcon } from './Icons.tsx'

/** Tells you when feedback could not be loaded or saved - never fails silently. */
export function FeedbackNotice() {
  const feedback = useFeedback()
  const message =
    feedback.error ??
    (feedback.status === 'error' ? "Your saved ratings couldn't be loaded right now, so changes may not be saved." : null)
  if (!message) return null

  return (
    <div className="notice" role="alert">
      <p>{message}</p>
      {feedback.error && (
        <button type="button" className="notice__close" onClick={feedback.dismissError} aria-label="Dismiss">
          <CloseIcon width="16" height="16" />
        </button>
      )}
    </div>
  )
}

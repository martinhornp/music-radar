// Single entry point for release data: the recommendations exported from the
// discovery pipeline, served by our PHP API. UI components never fetch directly.
import { apiGet, apiPost } from '../api.ts'
import type { FeedbackChange } from '../lib/feedback.ts'
import type { Feed, FeedbackItem, SearchResponse, TopAlbumsResponse } from './types.ts'

export function loadFeed(signal?: AbortSignal): Promise<Feed> {
  return apiGet<Feed>('/recommendations', signal)
}

/** Searches everything the radar has stored (not only the recommendations). */
export function searchReleases(query: string, signal?: AbortSignal): Promise<SearchResponse> {
  return apiGet<SearchResponse>(`/search?q=${encodeURIComponent(query)}`, signal)
}

/** Your most played albums (the one-time import), for "Bedøm albums". */
export function loadTopAlbums(signal?: AbortSignal): Promise<TopAlbumsResponse> {
  return apiGet<TopAlbumsResponse>('/top-albums', signal)
}

export async function loadFeedback(signal?: AbortSignal): Promise<FeedbackItem[]> {
  return (await apiGet<{ items: FeedbackItem[] }>('/feedback', signal)).items
}

/** Stores one change; the server answers with the release's stored feedback. */
export function saveFeedback(releaseId: string, change: FeedbackChange): Promise<FeedbackItem> {
  return apiPost<FeedbackItem>(`/feedback/${releaseId}`, change)
}

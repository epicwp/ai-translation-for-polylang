import { fetchCases, fetchMyCases, fetchParticipatingCases } from './api.js';
import { filterRoadmapCases } from './topics.js';

/**
 * Derive per-tab totals from already-fetched case arrays.
 * @param {{cases:Array, participating:Array, started:Array}} data
 * @returns {{all:number, participating:number, started:number, roadmap:number}}
 */
export function computeTabCounts({ cases, participating, started }) {
  const publicCases = cases || [];
  return {
    all: publicCases.length,
    participating: (participating || []).length,
    started: (started || []).length,
    roadmap: filterRoadmapCases(publicCases).length,
  };
}

/**
 * Fetch lightweight totals for each Community tab badge.
 * @returns {Promise<{all:number|null, participating:number|null, started:number|null, roadmap:number|null}>}
 */
export async function fetchTabCounts() {
  const [casesRes, participatingRes, startedRes] = await Promise.all([
    fetchCases({ limit: 100 }),
    fetchParticipatingCases(),
    fetchMyCases(),
  ]);

  if (casesRes.error || participatingRes.error || startedRes.error) {
    return {
      all: casesRes.error ? null : computeTabCounts({
        cases: casesRes.cases,
        participating: [],
        started: [],
      }).all,
      participating: participatingRes.error ? null : (participatingRes.cases || []).length,
      started: startedRes.error ? null : (startedRes.cases || []).length,
      roadmap: casesRes.error ? null : computeTabCounts({
        cases: casesRes.cases,
        participating: [],
        started: [],
      }).roadmap,
    };
  }

  return computeTabCounts({
    cases: casesRes.cases,
    participating: participatingRes.cases,
    started: startedRes.cases,
  });
}
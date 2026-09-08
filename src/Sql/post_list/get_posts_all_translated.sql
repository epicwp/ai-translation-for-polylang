-- Get post IDs that have all target languages translated (completed).
-- Completed state lives in pllat_translation_index, NOT pllat_claims: claims are
-- deleted on success, so a claims query returns empty in normal operation.
-- Count distinct non-outdated translated target langs per source post.
SELECT source_id
FROM {index_table}
WHERE source_kind = 'post'
  AND content_subtype = %s
  AND target_id IS NOT NULL
  AND outdated_at IS NULL
GROUP BY source_id
HAVING COUNT(DISTINCT target_lang) >= %d

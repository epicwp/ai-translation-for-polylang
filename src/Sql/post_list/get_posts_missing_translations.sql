-- Get post IDs missing translations for any target language.
-- Done state lives in pllat_translation_index, NOT pllat_claims: claims are
-- deleted on success, so a claims LEFT JOIN always reports zero completed and
-- (wrongly) returns every post. A post is "missing" when its count of
-- non-outdated translated target langs is below the target language count.
SELECT p.ID AS source_id
FROM {posts_table} p
LEFT JOIN (
    SELECT source_id, COUNT(DISTINCT target_lang) AS done_count
    FROM {index_table}
    WHERE source_kind = 'post'
      AND content_subtype = %s
      AND target_id IS NOT NULL
      AND outdated_at IS NULL
    GROUP BY source_id
) done ON p.ID = done.source_id
WHERE p.post_type = %s
  AND p.post_status IN ({post_statuses_placeholders})
  AND (done.done_count IS NULL OR done.done_count < %d)

-- Get post IDs that have any failed translation claims.
-- Only considers the latest claim per post+lang pair.
SELECT DISTINCT latest.source_id
FROM (
    SELECT source_id, target_lang, MAX(id) AS max_id
    FROM {jobs_table}
    WHERE source_kind = 'post'
      AND content_subtype = %s
    GROUP BY source_id, target_lang
) latest
INNER JOIN {jobs_table} j ON j.id = latest.max_id
WHERE j.status = 'failed'

-- Get latest claim status per post and target language for multiple posts.
-- Uses subquery to find the most recent claim for each post+lang pair.
SELECT j.id, j.source_id, j.target_lang, j.status
FROM {jobs_table} j
INNER JOIN (
    SELECT source_id, target_lang, MAX(id) AS max_id
    FROM {jobs_table}
    WHERE source_kind = 'post'
      AND source_id IN ({post_id_placeholders})
    GROUP BY source_id, target_lang
) latest ON j.id = latest.max_id
ORDER BY j.source_id, j.target_lang

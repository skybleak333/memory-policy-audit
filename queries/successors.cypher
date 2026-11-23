MATCH (revision:Revision)
WHERE revision.effective_on <= $date
OPTIONAL MATCH (revision)<-[:SUPERSEDES*1..]-(newer:Revision)
WHERE newer.effective_on <= $date
RETURN revision.id AS id, collect(DISTINCT newer.id) AS successors

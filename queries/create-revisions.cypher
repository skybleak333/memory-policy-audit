UNWIND $revisions AS item
WITH item
WHERE $known_on IS NULL OR item.published_on <= $known_on
CREATE (:Revision {id: item.id, effective_on: item.effective_on})

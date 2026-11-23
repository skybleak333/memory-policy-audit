UNWIND $revisions AS item
CREATE (:Revision {id: item.id, effective_on: item.effective_on})

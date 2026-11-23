UNWIND $revisions AS item
UNWIND item.supersedes AS predecessorId
MATCH (newer:Revision {id: item.id}), (older:Revision {id: predecessorId})
CREATE (newer)-[:SUPERSEDES]->(older)

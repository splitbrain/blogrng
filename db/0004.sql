CREATE TABLE sources
(
    sourceid    TEXT    NOT NULL PRIMARY KEY,
    sourceurl   TEXT    NOT NULL,
    added       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE seen
(
    seen    TEXT    NOT NULL PRIMARY KEY
);

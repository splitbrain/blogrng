CREATE TABLE feeds
(
    feedid    TEXT    NOT NULL PRIMARY KEY,
    url       TEXT    NOT NULL,
    title     TEXT    NOT NULL,
    homepage  TEXT    NOT NULL DEFAULT '',
    added     INTEGER NOT NULL DEFAULT 0,
    fetched   INTEGER NOT NULL DEFAULT 0,
    errors    INTEGER NOT NULL DEFAULT 0,
    lasterror TEXT    NOT NULL DEFAULT ''
);

CREATE TABLE items
(
    itemid    INTEGER NOT NULL PRIMARY KEY,
    feedid    TEXT    NOT NULL,
    url       TEXT    NOT NULL UNIQUE,
    title     TEXT    NOT NULL,
    published INTEGER NOT NULL,
    FOREIGN KEY (feedid) REFERENCES feeds (feedid)
);


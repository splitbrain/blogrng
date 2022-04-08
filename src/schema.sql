CREATE TABLE feeds
(
    feedid    TEXT NOT NULL PRIMARY KEY,
    url       TEXT NOT NULL,
    title     TEXT NOT NULL,
    homepage  TEXT NOT NULL DEFAULT '',
    added     INT  NOT NULL DEFAULT 0,
    fetched   INT  NOT NULL DEFAULT 0,
    errors    INT  NOT NULL DEFAULT 0,
    lasterror TEXT NOT NULL DEFAULT ''
);

CREATE TABLE items
(
    url PRIMARY KEY,
    title     TEXT NOT NULL,
    feedid    TEXT NOT NULL,
    published INT  NOT NULL,
    FOREIGN KEY (feedid) REFERENCES feeds (feedid)
);


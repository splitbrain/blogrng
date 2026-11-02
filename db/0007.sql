CREATE TABLE blocklist
(
    domain TEXT NOT NULL PRIMARY KEY
);

INSERT INTO blocklist (domain) VALUES ('blogspot.com'), ('paper.wf'), ('ilands.app');

<?php

namespace splitbrain\blogrng;

class Wrapped {


    protected DataBase $db;

    public function __construct()
    {
        $fm = new FeedManager();
        $this->db = $fm->db();
    }


    public function all($year): array
    {
        return [
            'allFeeds' => $this->allFeeds(),
            'newFeeds' => $this->newFeeds($year),
            'newPosts' => $this->newPosts($year),
            'feedsWithNoPosts' => $this->feedsWithNoPosts($year),
            'lastYearFeedsWithNoPosts' => $this->lastYearFeedsWithNoPosts($year),
            'thisYearFeedsWithErrors' => $this->thisYearsFeedsWithErrors($year),
            'overallFeedsWithErrors' => $this->overallFeedsWithErrors()
        ];
    }

    public function allFeeds(): int
    {
        $sql = "SELECT COUNT(*) as cnt 
                  FROM feeds
                 WHERE errors = 0";
        return (int) $this->db->queryValue($sql);
    }

    public function newFeeds(int $year): int
    {
        $sql = "SELECT COUNT(*) as cnt
                  FROM feeds
                 WHERE strftime('%Y', datetime(added, 'unixepoch')) = ?
                   AND errors = 0";
        return (int) $this->db->queryValue($sql, [$year]);
    }

    public function newPosts(int $year): int
    {
        $sql = "SELECT COUNT(*) as cnt 
                 FROM items
                WHERE strftime('%Y', datetime(published, 'unixepoch')) = ?";
        return (int) $this->db->queryValue($sql, [$year]);
    }

    public function feedsWithNoPosts(int $year): int
    {
        $sql = "SELECT COUNT(f.feedid)
                  FROM feeds f LEFT JOIN items i 
                    ON f.feedid = i.feedid
                   AND strftime('%Y', datetime(i.published, 'unixepoch')) = ?
                 WHERE i.itemid IS NULL
                  AND errors = 0";
        return $this->db->queryValue($sql, [$year]);
    }

    public function lastYearFeedsWithNoPosts(int $year): int
    {
        $sql = "SELECT COUNT(f.feedid)
                  FROM feeds f LEFT JOIN items i
                    ON f.feedid = i.feedid
                   AND strftime('%Y', datetime(i.published, 'unixepoch')) = ?
                 WHERE i.itemid IS NULL
                   AND strftime('%Y', datetime(f.added, 'unixepoch')) = ?
                   AND errors = 0";
        return (int) $this->db->queryValue($sql, [$year, $year - 1]);
    }

    public function thisYearsFeedsWithErrors(int $year): int
    {
        $sql = "SELECT COUNT(feedid)
                  FROM feeds 
                 WHERE errors > 0
                   AND strftime('%Y', datetime(added, 'unixepoch')) = ?";
        return (int) $this->db->queryValue($sql, [$year]);
    }

    public function overallFeedsWithErrors(): int
    {
        $sql = "SELECT COUNT(*)
                  FROM feeds 
                 WHERE errors > 0";
        return (int) $this->db->queryValue($sql);
    }

}

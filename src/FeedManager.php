<?php

namespace splitbrain\blogrng;

use Exception;
use PDOException;
use SimplePie;

/**
 * Access and manage the feed database
 */
class FeedManager
{
    /** @var DataBase */
    protected $db;

    const MAXAGE = 60 * 60 * 24 * 30 * 6; // last six months

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new DataBase(
            __DIR__ . '/../blogrng.sqlite',
            __DIR__ . '/../db/'
        );
    }

    /**
     * Get a random entry, that is not part of the given exclude list
     *
     * @param int[] $seenPostIDs
     * @return array
     */
    public function getRandom($seenPostIDs = [])
    {
        $seenPostIDs = array_map('intval', $seenPostIDs);
        $seenPostIDs = join(',', $seenPostIDs);

        $mindate = time() - self::MAXAGE;

        $sql = "
            SELECT *
             FROM items I, feeds F
            WHERE I.feedid = F.feedid
              AND F.errors = 0
              AND I.itemid NOT IN ($seenPostIDs)
              AND I.published > $mindate
         ORDER BY random()
            LIMIT 1
             ";

        $result = $this->db->queryAll($sql);
        return $result[0];
    }

    /**
     * getInfo about the last seen pages, based on passed IDs
     *
     * @param int[] $seenIDs
     * @return array|false
     */
    public function getLastSeen($seenPostIDs)
    {
        $seenPostIDs = array_map('intval', $seenPostIDs);
        $seen = join(',', $seenPostIDs);

        $sql = "
           SELECT * 
             FROM items I, feeds F
            WHERE I.feedid = F.feedid
              AND I.itemid IN ($seen)
        ";

        $result = $this->db->queryAll($sql);
        $result = array_column($result, null, 'itemid');

        // sort by the given order
        $data = [];
        foreach ($seenPostIDs as $id) {
            if (isset($result[$id])) $data[] = $result[$id];
        }

        return $data;
    }

    /**
     * Get info about the database
     *
     * @return array
     */
    public function getStats()
    {
        $stats = [];

        $sql = "SELECT COUNT(*) FROM feeds WHERE errors = 0";
        $stats['feeds'] = $this->db->queryValue($sql);


        $sql = "SELECT COUNT(*) FROM items A, feeds B
            WHERE A.feedid = B.feedid AND B.errors = 0";
        $stats['items'] = $this->db->queryValue($sql);

        $mindate = time() - self::MAXAGE;
        $sql = "SELECT COUNT(*) FROM items A, feeds B
            WHERE A.feedid = B.feedid AND B.errors = 0 AND A.published > $mindate";
        $stats['recentitems'] = $this->db->queryValue($sql);

        return $stats;
    }

    /**
     * Get a single feed record
     *
     * @param string $feedid
     * @return array|false
     */
    public function getFeed($feedid)
    {
        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->queryAll($sql, [$feedid]);
        if ($result) $result = $result[0];
        return $result;
    }

    /**
     * @param $itemid
     * @return array|false
     */
    public function getItem($itemid)
    {
        $sql = "SELECT * FROM items I, feeds F WHERE I.feedid = F.feedid AND I.itemid = ?";
        $result = $this->db->queryAll($sql, [$itemid]);
        if ($result) $result = $result[0];
        return $result;
    }

    /**
     * Adds a new feed
     *
     * @param string $url Either the feed itself or a website (using autodiscovery)
     * @return array The feed information
     * @throws Exception|PDOException
     */
    public function addFeed($url)
    {
        $simplePie = new SimplePie();
        $simplePie->cache = false;
        $simplePie->set_feed_url($url);
        if (!$simplePie->init()) {
            throw new Exception($simplePie->error());
        }

        $url = $simplePie->feed_url;
        $fid = $this->feedID($url);
        $feed = [
            'feedid' => $fid,
            'feedurl' => $url,
            'homepage' => $simplePie->get_permalink(),
            'feedtitle' => $simplePie->get_title(),
            'added' => time(),

        ];

        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->queryAll($sql, [$fid]);
        if ($result) {
            throw new Exception("[$fid] Feed already exists");
        }

        $this->db->saveRecord('feeds', $feed);
        return $feed;
    }

    /**
     * Get all feeds that should be updated again
     *
     * @return array
     */
    public function getAllUpdatableFeeds()
    {
        $limit = time() - 60 * 60 * 24;
        $query = "SELECT * FROM feeds WHERE fetched < $limit AND errors < 5 ORDER BY random()";
        return $this->db->queryAll($query);
    }

    /**
     * Get all feeds
     *
     * @return array
     */
    public function getAllFeeds()
    {
        $query = "SELECT * FROM feeds WHERE errors = 0 ORDER BY feedurl";
        return $this->db->queryAll($query);
    }

    /**
     * Fetch items of the given Feed Record
     *
     * @throws Exception|PDOException
     */
    public function fetchFeedItems($feed)
    {
        $simplePie = new SimplePie();
        $simplePie->cache = false;
        $simplePie->set_feed_url($feed['feedurl']);
        $simplePie->force_feed(true); // no autodetect here

        try {
            if (!$simplePie->init()) {
                throw new Exception($simplePie->error());
            }

            $items = $simplePie->get_items();
            if (!$items) throw new Exception('no items found');

            $this->db->pdo()->beginTransaction();
            foreach ($items as $item) {
                $itemUrl = $item->get_permalink();
                if (!$itemUrl) continue;
                $itemTitle = $item->get_title();
                if (!$itemTitle) continue;
                $itemDate = $item->get_gmdate('U');
                if (!$itemDate) $itemDate = time();

                $record = [
                    'feedid' => $feed['feedid'],
                    'itemurl' => $itemUrl,
                    'itemtitle' => $itemTitle,
                    'published' => $itemDate,
                ];

                $this->db->saveRecord('items', $record, false);
            }
            $this->db->pdo()->commit();

            // reset any errors
            $feed['errors'] = 0;
            $feed['lasterror'] = '';
            $feed['fetched'] = time();
            $this->db->saveRecord('feeds', $feed);
        } catch (Exception $e) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }

            // save the error
            $feed['errors']++;
            $feed['lasterror'] = $e->getMessage();
            $feed['fetched'] = time();
            $this->db->saveRecord('feeds', $feed);

            throw $e;
        }

        return count($items);
    }

    /**
     * Delete a feed and all its items
     *
     * @param $feedID
     * @return void
     * @throws Exception
     */
    public function deleteFeed($feedID)
    {
        $feed = $this->getFeed($feedID);
        if (!$feed) throw new \Exception('Feed does not exist');

        $this->db->pdo()->exec('PRAGMA foreign_keys = ON');
        $sql = "DELETE FROM feeds WHERE feedid = ?";
        $this->db->queryAll($sql, [$feedID]);
        $this->db->pdo()->exec('PRAGMA foreign_keys = OFF');
    }

    /**
     * Create a ID for the given feed
     *
     * @param string $url
     * @return string
     */
    protected function feedID($url)
    {
        $url = trim($url);
        $url = preg_replace('!^https?://!', '', $url);
        $url = strtolower($url);
        return md5($url);
    }

}

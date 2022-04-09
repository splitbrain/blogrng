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

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new DataBase(__DIR__ . '/../blogrng.sqlite');
    }

    /**
     * Get a random entry, that is not part of the given exclude list
     *
     * @param int[] $exclude
     * @return array
     */
    public function getRandom($exclude = [])
    {
        $exclude = array_map('intval', $exclude);
        $exclude = join(',', $exclude);

        $mindate = time() - 60 * 60 * 24 * 30 * 6; // last six months

        $sql = "
            SELECT A.ROWID, A.*
             FROM items A, feeds B
            WHERE A.feedid = B.feedid
              AND B.errors = 0
              AND A.ROWID NOT IN ($exclude)
              AND A.published > $mindate
         ORDER BY random()
            LIMIT 1
             ";

        $result = $this->db->query($sql);
        return $result[0];
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
            'url' => $url,
            'homepage' => $simplePie->get_permalink(),
            'title' => $simplePie->get_title(),
            'added' => time(),

        ];

        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->query($sql, [$fid]);
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
        return $this->db->query($query);
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
        $simplePie->set_feed_url($feed['url']);
        $simplePie->force_feed(true); // no autodetect here
        if (!$simplePie->init()) {
            throw new Exception($simplePie->error());
        }

        $items = $simplePie->get_items();
        if (!$items) return 0;

        $this->db->query('BEGIN');
        try {
            foreach ($items as $item) {
                $itemUrl = $item->get_permalink();
                if (!$itemUrl) continue;
                $itemTitle = $item->get_title();
                if (!$itemTitle) continue;
                $itemDate = $item->get_gmdate('U');
                if (!$itemDate) $itemDate = time();


                $this->db->saveRecord('items', [
                    'feedid' => $feed['feedid'],
                    'url' => $itemUrl,
                    'title' => $itemTitle,
                    'published' => $itemDate,
                ]);
            }
            $this->db->query('COMMIT');

            // reset any errors
            $feed['errors'] = 0;
            $feed['lasterror'] = '';
            $feed['fetched'] = time();
            $this->db->saveRecord('feeds', $feed);
        } catch (PDOException $e) {
            $this->db->query('ROLLBACK');

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

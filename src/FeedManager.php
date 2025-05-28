<?php

namespace splitbrain\blogrng;

use Exception;
use PDOException;
use SimplePie\SimplePie;
use Wa72\Url\Url;

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
            __DIR__ . '/../data/blogrng.sqlite',
            __DIR__ . '/../db/'
        );
    }

    /**
     * Access to the database
     *
     * @return DataBase
     */
    public function db()
    {
        return $this->db;
    }

    /**
     * Get a single random post
     *
     * This tries to be fair by giving each feed the same chance to be picked, regardless of
     * post frequency
     *
     * @param int $seenPostIDs
     * @return array
     */
    public function getRandom($seenPostIDs = [])
    {
        $seenPostIDs = array_map('intval', $seenPostIDs);
        $seenPostIDs = join(',', $seenPostIDs);
        $mindate = time() - self::MAXAGE;

        // select a single distinct feed that has recent, unseen posts first
        $sql = "
            SELECT DISTINCT F.feedid
             FROM items I, feeds F
            WHERE I.feedid = F.feedid
              AND I.itemid NOT IN ($seenPostIDs)
              AND I.published > $mindate
         ORDER BY random()
            LIMIT 1
             ";
        $feedId = $this->db->queryValue($sql);

        $sql = "
            SELECT *
             FROM items I, feeds F
            WHERE I.feedid = F.feedid
              AND I.itemid NOT IN ($seenPostIDs)
              AND I.published > $mindate
              AND F.feedid = :feedid
         ORDER BY random()
            LIMIT 1
        ";

        $result = $this->db->queryRecord($sql, [':feedid' => $feedId]);
        // if we did not get results, try again without excluding posts
        if (empty($result)) return $this->getRandom([]);

        return $result;
    }

    /**
     * Get random entries, that is not part of the given exclude list
     *
     * @param int[] $seenPostIDs
     * @param int $limit how many posts
     * @return string[][]
     */
    public function getRandoms($seenPostIDs = [], $limit = 1)
    {
        $posts = [];
        for ($i = 0; $i < $limit; $i++) {
            $post = $this->getRandom($seenPostIDs);
            $seenPostIDs[] = $post['itemid'];
            $posts[] = $post;
        }

        return $posts;
    }

    /**
     * getInfo about the last seen pages, based on passed IDs
     *
     * @param int[] $seenPostIDs
     * @return array
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

        $sql = "SELECT COUNT(*) FROM feeds WHERE errors = 0 AND mastodon != ''";
        $stats['mastodon'] = $this->db->queryValue($sql);


        $sql = "SELECT COUNT(*) FROM items A, feeds B
            WHERE A.feedid = B.feedid AND B.errors = 0";
        $stats['items'] = $this->db->queryValue($sql);

        $mindate = time() - self::MAXAGE;
        $sql = "SELECT COUNT(*) FROM items A, feeds B
            WHERE A.feedid = B.feedid AND B.errors = 0 AND A.published > $mindate";
        $stats['recentitems'] = $this->db->queryValue($sql);

        $sql = 'SELECT page_count * page_size as size FROM pragma_page_count(), pragma_page_size()';
        $stats['size'] = $this->db->queryValue($sql);

        $sql = 'SELECT COUNT(*) FROM suggestions';
        $stats['suggestions'] = $this->db->queryValue($sql);

        $sql = "SELECT COUNT(*) as cnt,
                       STRFTIME('%Y-%W', published, 'unixepoch') as week,
                       STRFTIME('%W', published, 'unixepoch') as w
                  FROM items
                 WHERE published > $mindate
                   AND DATE(published, 'unixepoch') < DATE('now')
              GROUP BY week
              ORDER BY week";
        $stats['weeklyposts'] = array_column($this->db->queryAll($sql), 'cnt', 'w');

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
     * Get the newest items for a feed
     *
     * @param string $feedid
     * @param int $max
     * @return array|false
     */
    public function getFeedItems($feedid, $max = 10)
    {
        $sql = "SELECT * FROM items WHERE feedid = ? ORDER BY published DESC LIMIT ?";
        $result = $this->db->queryAll($sql, [$feedid, $max]);
        return $result;
    }

    /**
     * Suggest a new feed
     *
     * @param string $url
     * @return array
     * @throws Exception
     */
    public function suggestFeed($url)
    {
        $feed = $this->getFeedData($url);
        $fid = $feed['feedid'];

        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->queryRecord($sql, [$fid]);
        if ($result) {
            throw new Exception("This feed already exists in the database");
        }

        $sql = "SELECT * FROM suggestions WHERE feedid = ?";
        $result = $this->db->queryRecord($sql, [$fid]);
        if ($result) {
            throw new Exception("This feed has already been suggested");
        }

        $this->db->saveRecord('suggestions', $feed);
        return $feed;
    }

    /**
     * Get all the suggestions
     *
     * @return array
     */
    public function getSuggestions()
    {
        $sql = "SELECT * FROM suggestions ORDER BY added";
        return $this->db->queryAll($sql);
    }

    /**
     * Delete a feed from the suggestions
     *
     * @param string $feedid
     * @return void
     */
    public function removeSuggestion($feedid)
    {
        $sql = "DELETE FROM suggestions WHERE feedid = ?";
        $this->db->exec($sql, [$feedid]);
    }

    /**
     * Try to parse the gien feed or homepage and return the feed details
     *
     * @param string $url
     * @return array
     * @throws Exception
     */
    protected function getFeedData($url, $mastodon = false)
    {
        $simplePie = new SimplePie();
        $simplePie->enable_cache(false);
        $simplePie->set_feed_url($url);

        try {
            $ok = $simplePie->init();
        } catch (\Throwable $e) {
            throw new Exception("Sorry I couldn't find a supported feed at that URL. " . $e->getMessage());
        }

        if (!$ok) {
            throw new Exception($simplePie->error());
        }

        $url = $simplePie->feed_url;
        $fid = $this->feedID($url);

        $homepage = trim($simplePie->get_permalink());
        if (!$homepage) {
            $hp = new Url($url);
            $hp = $hp->withPath($hp->getDirname());
            $homepage = $hp->__toString();
        }

        $title = trim($simplePie->get_title());
        if (!$title) {
            $title = parse_url($homepage, PHP_URL_HOST);
        }

        $feed = [
            'feedid' => $fid,
            'feedurl' => $url,
            'homepage' => $homepage,
            'feedtitle' => $title,
            'added' => time(),
        ];

        if ($mastodon) {
            $feed['mastodon'] = (new Mastodon())->getProfile($homepage);
        }

        return $feed;
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
        $feed = $this->getFeedData($url, true);
        $fid = $feed['feedid'];

        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->queryRecord($sql, [$fid]);
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
        $day = 60 * 60 * 24;
        $limit = time() - $day;
        // each error pushes the next retry back by a day
        $query = "SELECT * FROM feeds WHERE fetched < ($limit - ( errors * $day )) ORDER BY random()";
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
     * Get all feeds with their most recent post
     *
     * Important! You need to close the cursor when done with the statement
     *
     * @param bool $witherrors Include feeds that have errors?
     * @return \PDOStatement
     */
    public function getAllFeedsWithDetails($witherrors = false)
    {
        $errorlimit = $witherrors ? 100 : 0;

        $query = "SELECT A.feedid AS feedid, *
                    FROM feeds A
              LEFT JOIN (SELECT feedid,
                                 itemid,
                                 itemurl,
                                 itemtitle,
                                 MAX(published) AS published
                            FROM items
                        GROUP BY feedid
                         ) B 
                      ON A.feedid = B.feedid
                   WHERE errors <= $errorlimit
                ORDER BY A.feedurl";

        $stmt = $this->db->pdo()->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch items of the given Feed Record
     *
     * @throws Exception|PDOException
     */
    public function fetchFeedItems($feed)
    {
        $simplePie = new SimplePie();
        $simplePie->enable_cache(false);
        $simplePie->set_feed_url($feed['feedurl']);
        $simplePie->force_feed(true); // no autodetect here

        $domain = parse_url($feed['feedurl'], PHP_URL_HOST);

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

                // only keep items from the same domain, ignore external links
                if (parse_url($itemUrl, PHP_URL_HOST) != $domain) continue;

                $itemTitle = $item->get_title();
                if (!$itemTitle) continue;
                $itemDate = $item->get_gmdate('U');
                if (!$itemDate) $itemDate = time();
                if ($itemDate > time()) $itemDate = time();

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
        if (!$feed) throw new Exception('Feed does not exist');

        $this->db->pdo()->exec('PRAGMA foreign_keys = ON');
        $sql = "DELETE FROM feeds WHERE feedid = ?";
        $this->db->queryAll($sql, [$feedID]);
        $this->db->pdo()->exec('PRAGMA foreign_keys = OFF');
    }

    /**
     * Reset the error counter for the given feed
     *
     * @param $feedID
     * @return void
     * @throws Exception
     */
    public function resetFeedErrors($feedID)
    {
        $feed = $this->getFeed($feedID);
        if (!$feed) throw new Exception('Feed does not exist');

        $feed['errors'] = 1;
        $feed['lasterror'] = 'to be retried';
        $this->db->saveRecord('feeds', $feed);
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

    /**
     * Add a RSS feed source for automatic suggestions
     *
     * @param string $url
     * @return array
     * @throws Exception
     */
    public function addSource($url)
    {
        $simplePie = new SimplePie();
        $simplePie->enable_cache(false);
        $simplePie->set_feed_url($url);
        if (!$simplePie->init()) {
            $error = $simplePie->error();
            if ($simplePie->status_code() == 200 && str_contains($error, 'text/plain')) {
                $type = 'list';
            } elseif ($simplePie->status_code() == 200 && str_starts_with($simplePie->get_raw_data(), '<opml')) {
                $type = 'opml';
            } else {
                throw new Exception($error);
            }
        } else {
            $url = $simplePie->feed_url;
            $type = 'feed';
        }


        $sid = $this->feedID($url);
        $source = [
            'sourceid' => $sid,
            'sourceurl' => $url,
            'added' => time(),
            'type' => $type,
        ];

        $sql = "SELECT * FROM sources WHERE sourceid = ?";
        $result = $this->db->queryRecord($sql, [$sid]);
        if ($result) {
            throw new Exception("[$sid] Source already exists");
        }

        $this->db->saveRecord('sources', $source);
        return $source;
    }

    /**
     * Get all sources
     *
     * @return array
     */
    public function getSources()
    {
        $sql = "SELECT * FROM sources ORDER BY added DESC";
        return $this->db->queryAll($sql);
    }

    /**
     * Fetch a single list source and add new suggestions
     *
     * @param array $source A source record
     * @return int number of added suggestions
     * @throws Exception
     */
    public function fetchSourceList($source)
    {
        $lines = file_get_contents($source['sourceurl']);
        if (!$lines) throw new Exception('Could not fetch source list');

        $lines = explode("\n", $lines);
        $new = 0;
        foreach ($lines as $itemUrl) {
            $itemUrl = trim($itemUrl);
            if (empty($itemUrl)) continue;

            try {
                // check if we've seen this item already
                $this->rememberSourceSuggestion($itemUrl);
                // add the suggestion
                $this->suggestFeed($itemUrl);
                $new++;
                echo '✓';
            } catch (Exception $e) {
                // ignore
                echo '✗';
            }
        }
        return $new;
    }

    /**
     * Fetch a single OPML source and add new suggestions
     *
     * @param array $source A source record
     * @return int number of added suggestions
     * @throws Exception
     */
    public function fetchSourceOpml($source)
    {
        $lines = file_get_contents($source['sourceurl']);
        if (!$lines) throw new Exception('Could not fetch source list');

        $xml = simplexml_load_string($lines);
        if (!$xml) throw new Exception('Could not parse OPML');

        $new = 0;
        foreach ($xml->body->outline as $item) {
            $itemUrl = (string)$item['xmlUrl'];
            if (empty($itemUrl)) continue;

            try {
                // check if we've seen this item already
                $this->rememberSourceSuggestion($itemUrl);
                // add the suggestion
                $this->suggestFeed($itemUrl);
                $new++;
                echo '✓';
            } catch (Exception $e) {
                // ignore
                echo '✗';
            }
        }
        return $new;
    }

    /**
     * Fetch a single source and add new suggestions
     *
     * @param array $source A source record
     * @return int number of added suggestions
     * @throws Exception
     */
    public function fetchSource($source)
    {
        if ($source['type'] == 'feed') {
            return $this->fetchSourceFeed($source);
        } elseif ($source['type'] == 'list') {
            return $this->fetchSourceList($source);
        } elseif ($source['type'] == 'opml') {
            return $this->fetchSourceOpml($source);
        } else {
            throw new Exception('Unknown source type');
        }
    }

    /**
     * Fetch a single feed source and add new suggestions
     *
     * @param array $source A source record
     * @return int number of added suggestions
     * @throws Exception
     */
    public function fetchSourceFeed($source)
    {
        $simplePie = new SimplePie();
        $simplePie->enable_cache(false);
        $simplePie->set_feed_url($source['sourceurl']);
        $simplePie->force_feed(true); // no autodetect here

        if (!$simplePie->init()) {
            throw new Exception($simplePie->error());
        }

        $items = $simplePie->get_items();
        if (!$items) throw new Exception('no items found');

        $new = 0;
        foreach ($items as $item) {
            $itemUrl = $item->get_permalink();
            try {
                // check if we've seen this item already
                $this->rememberSourceSuggestion($itemUrl);
                // add the suggestion
                $this->suggestFeed($itemUrl);
                $new++;
                echo '✓';
            } catch (Exception $e) {
                // ignore
                echo '✗';
            }
        }
        return $new;
    }

    /**
     * Remember that this URL has been suggested in the past
     *
     * @param string $itemUrl
     * @throws Exception if the URL has been suggested before
     */
    protected function rememberSourceSuggestion($itemUrl)
    {
        $hash = $this->feedID($itemUrl);

        $sql = "SELECT seen FROM seen WHERE seen = ?";
        $seen = $this->db->queryValue($sql, [$hash]);
        if ($seen) throw new Exception('Already suggested');

        // remember the item to not suggest it again
        // we also remember it when it fails to add in the next step to not retry fails
        $sql = "INSERT INTO seen (seen) VALUES (?)";
        $this->db->queryValue($sql, [$hash]);
    }
}

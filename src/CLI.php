<?php

namespace splitbrain\blogrng;


use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;

class CLI extends PSR3CLI
{


    /** @var DataBase */
    protected $db;

    protected function setup(Options $options)
    {
        $options->setHelp('Manage the feeds');

        $options->registerCommand('add', 'Adds a feed');
        $options->registerArgument('feedurl', 'The URL to the RSS/Atom feed', true, 'add');

        $options->registerCommand('update', 'Get the newest items for all feeds');
    }

    protected function main(Options $options)
    {
        $this->db = new DataBase(__DIR__ . '/../blogrng.sqlite', $this);


        $args = $options->getArgs();
        $cmd = $options->getCmd();
        switch ($cmd) {
            case 'add';
                return $this->addFeed($args[0]);
            case 'update';
                return $this->updateFeeds();
            default:
                echo $options->help();
                return 0;
        }
    }

    /**
     * Adds a new feed
     *
     * @param string $url Either the feed itself or a website (using autodiscovery)
     * @return int
     */
    protected function addFeed($url)
    {
        $feed = new \SimplePie();
        $feed->cache = false;
        $feed->set_feed_url($url);
        if (!$feed->init()) {
            $this->error($feed->error());
            return 1;
        }

        $title = $feed->get_title();
        $url = $feed->feed_url;
        $status = $feed->status_code();
        $fid = $this->feedID($url);

        $sql = "SELECT * FROM feeds WHERE feedid = ?";
        $result = $this->db->query($sql, [$fid]);
        if ($result) {
            $this->error('Feed already exists as [{fid}]', ['fid' => $fid]);
            return 1;
        }

        $this->db->saveRecord('feeds', [
            'feedid' => $fid,
            'url' => $url,
            'homepage' => $feed->get_permalink(),
            'title' => $title,
            'added' => time(),
        ]);

        $this->success("[$fid] $title $url");
        return 0;
    }

    /**
     * Update all the feeds
     *
     * @return int
     */
    protected function updateFeeds()
    {
        $limit = time() - 60 * 60 * 24;
        $query = "SELECT * FROM feeds WHERE fetched < $limit AND errors < 5";
        $result = $this->db->query($query);

        foreach ($result as $feed) {
            try {
                $this->fetchFeed($feed['url'], $feed['feedid']);
                $feed['errors'] = 0;
                $feed['lasterror'] = '';
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $feed['errors']++;
                $feed['lasterror'] = $e->getMessage();
            }
            $feed['fetched'] = time();
            $this->db->saveRecord('feeds', $feed);
        }

        return 0;
    }

    /**
     * @param string $url
     * @param string $fid
     * @throws \Exception
     */
    protected function fetchFeed($url, $fid)
    {
        $feed = new \SimplePie();
        $feed->cache = false;
        $feed->set_feed_url($url);
        $feed->force_feed(true); // no autodetect here
        if (!$feed->init()) {
            throw new \Exception($feed->error());
        }

        $items = $feed->get_items();
        if (!$items) return $feed->status_code();

        $this->success("{count} items in [{fid}] {url}", [
            'count' => count($items),
            'fid' => $fid,
            'url' => $url,
        ]);

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
                    'feedid' => $fid,
                    'url' => $itemUrl,
                    'title' => $itemTitle,
                    'published' => $itemDate,
                ]);
            }
            $this->db->query('COMMIT');
        } catch (\PDOException $e) {
            $this->db->query('ROLLBACK');
        }
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

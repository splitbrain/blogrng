<?php

namespace splitbrain\blogrng;

use Goose\Client as GooseClient;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RSS
{


    /** @var Environment */
    protected $twig;
    /** @var FeedManager */
    protected $feedManager;

    /** @var bool force refresh */
    protected $force = false;

    /** @var array allowed feed */
    protected $feeds = [
        1 => [1, 3, 5, 10, 20, 25],
        7 => [5, 10, 15, 25],
    ];


    /**
     * Constructor
     */
    public function __construct()
    {
        $loader = new FilesystemLoader(__DIR__ . '/../templates');
        $this->twig = new Environment($loader);
        $this->feedManager = new FeedManager();
    }

    /**
     * Force a refresh of the feed
     */
    public function forceRefresh()
    {
        $this->force = true;
    }

    /**
     * Returns the wanted feed content
     *
     * @param int $freq wanted frequency in days
     * @param int $num wanted number of posts
     * @return false|string
     */
    public function getFeed($freq = 1, $num = 5)
    {
        // ensure only valid values are used
        if ($freq < 1) $freq = 1;
        if ($num < 1) $num = 1;
        if (!isset($this->feeds[$freq])) $freq = 1;
        while (!in_array($num, $this->feeds[$freq])) {
            $num--;
        }

        $cache = $this->getCacheName($freq, $num);
        if (!file_exists($cache)) {
            // this should not happen, but if it does, create the feed
            $this->createFeed($freq, $num);
        }
        return file_get_contents($cache);
    }

    /**
     * Where a feed file is cached
     *
     * @param int $freq wanted frequency in days
     * @param int $num wanted number of posts
     * @return string
     */
    protected function getCacheName($freq, $num)
    {
        return __DIR__ . '/../data/rss/' . $freq . '.' . $num . '.xml';
    }

    /**
     * Create a feed file
     *
     * @param int $freq wanted frequency in days
     * @param int $num wanted number of posts
     * @return int|string Name of the created file or time in seconds until the next update
     */
    public function createFeed($freq = 1, $num = 5)
    {
        $cache = $this->getCacheName($freq, $num);

        $now = time();
        $valid = @filemtime($cache) - ($now - $freq * 60 * 60 * 24);


        if ($valid < 0 || $this->force) {
            $creator = new \UniversalFeedCreator();
            $creator->title = 'indieblog.page daily random posts';
            $creator->description = 'Discover the IndieWeb, one blog post at a time.';
            $creator->link = 'https://indieblog.page';

            $result = $this->feedManager->getRandoms([], $num + 10); // get more than we need, to compensate for errors
            $added = 0;
            foreach ($result as $data) {
                try {
                    $data = $this->fetchAdditionalData($data);
                } catch (\Exception $e) {
                    continue;
                }
                $data['itemurl'] = Controller::campaignURL($data['itemurl'], 'rss');
                $data['feedurl'] = Controller::campaignURL($data['feedurl'], 'rss');

                $item = new \FeedItem();
                $item->title = '🎲 ' . $data['itemtitle'];
                $item->link = $data['itemurl'];
                $item->date = $now--; // separate each post by a second, first one being the newest
                $item->source = $data['feedurl'];
                $item->author = $data['feedtitle'];
                $item->description = $this->twig->render('partials/rssitem.twig', ['item' => $data]);
                $creator->addItem($item);

                if (++$added >= $num) break;
            }
            $creator->saveFeed('RSS2.0', $cache, false);
            return $cache;
        }
        return $valid;
    }

    /**
     * Create all feeds
     *
     * @param LoggerInterface $logger
     */
    public function createAllFeeds(LoggerInterface $logger){
        foreach ($this->feeds as $freq => $nums){
            foreach ($nums as $num){
                $logger->info('Creating feed for '.$freq.' days and '.$num.' posts');
                $ret = $this->createFeed($freq, $num);
                if(is_int($ret)){
                    $logger->info('Feed still valid for ' . $ret . ' seconds');
                } else {
                    $logger->success('Feed created: {feed}', ['feed' => $ret]);
                }
            }
        }
    }

    /**
     * Enhance the given item with data fetched from the web
     *
     * @param string[] $item
     * @return string[]
     * @throws \Exception
     */
    protected function fetchAdditionalData($item)
    {
        $goose = new GooseClient();
        $article = $goose->extractContent($item['itemurl']);

        $text = $article->getCleanedArticleText();
        $desc = $article->getMetaDescription();
        if (strlen($text) > strlen($desc)) {
            $summary = $text;
        } else {
            $summary = $desc;
        }
        if (mb_strlen($summary) > 500) {
            $summary = mb_substr($summary, 0, 500) . '…';
        }
        $item['summary'] = $summary;

        return $item;
    }
}

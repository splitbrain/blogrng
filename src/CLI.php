<?php

namespace splitbrain\blogrng;


use Exception;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;

class CLI extends PSR3CLI
{
    /** @var FeedManager */
    protected $feedManager;

    /** @inheritdoc */
    protected function setup(Options $options)
    {
        $options->setHelp('Manage the feeds');

        $options->registerCommand('add', 'Adds a feed');
        $options->registerArgument('feedurl', 'The URL to the RSS/Atom feed', true, 'add');

        $options->registerCommand('update', 'Get the newest items for all feeds');
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        $this->feedManager = new FeedManager();


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
     * Add a new feed
     *
     * @param string $feedurl
     * @return int
     */
    protected function addFeed($feedurl)
    {
        try {
            $feed = $this->feedManager->addFeed($feedurl);
            $this->success('[{fid}] {title}', $feed);
            return 0;
        } catch (Exception $e) {
            $this->error($e->getMessage());
            $this->debug($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Update all the feeds
     *
     * @return int
     */
    protected function updateFeeds()
    {
        $feeds = $this->feedManager->getAllUpdatableFeeds();
        foreach ($feeds as $feed) {
            try {
                $count = $this->feedManager->fetchFeedItems($feed);
                $this->success('[{feedid}] {count} items found', ['feedid' => $feed['feedid'], 'count' => $count]);
            } catch (Exception $e) {
                $this->error('[{feedid}] {msg}', ['feedid' => $feed['feedid'], 'msg' => $e->getMessage()]);
                $this->debug($e->getTraceAsString());
            }
        }
        return 1;
    }

}

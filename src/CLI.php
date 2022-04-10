<?php

namespace splitbrain\blogrng;


use Exception;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\TableFormatter;

class CLI extends PSR3CLI
{
    /** @var FeedManager */
    protected $feedManager;

    /** @inheritdoc */
    protected function setup(Options $options)
    {
        $options->useCompactHelp(true);
        $options->setHelp('Manage the feeds');

        $options->registerCommand('add', 'Adds a feed');
        $options->registerArgument('feedurl', 'The URL to the RSS/Atom feed', true, 'add');

        $options->registerCommand('update', 'Get the newest items for all feeds');

        $options->registerCommand('inspect', 'Inspect the given feed or item');
        $options->registerArgument('id', 'Feed or item id', true, 'inspect');

        $options->registerCommand('delete', 'Delete the given feed');
        $options->registerArgument('id', 'Feed id', true, 'delete');

        $options->registerCommand('adminpass', 'Set the password for the web admin user');
        $options->registerArgument('pass', 'The password to set', true, 'adminpass');
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        $this->feedManager = new FeedManager();


        $args = $options->getArgs();
        $cmd = $options->getCmd();
        switch ($cmd) {
            case 'add':
                return $this->addFeed($args[0]);
            case 'update':
                return $this->updateFeeds();
            case 'inspect':
                return $this->inspect($args[0]);
            case 'delete':
                return $this->delete($args[0]);
            case 'adminpass':
                $this->feedManager->db()->setOpt('adminpass', password_hash($args[0], PASSWORD_DEFAULT));
                return 0;
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
            $this->success('[{feedid}] {feedtitle}', $feed);
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

    /**
     * Inspect the given post or feed
     *
     * @param string|int $id
     * @return int
     */
    protected function inspect($id)
    {

        if (strlen($id) === 32) {
            $data = $this->feedManager->getFeed($id);
        } else {
            $data = $this->feedManager->getItem($id);
        }

        if (!$data) {
            $this->error('Could not find any data for given ID');
            return 1;
        }

        $td = new TableFormatter($this->colors);
        foreach ($data as $key => $val) {
            echo $td->format([15, '*'], [$key, $val]);
        }
        return 0;
    }

    /**
     * Delete the feed
     *
     * @param string $id
     * @return int
     */
    protected function delete($id)
    {

        try {
            $this->feedManager->deleteFeed($id);
            $this->success('Feed deleted');
            return 0;
        } catch (Exception $e) {
            $this->error($e->getMessage());
            $this->debug($e->getTraceAsString());
            return 1;
        }

    }
}

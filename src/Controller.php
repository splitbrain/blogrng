<?php

namespace splitbrain\blogrng;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A simple view controller
 */
class Controller
{

    /** @var Environment */
    protected $twig;
    /** @var CookieManager */
    protected $cookieManager;
    /** @var FeedManager */
    protected $feedManager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $loader = new FilesystemLoader(__DIR__ . '/../templates');
        $this->twig = new Environment($loader);

        $this->feedManager = new FeedManager();
        $this->cookieManager = new CookieManager();

    }

    /**
     * Views are methods in this class
     *
     * @param string $view
     * @return void
     */
    public function __invoke($view = '')
    {
        if ($view === '') $view = 'index';

        if (is_callable([$this, $view])) {
            $this->$view();
        } else {
            $this->notFound();
        }
    }

    public function notFound()
    {
        http_response_code(404);
        echo $this->twig->render('404.twig');
    }

    public function index()
    {
        $context = [
            'seen' => $this->feedManager->getLastSeen($this->cookieManager->getSeenPostIDs()),
        ];

        echo $this->twig->render('index.twig', $context);
    }

    public function faq()
    {
        $context = [
            'stats' => $this->feedManager->getStats()
        ];

        echo $this->twig->render('faq.twig', $context);
    }

    public function suggest()
    {
        $context = [];

        if (isset($_POST['suggest']) &&
            preg_match('/^https?:\/\//', $_POST['suggest']) &&
            empty($_POST['title'])) {

            try {
                $context['feed'] = $this->feedManager->suggestFeed($_POST['suggest']);
            } catch (\Exception $e) {
                $context['error'] = $e->getMessage();
            }
        }

        echo $this->twig->render('suggest.twig', $context);
    }

    public function random()
    {
        $post = $this->feedManager->getRandom($this->cookieManager->getSeenPostIDs());
        $this->cookieManager->addSeenPostID($post['itemid']);
        header('Location: ' . $post['itemurl']);
    }

    public function seen()
    {
        $seen = $this->feedManager->getLastSeen($this->cookieManager->getSeenPostIDs());
        echo $this->twig->render('partials/seen.twig', ['seen' => $seen]);
    }

    public function export()
    {
        $all = $this->feedManager->getAllFeeds();
        header('Content-Type: application/json');
        echo json_encode($all);
    }
}

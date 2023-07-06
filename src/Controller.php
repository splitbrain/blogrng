<?php

namespace splitbrain\blogrng;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Wa72\Url\Url;

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
        $this->twig->addGlobal('cachebuster', max(
            filemtime(__DIR__ . '/../public/custom.css'),
            filemtime(__DIR__ . '/../public/script.js')
        ));

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

    /**
     * Ensure only superuser may continue
     */
    protected function requireAuth()
    {
        if (
            !isset($_SERVER['PHP_AUTH_PW']) ||
            !password_verify($_SERVER['PHP_AUTH_PW'], $this->feedManager->db()->getOpt('adminpass', ''))
        ) {
            header('WWW-Authenticate: Basic realm="My Realm"');
            header('HTTP/1.0 401 Unauthorized');
            echo $this->twig->render('401.twig');
            exit;
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

    public function all()
    {
        echo $this->twig->render('all.twig');
    }

    public function suggest()
    {
        $context = [];

        // Android share often posts the URL in the text field for some reason
        if (!isset($_POST['suggest']) && isset($_POST['text']) &&
            preg_match('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#i', $_POST['text'], $m)) {
            $_POST['suggest'] = $m[0];
        }

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
        $post = $this->feedManager->getRandoms($this->cookieManager->getSeenPostIDs())[0];
        $this->cookieManager->addSeenPostID($post['itemid']);
        header('Location: ' . self::campaignURL($post['itemurl']));
    }

    public function seen()
    {
        $seen = $this->feedManager->getLastSeen($this->cookieManager->getSeenPostIDs());
        echo $this->twig->render('partials/seen.twig', ['seen' => $seen]);
    }

    public function export()
    {
        $stmt = $this->feedManager->getAllFeedsWithDetails(true);

        header('Content-Type: application/json');
        echo "[\n";
        $firstRowDone = false;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($firstRowDone) echo ",\n";
            echo json_encode($row, JSON_PRETTY_PRINT);
            $firstRowDone = true;
        }
        echo "]\n";
        $stmt->closeCursor();
    }

    public function admin()
    {
        $this->requireAuth();
        $context = [];

        if (isset($_REQUEST['add'])) {
            try {
                $context['feed'] = $this->feedManager->addFeed($_REQUEST['add']);
                $this->feedManager->removeSuggestion($context['feed']['feedid']);
            } catch (\Exception $e) {
                $context['error'] = $e->getMessage();
            }
        }

        if (isset($_REQUEST['remove'])) {
            $this->feedManager->removeSuggestion($_REQUEST['remove']);
        }

        if (isset($_REQUEST['delete'])) {
            try {
                $this->feedManager->deleteFeed($_REQUEST['delete']);
            } catch (\Exception $e) {
                $context['error'] = $e->getMessage();
            }
        }

        $context['suggestions'] = $this->feedManager->getSuggestions();

        echo $this->twig->render('admin.twig', $context);
    }

    public function inspect()
    {
        $this->requireAuth();
        $context = [];

        if(isset($_REQUEST['id'])){
            $id = substr($_REQUEST['id'], 0, 32);

            $context['feed'] = $this->feedManager->getFeed($id);
            $context['items'] = $this->feedManager->getFeedItems($id);
        }

        echo $this->twig->render('inspect.twig', $context);
    }

    public function rss()
    {
        echo $this->twig->render('rss.twig');
    }

    public function dailyfeed()
    {
        $num = 1;
        if (isset($_REQUEST['num'])) $num = (int)$_REQUEST['num'];
        $rss = new RSS();
        header('Content-Type: application/rss+xml');
        echo $rss->getFeed(1, $num);
    }

    public function weeklyfeed()
    {
        $num = 5;
        if (isset($_REQUEST['num'])) $num = (int)$_REQUEST['num'];
        $rss = new RSS();
        header('Content-Type: application/rss+xml');
        echo $rss->getFeed(7, $num);
    }

    /**
     * Add campaign info
     *
     * @param string $url
     * @param string $type
     * @return string
     */
    public static function campaignURL($url, $type = 'random')
    {
        $url = new Url($url);
        $url->setQueryParameter('utm_source', 'indieblog.page');
        $url->setQueryParameter('utm_medium', $type);
        $url->setQueryParameter('utm_campaign', 'indieblog.page');
        return $url->write();
    }
}
